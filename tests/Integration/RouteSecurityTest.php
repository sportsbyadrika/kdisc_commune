<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\App;
use App\Core\ErrorHandler;
use App\Core\Request;
use App\Core\Route;
use App\Core\Router;
use App\Core\View;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Enums\StaffRole;
use App\Middleware\VerifyCsrfToken;
use App\Models\Account;
use App\Models\Customer;
use App\Services\Demo\DemoSeeder;
use App\Services\Finance\FinanceDocuments;
use App\Services\Kyc\DocumentStore;
use App\Support\Clock;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\HttpKernel;

/**
 * Batch 8 security regression suite, driven by the real route table:
 *  - every route is either on the explicit public allowlist or requires a signed-in staff user / visitor
 *    (unauthenticated → redirect to the right sign-in page, or 401 JSON);
 *  - every staff route declares an ability (can:) or role (role:), and a role WITHOUT it gets 403;
 *  - every state-changing route rejects a missing / wrong CSRF token (419);
 *  - visitor-owned resources (bookings, documents, invoices, PDFs, holds) are not reachable by another visitor;
 *  - open redirects, error pages without traces, security headers, sessions dropped after a password change.
 */
final class RouteSecurityTest extends TestCase
{
    use HttpKernel;

    /** Routes that must work without signing in (by route name; * = prefix). */
    public const PUBLIC = [
        'home', 'spaces', 'spaces.explore', 'spaces.floor', 'facilities', 'pricing', 'about', 'privacy', 'terms', 'contact', 'contact.submit',
        'portal.login', 'portal.login.attempt', 'portal.register', 'portal.register.store', 'portal.register.sent',
        'portal.password.forgot', 'portal.password.email', 'portal.password.resend', 'portal.password.sent',
        'portal.password.set', 'portal.password.set.store', 'portal.password.reset', 'portal.password.reset.store', 'portal.logout',
        'staff.home', 'staff.login', 'staff.login.attempt', 'staff.logout',
        'staff.password.forgot', 'staff.password.email', 'staff.password.reset', 'staff.password.update',
        // Space Explorer API for guests: reads are public, holds / quote answer 401 or work on the guest's own data
        'api.space.*',
    ];

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
    }

    /** @return list<Route> */
    private static function routes(): array
    {
        return App::container()->get(Router::class)->routes();
    }

    private static function isPublic(Route $route): bool
    {
        $name = (string) $route->getName();
        foreach (self::PUBLIC as $p) {
            if ($name === $p || (str_ends_with($p, '*') && str_starts_with($name, rtrim($p, '*')))) {
                return true;
            }
        }
        return false;
    }

    /** A concrete URI for a route, with sample values that satisfy each parameter's pattern. */
    private static function uri(Route $route, array $values = []): string
    {
        return (string) preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}#', static function (array $m) use ($values): string {
            if (isset($values[$m[1]])) {
                return (string) $values[$m[1]];
            }
            if ($m[1] === 'token') {
                return str_repeat('A', 43);
            }
            $pattern = ($m[2] ?? '') !== '' ? $m[2] : '[^/]+';
            foreach (['1', 'invoice', 'ground-floor', 'BK-2026-000001', 'x'] as $candidate) {
                if (preg_match('#^(?:' . $pattern . ')$#', $candidate) === 1) {
                    return $candidate;
                }
            }
            return '1';
        }, $route->uri());
    }

    private static function method(Route $route): string
    {
        return array_values(array_diff($route->methods(), ['HEAD']))[0];
    }

    // ------------------------------------------------------------------ route table rules

    public function testEveryNonPublicRouteIsBehindAnAuthMiddleware(): void
    {
        $unguarded = [];
        foreach (self::routes() as $route) {
            if (self::isPublic($route)) {
                continue;
            }
            $mw = $route->getMiddleware();
            if (!in_array('auth.staff', $mw, true) && !in_array('auth.visitor', $mw, true)) {
                $unguarded[] = self::method($route) . ' ' . $route->uri() . ' (' . $route->getName() . ')';
            }
        }
        self::assertSame([], $unguarded, 'Routes without auth middleware must be on the public allowlist.');
    }

    public function testEveryStaffRouteDeclaresAnAbilityOrRole(): void
    {
        $missing = [];
        foreach (self::routes() as $route) {
            if (self::isPublic($route) || !in_array('auth.staff', $route->getMiddleware(), true)) {
                continue;
            }
            $declared = array_filter($route->getMiddleware(), static fn (string $m) => str_starts_with($m, 'can:') || str_starts_with($m, 'role:'));
            if ($declared === []) {
                $missing[] = $route->uri();
            }
            foreach ($declared as $m) {
                if (str_starts_with($m, 'can:')) {
                    foreach (explode(',', substr($m, 4)) as $ability) {
                        $known = array_filter(StaffRole::cases(), static fn (StaffRole $r) => $r->can($ability));
                        self::assertNotEmpty($known, "Ability [{$ability}] on {$route->uri()} is not granted to any role.");
                    }
                }
            }
        }
        self::assertSame([], $missing, 'Staff routes must declare can:<ability> or role:<roles>.');
    }

    public function testUnauthenticatedRequestsAreRedirectedOrRejected(): void
    {
        $checked = 0;
        foreach (self::routes() as $route) {
            $method = self::method($route);
            $uri = self::uri($route);
            $this->signOut();
            $response = $method === 'GET' ? $this->http('GET', $uri) : $this->submit($method, $uri);
            if (self::isPublic($route)) {
                self::assertLessThan(500, $response->status(), "{$method} {$uri} failed for a guest.");
                continue;
            }
            $checked++;
            $staff = in_array('auth.staff', $route->getMiddleware(), true);
            self::assertSame(302, $response->status(), "{$method} {$uri} should redirect a guest to sign in.");
            self::assertStringEndsWith($staff ? '/staff/login' : '/login', self::location($response), "{$method} {$uri}");
            // JSON callers get 401, never data
            $this->signOut();
            $json = $this->json($method, $uri);
            self::assertSame(401, $json->status(), "{$method} {$uri} (JSON) should answer 401.");
        }
        self::assertGreaterThan(100, $checked);
    }

    public function testGuestsCannotHoldOrBookThroughTheApi(): void
    {
        foreach (['POST /api/space/holds', 'POST /api/space/holds/renew', 'DELETE /api/space/holds', 'DELETE /api/space/holds/1'] as $call) {
            [$method, $uri] = explode(' ', $call);
            $this->signOut();
            self::assertSame(401, $this->json($method, $uri, ['seat_ids' => [1]])->status(), $call);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function roles(): iterable
    {
        foreach ([StaffRole::Receptionist, StaffRole::FinanceAdmin, StaffRole::StateAdmin, StaffRole::CentreManager] as $role) {
            yield $role->value => [$role->value];
        }
    }

    #[DataProvider('roles')]
    public function testRolesWithoutTheAbilityGet403(string $roleValue): void
    {
        $role = StaffRole::from($roleValue);
        $staffId = (int) db()->scalar('SELECT id FROM staff_users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1', [$role->value]);
        $denied = 0;
        foreach (self::routes() as $route) {
            $abilities = [];
            foreach ($route->getMiddleware() as $m) {
                if (str_starts_with($m, 'can:')) {
                    $abilities[] = explode(',', substr($m, 4));
                }
            }
            // every can: on the route must pass (AND between middleware, OR inside one)
            $allowed = array_reduce($abilities, static fn (bool $ok, array $any) => $ok && array_filter($any, static fn (string $a) => $role->can($a)) !== [], true);
            if ($abilities === [] || $allowed) {
                continue;
            }
            $method = self::method($route);
            $this->actingAsStaff($staffId);
            $response = $method === 'GET' ? $this->http('GET', self::uri($route)) : $this->submit($method, self::uri($route));
            self::assertSame(403, $response->status(), "{$role->label()} must not reach {$method} {$route->uri()}");
            $denied++;
        }
        if ($role === StaffRole::CentreManager) {
            self::assertGreaterThanOrEqual(0, $denied);
        } else {
            self::assertGreaterThan(5, $denied, 'The role should be denied some routes.');
        }
    }

    #[DataProvider('roles')]
    public function testPagesOnlyLinkToWhatTheRoleMayOpen(string $roleValue): void
    {
        $role = StaffRole::from($roleValue);
        $staffId = (int) db()->scalar('SELECT id FROM staff_users WHERE role = ? AND is_active = 1 ORDER BY id LIMIT 1', [$role->value]);
        $pages = [];
        foreach ((array) App::config('navigation.staff', []) as $item) {
            if (isset($item['route']) && $role->can((string) ($item['can'] ?? '')) && App::container()->get(Router::class)->has((string) $item['route'])) {
                $pages[] = url((string) $item['route']);
            }
        }
        self::assertNotEmpty($pages);
        $links = [];
        foreach ($pages as $page) {
            $this->actingAsStaff($staffId);
            $response = $this->http('GET', $page);
            self::assertContains($response->status(), [200, 302], "{$role->label()} sidebar page {$page}");
            preg_match_all('~href="(/staff/[^"#]*)"~', $response->content(), $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href);
                $key = (string) preg_replace(['#\?.*$#', '#/\d+#', '#BK-\d{4}-\d+#', '#CMN-KTR-[IN]-\d{4}-\d+#'], ['', '/N', 'BK', 'UID'], $href);
                if (!preg_match('#\.(pdf|xlsx)(\?|$)#', $href) && !isset($links[$key])) {
                    $links[$key] = [$href, $page];
                }
            }
        }
        $forbidden = [];
        foreach ($links as [$href, $from]) {
            $this->actingAsStaff($staffId);
            if ($this->http('GET', $href)->status() === 403) {
                $forbidden[] = "{$href} (linked from {$from})";
            }
        }
        self::assertSame([], $forbidden, "{$role->label()} sees links it may not open");
    }

    // ------------------------------------------------------------------ CSRF

    public function testEveryStateChangingRouteRequiresACsrfToken(): void
    {
        $checked = 0;
        foreach (self::routes() as $route) {
            $method = self::method($route);
            if ($method === 'GET') {
                continue;
            }
            $this->signOut();
            self::assertSame(419, $this->http($method, self::uri($route))->status(), "{$method} {$route->uri()} without a token");
            $this->signOut();
            $this->csrf();
            self::assertSame(419, $this->http($method, self::uri($route), ['_token' => str_repeat('0', 64)])->status(), "{$method} {$route->uri()} with a wrong token");
            $checked++;
        }
        self::assertGreaterThan(60, $checked);
        $except = (new \ReflectionClass(VerifyCsrfToken::class))->getProperty('except');
        self::assertSame([], $except->getDefaultValue(), 'No route may be exempt from CSRF.');
    }

    // ------------------------------------------------------------------ IDOR (visitor-owned resources)

    public function testVisitorsCannotReachAnotherVisitorsResources(): void
    {
        $clock = App::container()->get(Clock::class);
        $clock->freeze(new \DateTimeImmutable('2026-09-27 10:00:00'));
        App::container()->get(DemoSeeder::class)->run();
        $clock->freeze(null);

        $pairs = db()->select(
            "SELECT b.customer_id, MIN(b.booking_no) AS booking_no, MIN(i.id) AS invoice_id
             FROM bookings b JOIN invoices i ON i.booking_id = b.id AND i.customer_id = b.customer_id
             WHERE b.status IN ('confirmed', 'active', 'completed') GROUP BY b.customer_id ORDER BY b.customer_id LIMIT 2",
        );
        self::assertCount(2, $pairs, 'Demo data should have two visitors with invoiced bookings.');
        [$a, $b] = $pairs;
        $accounts = [];
        foreach ([$a, $b] as $i => $row) {
            $id = Account::create(['email' => "idor{$i}@example.test", 'status' => 'active', 'password_hash' => 'x', 'email_verified_at' => date('Y-m-d H:i:s')]);
            Customer::update((int) $row['customer_id'], ['account_id' => $id]);
            $accounts[$i] = $id;
        }
        $invoiceB = (array) db()->first('SELECT * FROM invoices WHERE id = ?', [(int) $b['invoice_id']]);
        $invoiceA = (array) db()->first('SELECT * FROM invoices WHERE id = ?', [(int) $a['invoice_id']]);

        // a KYC document of visitor B
        $tmp = tempnam(sys_get_temp_dir(), 'idor');
        $im = imagecreatetruecolor(60, 40);
        imagejpeg($im, $tmp);
        $docId = (int) App::container()->get(DocumentStore::class)->store((int) $b['customer_id'], DocumentType::Other, ['name' => 'scan.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK], HolderType::Account, $accounts[1], trusted: true)['id'];

        // a live seat hold of visitor B
        $seat = (int) db()->scalar("SELECT s.id FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id WHERE lv.status = 'published' AND s.parent_id IS NULL ORDER BY s.id DESC LIMIT 1");
        db()->insert('seat_holds', ['seat_id' => $seat, 'holder_type' => 'account', 'holder_id' => $accounts[1], 'session_id' => 'visitor-b', 'start_at' => '2027-01-01 00:00:00', 'end_at' => '2027-01-02 00:00:00', 'expires_at' => date('Y-m-d H:i:s', time() + 600)]);

        $slugB = FinanceDocuments::slug('invoice', $invoiceB);
        $probes = [
            ['GET', '/my/bookings/' . $b['booking_no']],
            ['GET', '/my/bookings/' . $b['booking_no'] . '/renew'],
            ['GET', '/my/bookings/' . $b['booking_no'] . '/allotment-letter.pdf'],
            ['POST', '/my/bookings/' . $b['booking_no'] . '/cancel'],
            ['GET', '/my/invoices/invoice/' . $invoiceB['id'] . '/' . $slugB . '.pdf'],
            ['GET', '/my/documents/' . $docId . '/file'],
            ['DELETE', '/my/documents/' . $docId],
            ['GET', '/spaces/checkout/done/' . $b['booking_no']],
        ];
        foreach ($probes as [$method, $uri]) {
            $this->actingAsVisitor($accounts[0]);
            $response = $method === 'GET' ? $this->http('GET', $uri) : $this->submit($method, $uri);
            self::assertSame(404, $response->status(), "Visitor A reached {$method} {$uri}");
        }
        // the document and B's booking are untouched
        self::assertNotNull(db()->first('SELECT id FROM customer_documents WHERE id = ?', [$docId]));
        self::assertNotSame('cancelled', db()->scalar('SELECT status FROM bookings WHERE booking_no = ?', [$b['booking_no']]));

        // A cannot release B's hold
        $this->actingAsVisitor($accounts[0]);
        $this->json('DELETE', '/api/space/holds/' . $seat);
        $this->actingAsVisitor($accounts[0]);
        $this->json('DELETE', '/api/space/holds');
        self::assertSame(1, (int) db()->scalar('SELECT COUNT(*) FROM seat_holds WHERE seat_id = ? AND holder_id = ?', [$seat, $accounts[1]]));

        // positive control: A's own invoice PDF works
        $this->actingAsVisitor($accounts[0]);
        $own = $this->http('GET', '/my/invoices/invoice/' . $invoiceA['id'] . '/' . FinanceDocuments::slug('invoice', $invoiceA) . '.pdf');
        self::assertSame(200, $own->status());
        self::assertSame('application/pdf', $own->getHeader('Content-Type'));
        // the slug must match the id (no guessing by id alone)
        $this->actingAsVisitor($accounts[0]);
        self::assertSame(404, $this->http('GET', '/my/invoices/invoice/' . $invoiceA['id'] . '/' . $slugB . '.pdf')->status());
    }

    // ------------------------------------------------------------------ redirects, errors, headers, sessions

    public function testLoginNextParameterCannotRedirectOffSite(): void
    {
        foreach (['//evil.example/my', 'https://evil.example/my', '/\\evil.example', '/my/../..//evil.example', '/myevil', '/my%2F%2Fevil.example'] as $next) {
            $this->signOut();
            $this->http('GET', '/login?next=' . rawurlencode($next));
            self::assertArrayNotHasKey('_intended', $_SESSION, "next={$next} must be ignored");
        }
        $this->signOut();
        $this->http('GET', '/login?next=' . rawurlencode('/spaces/explore/ground-floor?from=2026-10-01'));
        self::assertSame('/spaces/explore/ground-floor?from=2026-10-01', $_SESSION['_intended'] ?? null);

        // "back" after a validation error never follows a foreign Referer
        $this->signOut();
        $response = $this->submit('POST', '/contact', []) ;
        self::assertSame(302, $response->status());
        $this->signOut();
        $response = $this->http('POST', '/contact', ['_token' => $this->csrf()], ['HTTP_REFERER' => 'https://evil.example/phish']);
        self::assertStringNotContainsString('evil.example', self::location($response));
        $this->signOut();
        $response = $this->http('POST', '/contact', ['_token' => $this->csrf()], ['HTTP_REFERER' => 'http://localhost/contact?x=1']);
        self::assertSame('http://localhost/contact?x=1', self::location($response));
    }

    public function testStaffIntendedUrlIsLimitedToTheConsole(): void
    {
        $this->signOut();
        $_SESSION['_intended'] = 'https://evil.example/staff/x';
        $response = $this->submit('POST', '/staff/login', ['email' => 'manager@commune.test', 'password' => 'Password@123']);
        self::assertSame('/staff/dashboard', self::location($response));
    }

    public function testErrorPagesNeverLeakTracesWithoutDebug(): void
    {
        $handler = new ErrorHandler(false, null, App::container()->get(View::class));
        $e = new \RuntimeException('SQLSTATE secret-table-name at /home/app/Database.php');
        $html = $handler->render($e, Request::create('GET', '/x'));
        self::assertSame(500, $html->status());
        self::assertStringNotContainsString('secret-table-name', $html->content());
        self::assertStringNotContainsString('RuntimeException', $html->content());
        self::assertStringNotContainsString('.php', strip_tags($html->content()));
        $json = $handler->render($e, Request::create('GET', '/x', [], ['HTTP_ACCEPT' => 'application/json']));
        self::assertSame(['message' => 'Server error'], json_decode($json->content(), true));
        // and the app booted for these tests really runs with APP_DEBUG=false
        self::assertFalse((bool) App::config('app.debug'));
    }

    public function testSecurityHeaders(): void
    {
        $response = $this->http('GET', '/');
        self::assertSame('SAMEORIGIN', $response->getHeader('X-Frame-Options'));
        self::assertSame('nosniff', $response->getHeader('X-Content-Type-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->getHeader('Referrer-Policy'));
        self::assertStringContainsString('camera=()', (string) $response->getHeader('Permissions-Policy'));
        $csp = (string) $response->getHeader('Content-Security-Policy');
        self::assertStringContainsString("object-src 'none'", $csp);
        self::assertStringContainsString("frame-ancestors 'self'", $csp);
        self::assertStringNotContainsString("'unsafe-inline'", explode('; ', $csp)[1], 'scripts never allow inline code');
        self::assertNull($response->getHeader('Strict-Transport-Security'), 'no HSTS over plain HTTP');
        self::assertSame('no-store, private', $response->getHeader('Cache-Control'));

        // camera only on the pages that capture documents / scan QR codes
        $staffId = (int) db()->scalar("SELECT id FROM staff_users WHERE role = 'receptionist' LIMIT 1");
        $this->actingAsStaff($staffId);
        self::assertStringContainsString('camera=(self)', (string) $this->http('GET', '/staff/checkin')->getHeader('Permissions-Policy'));

        // HSTS on HTTPS; a spoofed X-Forwarded-Proto from an untrusted peer is ignored
        self::assertStringStartsWith('max-age=', (string) $this->http('GET', '/', [], ['HTTPS' => 'on'])->getHeader('Strict-Transport-Security'));
        self::assertNull($this->http('GET', '/', [], ['HTTP_X_FORWARDED_PROTO' => 'https'])->getHeader('Strict-Transport-Security'));
    }

    public function testPasswordChangeSignsOutOlderSessions(): void
    {
        $id = (int) db()->scalar("SELECT id FROM staff_users WHERE role = 'finance_admin' LIMIT 1");
        $this->actingAsStaff($id);
        self::assertSame(200, $this->http('GET', '/staff/dashboard')->status());
        db()->execute('UPDATE staff_users SET password_changed_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() + 5), $id]);
        $response = $this->http('GET', '/staff/dashboard');
        self::assertSame(302, $response->status());
        self::assertStringEndsWith('/staff/login', self::location($response));
        db()->execute('UPDATE staff_users SET password_changed_at = NULL WHERE id = ?', [$id]);
    }
}
