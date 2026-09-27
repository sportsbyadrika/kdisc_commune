<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Container;
use App\Core\Exceptions\MethodNotAllowedException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Middleware;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use Closure;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router(new Container());
    }

    public function testMatchesStaticRoute(): void
    {
        $this->router->get('/pricing', fn () => 'pricing page')->name('pricing');
        $response = $this->router->dispatch(Request::create('GET', '/pricing'));

        self::assertSame(200, $response->status());
        self::assertSame('pricing page', $response->content());
    }

    public function testTrailingSlashIsNormalised(): void
    {
        $this->router->get('/about', fn () => 'about');
        self::assertSame('about', $this->router->dispatch(Request::create('GET', '/about/'))->content());
    }

    public function testRouteParametersAreInjectedByName(): void
    {
        $this->router->get('/visitors/{uid}/bookings/{id:\d+}', fn (string $uid, int $id) => "{$uid}#{$id}");
        $response = $this->router->dispatch(Request::create('GET', '/visitors/CMN-KTR-I-2026-00042/bookings/7'));

        self::assertSame('CMN-KTR-I-2026-00042#7', $response->content());
    }

    public function testInlineConstraintRejectsNonMatchingValues(): void
    {
        $this->router->get('/bookings/{id:\d+}', fn (int $id) => (string) $id);
        $this->expectException(NotFoundException::class);
        $this->router->dispatch(Request::create('GET', '/bookings/abc'));
    }

    public function testWhereConstraint(): void
    {
        $this->router->get('/floors/{slug}', fn (string $slug) => $slug)->where('slug', '[a-z-]+');
        self::assertSame('ground-floor', $this->router->dispatch(Request::create('GET', '/floors/ground-floor'))->content());
        $this->expectException(NotFoundException::class);
        $this->router->dispatch(Request::create('GET', '/floors/Ground_1'));
    }

    public function testUnknownPathThrowsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->router->dispatch(Request::create('GET', '/missing'));
    }

    public function testWrongMethodThrowsMethodNotAllowed(): void
    {
        $this->router->post('/contact', fn () => 'sent');
        try {
            $this->router->dispatch(Request::create('GET', '/contact'));
            self::fail('Expected MethodNotAllowedException');
        } catch (MethodNotAllowedException $e) {
            self::assertSame(405, $e->status());
            self::assertSame('POST', $e->headers()['Allow']);
        }
    }

    public function testHeadIsServedByGetRoutes(): void
    {
        $this->router->get('/', fn () => 'home');
        self::assertSame(200, $this->router->dispatch(Request::create('HEAD', '/'))->status());
    }

    public function testNamedRouteUrlGenerationWithQueryString(): void
    {
        $this->router->get('/visitors/{id}', fn () => '')->name('visitors.show');
        self::assertSame('/visitors/42', $this->router->url('visitors.show', ['id' => 42]));
        self::assertSame('/visitors/42?tab=kyc', $this->router->url('visitors.show', ['id' => 42, 'tab' => 'kyc']));
    }

    public function testUrlGenerationEncodesParameters(): void
    {
        $this->router->get('/invoices/{no}/pdf', fn () => '')->name('invoice.pdf');
        self::assertSame('/invoices/KDISC%2FCMN%2F2026-27%2F0001/pdf', $this->router->url('invoice.pdf', ['no' => 'KDISC/CMN/2026-27/0001']));
    }

    public function testMissingRouteParameterThrows(): void
    {
        $this->router->get('/visitors/{id}', fn () => '')->name('visitors.show');
        $this->expectException(\InvalidArgumentException::class);
        $this->router->url('visitors.show');
    }

    public function testGroupsApplyPrefixNamePrefixAndMiddleware(): void
    {
        $this->router->aliasMiddleware(['tag' => TagMiddleware::class]);
        $this->router->group(['prefix' => '/staff', 'as' => 'staff.', 'middleware' => ['tag:outer']], function (Router $r): void {
            $r->group(['prefix' => 'reports', 'as' => 'reports.', 'middleware' => 'tag:inner'], function (Router $r): void {
                $r->get('/gst', fn () => 'gst')->name('gst')->middleware('tag:route');
            });
        });

        self::assertSame('/staff/reports/gst', $this->router->url('staff.reports.gst'));
        $response = $this->router->dispatch(Request::create('GET', '/staff/reports/gst'));
        self::assertSame('gst', $response->content());
        self::assertSame('outer,inner,route', $response->getHeader('X-Tags'));
    }

    public function testMiddlewareCanShortCircuit(): void
    {
        $this->router->aliasMiddleware(['deny' => DenyMiddleware::class]);
        $this->router->get('/secret', fn () => 'secret')->middleware('deny');
        self::assertSame(403, $this->router->dispatch(Request::create('GET', '/secret'))->status());
    }

    public function testArrayReturnBecomesJson(): void
    {
        $this->router->get('/api/ping', fn () => ['ok' => true]);
        $response = $this->router->dispatch(Request::create('GET', '/api/ping'));
        self::assertSame('{"ok":true}', $response->content());
        self::assertStringContainsString('application/json', (string) $response->getHeader('Content-Type'));
    }

    public function testControllerActionIsResolvedFromContainer(): void
    {
        $this->router->get('/hello/{name}', [HelloController::class, 'show']);
        self::assertSame('Hello, Asha (GET)', $this->router->dispatch(Request::create('GET', '/hello/Asha'))->content());
    }
}

final class TagMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        $response = $next($request);
        $existing = $response->getHeader('X-Tags');
        return $response->header('X-Tags', $params[0] . ($existing !== null ? ',' . $existing : ''));
    }
}

final class DenyMiddleware implements Middleware
{
    public function handle(Request $request, Closure $next, string ...$params): Response
    {
        return Response::text('Forbidden', 403);
    }
}

final class HelloController
{
    public function show(Request $request, string $name): Response
    {
        return Response::text("Hello, {$name} ({$request->method()})");
    }
}
