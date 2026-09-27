<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Enums\BillingUnit;
use App\Enums\RateScope;
use App\Enums\SeatCategory;
use App\Enums\StaffRole;
use App\Services\Layout\DesignerPresenter;
use App\Services\Layout\LayoutDraftService;
use App\Services\Layout\LayoutException;
use App\Services\Layout\LayoutPublisher;
use App\Services\Pricing\RateService;

/**
 * JSON API of the Layout & Pricing Designer canvas (resources/js/designer.js), mounted at /staff/layout/api
 * (auth.staff + can:layout.design; pricing writes also need can:pricing.manage). CSRF via X-CSRF-TOKEN.
 *
 *   POST   floors/{floor}/draft             create (or return) the draft — clone of the published version
 *   GET    versions/{v}                     document + version meta + pricing
 *   PUT    versions/{v}                     autosave the whole document {revision, doc} → {revision, ids}
 *   DELETE versions/{v}                     discard the draft
 *   GET    versions/{v}/check               publish validation report
 *   POST   versions/{v}/publish             {confirm, notes}
 *   GET    versions/{v}/pricing?date=       effective rates of every unit/zone
 *   POST   versions/{v}/rates               {target: seat|zone, ids[], unit, amount, gst_rate, effective_from, note}
 *   POST   versions/{v}/rates/clear         {target, ids[], unit|null, effective_from}
 *
 * Errors: 422 {message} (rules), 409 {message, reason|validation} (conflicts / confirmation needed).
 */
final class LayoutApiController extends Controller
{
    public function __construct(
        private readonly LayoutDraftService $drafts,
        private readonly LayoutPublisher $publisher,
        private readonly DesignerPresenter $presenter,
    ) {
    }

    public function createDraft(Request $request, int $floor): Response
    {
        return $this->guard(function () use ($request, $floor): array {
            if (db()->scalar('SELECT id FROM floors WHERE id = ?', [$floor]) === null) {
                throw new LayoutException('Floor not found.', 404);
            }
            $from = $request->int('from_version_id');
            $v = $this->drafts->createDraft($floor, $this->staffId(), $from > 0 ? $from : null);
            return $this->payload($v);
        });
    }

    public function show(int $version): Response
    {
        return $this->guard(fn (): array => $this->payload($this->version($version)));
    }

    public function save(Request $request, int $version): Response
    {
        return $this->guard(function () use ($request, $version): array {
            $doc = $request->input('doc');
            if (!is_array($doc)) {
                throw new LayoutException('Nothing to save.');
            }
            return $this->drafts->save($version, $request->int('revision'), $doc, $this->staffId());
        });
    }

    public function discard(int $version): Response
    {
        return $this->guard(function () use ($version): array {
            $this->drafts->discard($version);
            return ['ok' => true];
        });
    }

    public function check(int $version): Response
    {
        return $this->guard(fn (): array => $this->publisher->check($this->version($version)['id']));
    }

    public function publish(Request $request, int $version): Response
    {
        return $this->guard(function () use ($request, $version): array {
            $out = $this->publisher->publish($version, $this->staffId(), $request->bool('confirm'), (string) $request->input('notes', ''));
            return $out + ['message' => sprintf('Version %d is live.', (int) $out['version']['version_no'])];
        });
    }

    public function pricing(Request $request, int $version): Response
    {
        return $this->guard(function () use ($request, $version): array {
            $date = $request->string('date');
            return $this->presenter->pricing($this->version($version)['id'], preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : null);
        });
    }

    public function setRates(Request $request, int $version, RateService $rates): Response
    {
        return $this->guard(function () use ($request, $version, $rates): array {
            $v = $this->version($version);
            [$scope, $keys, $categories] = $this->targets($request, $v['id']);
            $unit = BillingUnit::tryFrom($request->string('unit')) ?? throw new LayoutException('Choose day, month or hour.');
            foreach ($categories as $cat) {
                if (!in_array($unit, $cat->billingUnits(), true)) {
                    throw new LayoutException(sprintf('%s is not billed per %s.', $cat->shortLabel(), $unit->value));
                }
            }
            $amount = $request->input('amount');
            $gst = $request->input('gst_rate', setting('gst_rate', 18));
            if (!is_numeric($amount) || !is_numeric($gst)) {
                throw new LayoutException('Enter the amount and GST rate as numbers.');
            }
            $ids = $rates->set($scope, $keys, $unit, (float) $amount, (float) $gst, $request->string('effective_from') ?: $this->today(), $this->staffId(), $request->string('note') ?: null);
            return ['created' => $ids, 'pricing' => $this->presenter->pricing($v['id'])];
        });
    }

    public function clearRates(Request $request, int $version, RateService $rates): Response
    {
        return $this->guard(function () use ($request, $version, $rates): array {
            $v = $this->version($version);
            [$scope, $keys] = $this->targets($request, $v['id']);
            $unit = $request->string('unit') !== '' ? (BillingUnit::tryFrom($request->string('unit')) ?? throw new LayoutException('Unknown unit.')) : null;
            $n = $rates->clear($scope, $keys, $unit, $request->string('effective_from') ?: $this->today());
            return ['cleared' => $n, 'pricing' => $this->presenter->pricing($v['id'])];
        });
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Map posted seat/zone ROW ids of this version to their stable keys (the rate scope ids).
     *
     * @return array{0: RateScope, 1: list<int>, 2: list<SeatCategory>}
     */
    private function targets(Request $request, int $versionId): array
    {
        if (!StaffRole::from((string) (App::guard('staff')->user()['role'] ?? ''))->can('pricing.manage')) {
            throw new LayoutException('Your role cannot change prices.', 403);
        }
        $scope = match ($request->string('target')) {
            'seat' => RateScope::Seat,
            'zone' => RateScope::Zone,
            default => throw new LayoutException('Choose seats or a zone to price.'),
        };
        $ids = array_values(array_filter(array_map('intval', is_array($request->input('ids')) ? $request->input('ids') : []), static fn (int $i) => $i > 0));
        if ($ids === [] || count($ids) > 500) {
            throw new LayoutException('Select 1 to 500 seats/zones — save the draft first if you just added them.');
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $rows = $scope === RateScope::Seat
            ? db()->select("SELECT s.seat_key AS k, sc.code FROM seats s JOIN zones z ON z.id = s.zone_id LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id WHERE z.layout_version_id = ? AND s.parent_id IS NULL AND s.id IN ({$in})", [$versionId, ...$ids])
            : db()->select("SELECT z.zone_key AS k, sc.code FROM zones z LEFT JOIN seat_categories sc ON sc.id = z.seat_category_id WHERE z.layout_version_id = ? AND z.id IN ({$in})", [$versionId, ...$ids]);
        if (count($rows) !== count($ids)) {
            throw new LayoutException('Some of the selected items are not bookable units of this layout (chairs of a cabin are priced with their cabin).');
        }
        $cats = [];
        foreach ($rows as $r) {
            $c = SeatCategory::tryFrom((string) $r['code']) ?? throw new LayoutException('Give the zone a space type before pricing it.');
            $cats[$c->value] = $c;
        }
        return [$scope, array_map(static fn (array $r) => (int) $r['k'], $rows), array_values($cats)];
    }

    /** @return array<string, mixed> */
    private function version(int $id): array
    {
        return $this->drafts->version($id) ?? throw new LayoutException('Layout version not found.', 404);
    }

    /**
     * @param array<string, mixed> $v
     * @return array<string, mixed>
     */
    private function payload(array $v): array
    {
        $by = $v['updated_by'] !== null ? db()->scalar('SELECT name FROM staff_users WHERE id = ?', [(int) $v['updated_by']]) : null;
        return [
            'version' => ['id' => (int) $v['id'], 'no' => (int) $v['version_no'], 'status' => $v['status'], 'revision' => (int) $v['revision'], 'updated_at' => $v['updated_at'], 'updated_by' => $by],
            'doc' => $this->drafts->document((int) $v['id']),
            'pricing' => $this->presenter->pricing((int) $v['id']),
        ];
    }

    private function staffId(): int
    {
        return (int) App::guard('staff')->id();
    }

    private function today(): string
    {
        return App::container()->get(\App\Support\Clock::class)->today();
    }

    /** @param \Closure(): array<string, mixed> $action */
    private function guard(\Closure $action): Response
    {
        try {
            return Response::json($action())->header('Cache-Control', 'no-store');
        } catch (LayoutException $e) {
            return Response::json(['message' => $e->getMessage()] + $e->payload, $e->status);
        }
    }
}
