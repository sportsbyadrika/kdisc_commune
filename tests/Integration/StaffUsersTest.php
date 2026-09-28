<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Enums\HolderType;
use App\Enums\StaffRole;
use App\Enums\TokenPurpose;
use App\Models\StaffUser;
use App\Services\Auth\PasswordTokenService;
use App\Services\Staff\StaffRuleException;
use App\Services\Staff\StaffUserService;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpKernel;

/**
 * Staff user management rules (who manages whom, self / last-manager protection, invites, deactivation), the staff
 * "Forgot password" + reset link flow, and the sign-in captcha after repeated failures.
 */
final class StaffUsersTest extends TestCase
{
    use HttpKernel;

    public static function setUpBeforeClass(): void
    {
        self::$booted = self::bootTestApp();
    }

    protected function setUp(): void
    {
        if (!self::$booted) {
            self::markTestSkipped('commune_test database not available.');
        }
        $this->signOut();
        db()->execute('DELETE FROM login_attempts');
        db()->execute('DELETE FROM rate_limits');
    }

    private function service(): StaffUserService
    {
        return App::container()->get(StaffUserService::class);
    }

    /** @return array<string, mixed> */
    private function staff(StaffRole $role): array
    {
        return (array) db()->first('SELECT * FROM staff_users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1', [$role->value]);
    }

    public function testManageableRoles(): void
    {
        self::assertSame([StaffRole::Receptionist, StaffRole::CentreManager, StaffRole::FinanceAdmin], StaffUserService::manageableRoles(StaffRole::CentreManager));
        self::assertSame(StaffRole::cases(), StaffUserService::manageableRoles(StaffRole::StateAdmin));
        self::assertSame([], StaffUserService::manageableRoles(StaffRole::Receptionist));
        self::assertSame([], StaffUserService::manageableRoles(StaffRole::FinanceAdmin));
        self::assertFalse(StaffUserService::canManage($this->staff(StaffRole::CentreManager), StaffRole::StateAdmin));
    }

    public function testCreateSendsAnInviteThatSetsThePassword(): void
    {
        $manager = $this->staff(StaffRole::CentreManager);
        $id = $this->service()->create(['name' => 'New Desk', 'email' => 'NewDesk@Commune.test', 'mobile' => '', 'role' => 'receptionist'], $manager);
        $user = (array) StaffUser::find($id);
        self::assertSame('newdesk@commune.test', $user['email']);
        self::assertSame(1, (int) $user['is_active']);
        self::assertSame((int) $manager['id'], (int) $user['created_by']);
        $token = (array) db()->first("SELECT * FROM password_tokens WHERE subject_type = 'staff' AND subject_id = ? AND used_at IS NULL", [$id]);
        self::assertSame('invite', $token['purpose']);
        self::assertGreaterThan(time() + 70 * 3600, strtotime((string) $token['expires_at']), 'invites last ~72 hours');
        self::assertNotNull(db()->first("SELECT id FROM audit_logs WHERE action = 'staff.create' AND entity_id = ?", [$id]));

        // A manager cannot create a State Admin
        $this->expectException(StaffRuleException::class);
        $this->service()->create(['name' => 'Boss', 'email' => 'boss@commune.test', 'role' => 'state_admin'], $manager);
    }

    public function testCannotDeactivateSelfOrTheLastManager(): void
    {
        $manager = $this->staff(StaffRole::CentreManager);
        $state = $this->staff(StaffRole::StateAdmin);
        try {
            $this->service()->setActive((int) $manager['id'], false, $manager);
            self::fail('self-deactivation must be refused');
        } catch (StaffRuleException $e) {
            self::assertStringContainsString('your own account', $e->getMessage());
        }
        // the only active Centre Manager cannot be deactivated or moved to another role, even by a State Admin
        db()->execute("UPDATE staff_users SET is_active = 0 WHERE role = 'centre_manager' AND id <> ?", [(int) $manager['id']]);
        try {
            $this->service()->setActive((int) $manager['id'], false, $state);
            self::fail('last manager must stay');
        } catch (StaffRuleException $e) {
            self::assertStringContainsString('last active Centre Manager', $e->getMessage());
        }
        try {
            $this->service()->update((int) $manager['id'], ['name' => $manager['name'], 'email' => $manager['email'], 'role' => 'receptionist'], $state);
            self::fail('last manager cannot be demoted');
        } catch (StaffRuleException $e) {
            self::assertStringContainsString('last active Centre Manager', $e->getMessage());
        }
        // own role cannot be changed
        try {
            $this->service()->update((int) $manager['id'], ['name' => $manager['name'], 'email' => $manager['email'], 'role' => 'finance_admin'], $manager);
            self::fail('own role change must be refused');
        } catch (StaffRuleException $e) {
            self::assertStringContainsString('own role', $e->getMessage());
        }
        // with a second manager it works, and the deactivated user is signed out + their links revoked
        $second = $this->service()->create(['name' => 'Second Manager', 'email' => 'second.mgr@commune.test', 'role' => 'centre_manager'], $state);
        $this->service()->setActive($second, false, $manager, 'left');
        self::assertSame(0, (int) StaffUser::find($second)['is_active']);
        self::assertSame(0, (int) db()->scalar("SELECT COUNT(*) FROM password_tokens WHERE subject_type = 'staff' AND subject_id = ? AND used_at IS NULL", [$second]));
        $this->actingAsStaff($second);
        self::assertStringEndsWith('/staff/login', self::location($this->http('GET', '/staff/dashboard')));
        $this->service()->setActive($second, true, $manager);
        self::assertSame(1, (int) StaffUser::find($second)['is_active']);
    }

    public function testCentreManagerCannotTouchStateAdmins(): void
    {
        $manager = $this->staff(StaffRole::CentreManager);
        $state = $this->staff(StaffRole::StateAdmin);
        $this->expectException(StaffRuleException::class);
        $this->service()->setActive((int) $state['id'], false, $manager);
    }

    public function testUsersPagesThroughHttp(): void
    {
        $manager = $this->staff(StaffRole::CentreManager);
        $this->actingAsStaff((int) $manager['id']);
        $page = $this->http('GET', '/staff/users');
        self::assertSame(200, $page->status());
        self::assertStringContainsString('reception@commune.test', $page->content());
        self::assertStringNotContainsString('password_hash', $page->content());

        $this->actingAsStaff((int) $manager['id']);
        $r = $this->submit('POST', '/staff/users', ['name' => 'Http User', 'email' => 'http.user@commune.test', 'mobile' => '', 'role' => 'finance_admin']);
        self::assertSame(302, $r->status());
        self::assertNotNull(StaffUser::findByEmail('http.user@commune.test'));

        // role outside the manager's reach is a validation error
        $this->actingAsStaff((int) $manager['id']);
        $this->submit('POST', '/staff/users', ['name' => 'Sneaky', 'email' => 'sneaky@commune.test', 'role' => 'state_admin']);
        self::assertNull(StaffUser::findByEmail('sneaky@commune.test'));

        // receptionists cannot open the page
        $this->actingAsStaff((int) $this->staff(StaffRole::Receptionist)['id']);
        self::assertSame(403, $this->http('GET', '/staff/users')->status());
    }

    public function testForgotAndResetPasswordFlow(): void
    {
        $user = $this->staff(StaffRole::FinanceAdmin);
        // unknown emails behave exactly like known ones (no enumeration)
        $a = $this->submit('POST', '/staff/password/forgot', ['email' => 'nobody@commune.test']);
        $this->signOut();
        $b = $this->submit('POST', '/staff/password/forgot', ['email' => $user['email']]);
        self::assertSame(self::location($a), self::location($b));
        self::assertSame(1, (int) db()->scalar("SELECT COUNT(*) FROM password_tokens WHERE subject_type = 'staff' AND subject_id = ? AND purpose = 'reset' AND used_at IS NULL", [(int) $user['id']]));

        $token = App::container()->get(PasswordTokenService::class)->issue(HolderType::Staff, (int) $user['id'], TokenPurpose::Reset);
        $this->signOut();
        $form = $this->http('GET', '/staff/password/reset/' . $token);
        self::assertSame(200, $form->status());
        self::assertSame('no-referrer', $form->getHeader('Referrer-Policy'));
        $this->signOut();
        $weak = $this->submit('POST', '/staff/password/reset/' . $token, ['password' => 'short', 'password_confirmation' => 'short']);
        self::assertSame(302, $weak->status());
        $this->signOut();
        $ok = $this->submit('POST', '/staff/password/reset/' . $token, ['password' => 'N3w-Passw0rd!', 'password_confirmation' => 'N3w-Passw0rd!']);
        self::assertStringEndsWith('/staff/login', self::location($ok));
        $row = (array) StaffUser::find((int) $user['id']);
        self::assertTrue(password_verify('N3w-Passw0rd!', (string) $row['password_hash']));
        self::assertNotNull($row['password_changed_at']);
        // single use
        $this->signOut();
        self::assertSame(410, $this->http('GET', '/staff/password/reset/' . $token)->status());
        // a visitor token never works on the staff form
        $visitorToken = App::container()->get(PasswordTokenService::class)->issue(HolderType::Account, 1, TokenPurpose::Reset);
        $this->signOut();
        self::assertSame(410, $this->http('GET', '/staff/password/reset/' . $visitorToken)->status());
        db()->execute('UPDATE staff_users SET password_hash = ? WHERE id = ?', [password_hash('Password@123', PASSWORD_ARGON2ID), (int) $user['id']]);
    }

    public function testLoginAsksForACaptchaAfterRepeatedFailures(): void
    {
        $email = 'reception@commune.test';
        $ip = ['REMOTE_ADDR' => '203.0.113.7'];
        for ($i = 0; $i < 3; $i++) {
            $this->http('POST', '/staff/login', ['_token' => $this->csrf(), 'email' => $email, 'password' => 'wrong'], $ip);
        }
        $form = $this->http('GET', '/staff/login', [], $ip);
        self::assertStringContainsString('data-test="captcha"', $form->content());
        // the right password without the captcha answer is refused
        $r = $this->http('POST', '/staff/login', ['_token' => $this->csrf(), 'email' => $email, 'password' => 'Password@123', 'captcha' => '999'], $ip);
        self::assertStringEndsWith('/staff/login', self::location($r));
        self::assertArrayNotHasKey('_auth_staff', $_SESSION);
        // a cookie-less client (new session) is still asked: failures are counted per email + IP in the database
        $this->signOut();
        $r = $this->http('POST', '/staff/login', ['_token' => $this->csrf(), 'email' => $email, 'password' => 'Password@123'], $ip);
        self::assertArrayNotHasKey('_auth_staff', $_SESSION);
        // answering the question signs in
        db()->execute('DELETE FROM login_attempts WHERE ip = ? AND succeeded = 0 ORDER BY id DESC LIMIT 1', ['203.0.113.7']);
        $this->http('GET', '/staff/login', [], $ip);
        $answer = $_SESSION['_captcha_login_staff'];
        $r = $this->http('POST', '/staff/login', ['_token' => $this->csrf(), 'email' => $email, 'password' => 'Password@123', 'captcha' => (string) $answer], $ip);
        self::assertSame('/staff/dashboard', self::location($r));
        self::assertArrayNotHasKey('_login_captcha_staff', $_SESSION);
    }

    public function testVisitorLoginCaptcha(): void
    {
        $ip = ['REMOTE_ADDR' => '203.0.113.9'];
        for ($i = 0; $i < 3; $i++) {
            $this->http('POST', '/login', ['_token' => $this->csrf(), 'email' => 'someone@example.test', 'password' => 'nope'], $ip);
        }
        self::assertStringContainsString('data-test="captcha"', $this->http('GET', '/login', [], $ip)->content());
    }
}
