<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Migrator;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Enums\TokenPurpose;
use App\Models\Account;
use App\Models\Customer;
use App\Services\Auth\PasswordHasher;
use App\Services\Auth\PasswordTokenService;
use App\Services\Kyc\AadhaarVault;
use App\Services\Kyc\DocumentStore;
use App\Services\Notify\Mailer;
use App\Services\Visitors\DuplicateFinder;
use App\Services\Visitors\ProfileService;
use App\Services\Visitors\RegistrationService;
use Database\Seeds\DatabaseSeeder;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end registration against the commune_test database (MySQL/MariaDB):
 * register → set-password link → wizard steps → submit → Unique Visitor ID sequence.
 * Skipped when the test database is unreachable.
 */
final class RegistrationFlowTest extends TestCase
{
    private static bool $booted = false;

    private static string $uploads = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$uploads !== '' && is_dir(self::$uploads)) {
            exec('rm -rf ' . escapeshellarg(self::$uploads));
        }
    }

    public static function setUpBeforeClass(): void
    {
        self::$uploads = sys_get_temp_dir() . '/commune-test-uploads-' . getmypid();
        foreach (['DB_DATABASE' => 'commune_test', 'MAIL_DSN' => 'null://null', 'APP_ENV' => 'testing', 'UPLOADS_PATH' => self::$uploads] as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $_SERVER[$k] = $v;
        }
        App::boot(dirname(__DIR__, 2));
        Database::setInstance(null);
        try {
            $db = Database::getInstance();
            App::container()->instance(Database::class, $db);
            $db->pdo();
            (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations', static fn () => null))->fresh();
            (new DatabaseSeeder($db, static fn () => null))->run();
            self::$booted = true;
        } catch (\Throwable) {
            self::$booted = false;
        }
    }

    protected function setUp(): void
    {
        if (!self::$booted) {
            self::markTestSkipped('commune_test database not available.');
        }
    }

    private function get(string $class): object
    {
        return App::container()->get($class);
    }

    /** @return array<string, mixed> */
    private function upload(string $ext = 'jpg'): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'kyc');
        $im = imagecreatetruecolor(120, 80);
        imagefill($im, 0, 0, (int) imagecolorallocate($im, 200, 210, 240));
        $ext === 'png' ? imagepng($im, $tmp) : imagejpeg($im, $tmp);
        return ['name' => 'scan.' . $ext, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)];
    }

    public function testOnlineRegistrationToUniqueId(): void
    {
        /** @var RegistrationService $reg */
        $reg = $this->get(RegistrationService::class);
        /** @var Mailer $mailer */
        $mailer = $this->get(Mailer::class);
        /** @var PasswordTokenService $tokens */
        $tokens = $this->get(PasswordTokenService::class);
        /** @var ProfileService $profiles */
        $profiles = $this->get(ProfileService::class);
        /** @var DocumentStore $store */
        $store = $this->get(DocumentStore::class);

        $accountId = $reg->register(['name' => 'Asha  Nair', 'email' => 'Asha@Example.com', 'mobile' => '+91 98470 12345', 'type' => 'individual']);
        self::assertNotNull($accountId);
        $account = Account::find($accountId);
        self::assertSame('asha@example.com', $account['email']);
        self::assertSame('pending', $account['status']);
        self::assertNull($account['password_hash']);

        // The emailed link carries the raw token; only its SHA-256 is stored.
        $mail = $mailer->lastSent();
        self::assertNotNull($mail);
        self::assertSame('asha@example.com', $mail->getTo()[0]->getAddress());
        self::assertMatchesRegularExpression('#/password/set/([A-Za-z0-9_-]{43})#', (string) $mail->getHtmlBody());
        preg_match('#/password/set/([A-Za-z0-9_-]{43})#', (string) $mail->getHtmlBody(), $m);
        $token = $m[1];
        self::assertSame(0, (int) db()->scalar('SELECT COUNT(*) FROM password_tokens WHERE token_hash = ?', [$token]));
        self::assertNotNull($tokens->find($token));

        // Set password: single use, activates + verifies the account.
        $row = $tokens->consume($token);
        self::assertNotNull($row);
        self::assertNull($tokens->consume($token));
        self::assertSame('used', $tokens->failureReason($token));
        $account = $reg->setPassword($row, 'Visitor@12345');
        self::assertSame('active', $account['status']);
        self::assertNotNull($account['email_verified_at']);
        self::assertTrue((new PasswordHasher())->verify('Visitor@12345', (string) Account::find($accountId)['password_hash']));

        // Wizard
        $customer = Customer::findByAccount($accountId);
        self::assertNotNull($customer);
        self::assertSame('Asha Nair', $customer['name']);
        $profiles->saveBasic($customer, [
            'sub_category' => 'freelancer', 'name' => 'Asha Nair', 'mobile' => '9847012345', 'address' => 'TC 12/345, Pulamon',
            'city' => 'Kottarakara', 'pincode' => '691506', 'state_code' => '32', 'profile' => 'Freelance UI designer needing a quiet desk three days a week.',
        ], HolderType::Account, $accountId);

        $customer = (array) Customer::find((int) $customer['id']);
        try {
            $profiles->saveIdentity($customer, ['nationality_type' => 'indian', 'aadhaar' => '234123412345', 'aadhaar_consent' => '1'], HolderType::Account, $accountId);
            self::fail('Bad Aadhaar checksum accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('aadhaar', $e->errors());
        }
        $profiles->saveIdentity($customer, [
            'nationality_type' => 'indian', 'aadhaar' => '2341 2341 2346', 'aadhaar_consent' => '1', 'pan' => 'abcpe1234f', 'gstin' => '',
        ], HolderType::Account, $accountId, $this->get(DuplicateFinder::class));
        $customer = (array) Customer::find((int) $customer['id']);
        self::assertSame('2346', $customer['aadhaar_last4']);
        self::assertSame('ABCPE1234F', $customer['pan']);
        self::assertNotNull($customer['consent_at']);
        self::assertSame('234123412346', (new AadhaarVault())->decrypt((string) $customer['aadhaar_enc']));

        // Not submittable without documents
        self::assertArrayHasKey(3, $profiles->missing($customer));
        $store->store((int) $customer['id'], DocumentType::Aadhaar, $this->upload(), HolderType::Account, $accountId, trusted: true);
        $store->store((int) $customer['id'], DocumentType::Photo, $this->upload('png'), HolderType::Account, $accountId, trusted: true);
        $pan = $store->store((int) $customer['id'], DocumentType::Pan, $this->upload(), HolderType::Account, $accountId, trusted: true);
        self::assertFileExists($store->path($pan));
        self::assertStringStartsWith(self::$uploads, $store->path($pan));
        self::assertStringStartsWith('kyc/' . $customer['id'] . '/', (string) $pan['file_path']);
        self::assertSame([], $profiles->missing($customer));

        $year = (int) date('Y');
        $uniqueId = $profiles->submit($customer, HolderType::Account, $accountId);
        self::assertSame(sprintf('CMN-KTR-I-%d-00001', $year), $uniqueId);
        $customer = (array) Customer::find((int) $customer['id']);
        self::assertSame('pending', $customer['kyc_status']);
        self::assertSame(4, (int) $customer['profile_step']);
        // Re-submitting keeps the same ID
        self::assertSame($uniqueId, $profiles->submit($customer, HolderType::Account, $accountId));

        // A second visitor cannot reuse the Aadhaar (duplicate check), and gets the next number.
        $second = $reg->register(['name' => 'Ravi', 'email' => 'ravi@example.com', 'mobile' => '9847000001', 'type' => 'individual']);
        $c2 = (array) Customer::findByAccount((int) $second);
        $profiles->saveBasic($c2, ['sub_category' => 'student', 'name' => 'Ravi K', 'mobile' => '9847000001', 'address' => 'Main Road', 'city' => 'Kollam', 'pincode' => '691001', 'state_code' => '32', 'profile' => 'Student preparing for exams; needs a quiet desk daily.'], HolderType::Account, (int) $second);
        $c2 = (array) Customer::find((int) $c2['id']);
        try {
            $profiles->saveIdentity($c2, ['nationality_type' => 'indian', 'aadhaar' => '234123412346', 'aadhaar_consent' => '1'], HolderType::Account, (int) $second, $this->get(DuplicateFinder::class));
            self::fail('Duplicate Aadhaar accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('already registered', $e->errors()['aadhaar'][0]);
        }
        $profiles->saveIdentity($c2, ['nationality_type' => 'foreign', 'country' => 'Germany', 'passport_no' => 'C01X00T47'], HolderType::Account, (int) $second);
        $c2 = (array) Customer::find((int) $c2['id']);
        $store->store((int) $c2['id'], DocumentType::Passport, $this->upload(), HolderType::Account, (int) $second, trusted: true);
        $store->store((int) $c2['id'], DocumentType::Photo, $this->upload(), HolderType::Account, (int) $second, trusted: true);
        self::assertSame(sprintf('CMN-KTR-I-%d-00002', $year), $profiles->submit($c2, HolderType::Account, (int) $second));
    }

    public function testUploadsAreSniffedNotTrusted(): void
    {
        /** @var DocumentStore $store */
        $store = $this->get(DocumentStore::class);
        $tmp = tempnam(sys_get_temp_dir(), 'kyc');
        file_put_contents($tmp, '<?php echo "pwned";');
        $customerId = (int) db()->scalar('SELECT id FROM customers ORDER BY id LIMIT 1');
        $this->expectException(ValidationException::class);
        $store->store($customerId, DocumentType::Other, ['name' => 'photo.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], HolderType::Account, null, trusted: true);
    }

    public function testExpiredTokenIsRejected(): void
    {
        /** @var PasswordTokenService $tokens */
        $tokens = $this->get(PasswordTokenService::class);
        $id = (int) db()->scalar('SELECT id FROM accounts ORDER BY id LIMIT 1');
        $token = $tokens->issue(HolderType::Account, $id, TokenPurpose::Reset, -1);
        self::assertNull($tokens->find($token));
        self::assertSame('expired', $tokens->failureReason($token));
        // issuing a new token revokes older unused ones
        $a = $tokens->issue(HolderType::Account, $id, TokenPurpose::Reset);
        $b = $tokens->issue(HolderType::Account, $id, TokenPurpose::Reset);
        self::assertNull($tokens->find($a));
        self::assertNotNull($tokens->find($b));
        self::assertSame('invalid', $tokens->failureReason('nope'));
    }

    public function testInstitutionGetsNSequence(): void
    {
        /** @var ProfileService $profiles */
        $profiles = $this->get(ProfileService::class);
        /** @var RegistrationService $reg */
        $reg = $this->get(RegistrationService::class);
        $accountId = (int) $reg->register(['name' => 'Rahul', 'email' => 'admin@ktl.example', 'mobile' => '9447011223', 'type' => 'institution']);
        $c = (array) Customer::findByAccount($accountId);
        $profiles->saveBasic($c, ['sub_category' => 'startup', 'name' => 'Kerala Tech Labs', 'mobile' => '0474 2450000', 'address' => 'Technopark Phase 1', 'city' => 'Kottarakara', 'pincode' => '691506', 'state_code' => '32',
            'profile' => str_repeat('Agritech startup building IoT sensors for paddy farmers. ', 2)], HolderType::Account, $accountId);
        $c = (array) Customer::find((int) $c['id']);
        try {
            $profiles->saveIdentity($c, ['pan' => 'AABCK1234L', 'gstin' => '32ABCPE1234F1ZK', 'tan' => 'TVDK12345E', 'sig_name' => 'Rahul', 'sig_designation' => 'Director',
                'sig_email' => 'r@ktl.example', 'sig_mobile' => '9447011223', 'sig_aadhaar' => '499123456783', 'aadhaar_consent' => '1'], HolderType::Account, $accountId);
            self::fail('GSTIN with a different PAN accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('gstin', $e->errors());
        }
        $profiles->saveIdentity($c, ['pan' => 'AABCK1234L', 'gstin' => '32AABCK1234L1ZV', 'tan' => 'TVDK12345E', 'sig_name' => 'Rahul', 'sig_designation' => 'Director',
            'sig_email' => 'r@ktl.example', 'sig_mobile' => '9447011223', 'sig_aadhaar' => '499123456783', 'aadhaar_consent' => '1'], HolderType::Account, $accountId);
        self::assertSame('6783', db()->scalar('SELECT aadhaar_last4 FROM customer_signatories WHERE customer_id = ?', [$c['id']]));
        $c = (array) Customer::find((int) $c['id']);
        self::assertSame(sprintf('CMN-KTR-N-%d-00001', (int) date('Y')), $profiles->submit($c, HolderType::Staff, null, checkDocuments: false));
    }
}
