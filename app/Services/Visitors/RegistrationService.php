<?php

declare(strict_types=1);

namespace App\Services\Visitors;

use App\Core\Database;
use App\Enums\AccountStatus;
use App\Enums\CustomerType;
use App\Enums\HolderType;
use App\Enums\KycStatus;
use App\Enums\TokenPurpose;
use App\Models\Account;
use App\Models\Customer;
use App\Services\AuditLog;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordTokenService;
use App\Services\Kyc\IdValidator;
use App\Services\Notify\Mailer;

/**
 * Visitor accounts (spec 4.1): self-registration, set-password / reset / invite links, activation.
 *
 * Responses never reveal whether an email is registered: register(), resendSetLink() and forgot()
 * always "succeed" from the caller's point of view and send the right email (or none).
 */
final class RegistrationService
{
    public function __construct(
        private readonly Database $db,
        private readonly PasswordTokenService $tokens,
        private readonly PasswordHasher $hasher,
        private readonly Mailer $mailer,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Create an unverified account + customer shell and email the set-password link.
     * An existing email gets a "you already have an account" email instead (no enumeration).
     *
     * @param array{name: string, email: string, mobile: string, type: string} $data
     * @return int|null new account id (null when the email already existed)
     */
    public function register(array $data): ?int
    {
        $email = mb_strtolower(trim($data['email']));
        $existing = Account::findByEmail($email);
        if ($existing !== null) {
            if ($existing['status'] === AccountStatus::Pending->value) {
                $this->sendSetLink($existing, TokenPurpose::Set);
            } else {
                $this->mailer->send($email, 'You already have a Commune account', 'account-exists', [
                    'loginUrl' => absolute_url('portal.login'),
                    'resetUrl' => absolute_url('portal.password.forgot'),
                ]);
            }
            return null;
        }

        $type = CustomerType::from($data['type']);
        $name = ProfileService::squash($data['name']);
        $mobile = IdValidator::normalizeMobile($data['mobile']);

        $accountId = $this->db->transaction(function (Database $db) use ($email, $type, $name, $mobile): int {
            $now = date('Y-m-d H:i:s');
            $accountId = $db->insert('accounts', ['email' => $email, 'status' => AccountStatus::Pending->value, 'terms_accepted_at' => $now]);
            // A walk-in registered at the desk (no portal account yet) can claim their profile by email.
            $walkIn = $db->first('SELECT id FROM customers WHERE email = ? AND account_id IS NULL ORDER BY id LIMIT 1 FOR UPDATE', [$email]);
            if ($walkIn !== null) {
                $db->update('customers', ['account_id' => $accountId], ['id' => $walkIn['id']]);
                $customerId = (int) $walkIn['id'];
            } else {
                $customerId = $db->insert('customers', [
                    'centre_id' => self::centreId($db),
                    'account_id' => $accountId,
                    'type' => $type->value,
                    'name' => $name,
                    'email' => $email,
                    'mobile' => $mobile,
                    'kyc_status' => KycStatus::NotSubmitted->value,
                    'registered_via' => 'online',
                ]);
            }
            $this->audit->record('account.register', 'account', $accountId, null, ['customer_id' => $customerId, 'type' => $type->value], actorType: 'account', actorId: $accountId);
            return $accountId;
        });

        $this->sendSetLink((array) Account::find($accountId), TokenPurpose::Set, $name);
        return $accountId;
    }

    /**
     * Email a fresh single-use link. Set = verify email + choose password; Reset = forgot password;
     * Invite = staff-created account ("Send portal invite").
     *
     * @param array<string, mixed> $account
     */
    public function sendSetLink(array $account, TokenPurpose $purpose, ?string $name = null): bool
    {
        $token = $this->tokens->issue(HolderType::Account, (int) $account['id'], $purpose);
        $customer = Customer::findByAccount((int) $account['id']);
        $name ??= (string) ($customer['name'] ?? '');
        $url = absolute_url($purpose === TokenPurpose::Reset ? 'portal.password.reset' : 'portal.password.set', ['token' => $token]);
        [$subject, $template] = match ($purpose) {
            TokenPurpose::Set => ['Set your password to activate your Commune account', 'set-password'],
            TokenPurpose::Reset => ['Reset your Commune password', 'reset-password'],
            TokenPurpose::Invite => ['You are invited to the Commune visitor portal', 'invite'],
        };
        return $this->mailer->send([(string) $account['email'], $name], $subject, $template, [
            'name' => $name,
            'url' => $url,
            'minutes' => $this->tokens->lifetimeMinutes(),
            'uniqueId' => $customer['unique_id'] ?? null,
        ]);
    }

    /** "Resend the link" for accounts that never set a password. */
    public function resendSetLink(string $email): void
    {
        $account = Account::findByEmail($email);
        if ($account !== null && $account['status'] === AccountStatus::Pending->value) {
            $this->sendSetLink($account, TokenPurpose::Set);
        }
    }

    /** Forgot password: active accounts get a reset link, never-activated ones a set link. */
    public function forgot(string $email): void
    {
        $account = Account::findByEmail($email);
        if ($account === null || $account['status'] === AccountStatus::Suspended->value) {
            return;
        }
        $this->sendSetLink($account, $account['status'] === AccountStatus::Pending->value ? TokenPurpose::Set : TokenPurpose::Reset);
    }

    /**
     * Apply a consumed token: store the Argon2id hash, verify the email, activate the account.
     * @param array<string, mixed> $tokenRow
     * @return array<string, mixed> the account row
     */
    public function setPassword(array $tokenRow, string $password): array
    {
        $id = (int) $tokenRow['subject_id'];
        $account = Account::find($id) ?? throw new \RuntimeException('Account not found.');
        $cols = ['password_hash' => $this->hasher->hash($password)];
        if ($account['email_verified_at'] === null) {
            $cols['email_verified_at'] = date('Y-m-d H:i:s');
        }
        if ($account['status'] === AccountStatus::Pending->value) {
            $cols['status'] = AccountStatus::Active->value;
        }
        Account::update($id, $cols);
        $this->audit->record('account.password.' . $tokenRow['purpose'], 'account', $id, actorType: 'account', actorId: $id);
        return (array) Account::find($id);
    }

    /**
     * Staff "Send portal invite": create (or reuse) the account for a customer and email an invite link.
     * @param array<string, mixed> $customer
     */
    public function invite(array $customer, int $staffId): bool
    {
        $email = mb_strtolower(trim((string) $customer['email']));
        if ($email === '') {
            return false;
        }
        $accountId = $customer['account_id'] !== null ? (int) $customer['account_id'] : null;
        if ($accountId === null) {
            $existing = Account::findByEmail($email);
            if ($existing !== null) {
                if (Customer::findByAccount((int) $existing['id']) !== null) {
                    return false; // email belongs to another visitor's portal account
                }
                $accountId = (int) $existing['id'];
            } else {
                $accountId = Account::create(['email' => $email, 'status' => AccountStatus::Pending->value]);
            }
            Customer::update((int) $customer['id'], ['account_id' => $accountId]);
        }
        $account = (array) Account::find($accountId);
        if ($account['status'] === AccountStatus::Active->value) {
            return false; // already using the portal
        }
        $this->audit->record('account.invite', 'account', $accountId, null, ['customer_id' => $customer['id']], actorType: 'staff', actorId: $staffId);
        return $this->sendSetLink($account, TokenPurpose::Invite, (string) $customer['name']);
    }

    public static function centreId(Database $db, ?int $preferred = null): int
    {
        if ($preferred !== null) {
            return $preferred;
        }
        $id = $db->scalar("SELECT id FROM centres WHERE code = 'KTR' LIMIT 1") ?? $db->scalar('SELECT id FROM centres ORDER BY id LIMIT 1');
        if ($id === null) {
            throw new \RuntimeException('No centre configured — run the seeders.');
        }
        return (int) $id;
    }
}
