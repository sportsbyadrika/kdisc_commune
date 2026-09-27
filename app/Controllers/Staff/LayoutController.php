<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\App;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\BillingUnit;
use App\Enums\RateScope;
use App\Enums\SeatCategory;
use App\Enums\StaffRole;
use App\Services\Layout\BuildingService;
use App\Services\Layout\DesignerPresenter;
use App\Services\Layout\LayoutDraftService;
use App\Services\Layout\LayoutException;
use App\Services\Pricing\RateService;
use App\Services\Space\ExplorerPresenter;
use App\Services\Space\FloorMapService;

/**
 * Layout & Pricing Designer pages (spec 5.4): the canvas designer per floor, draft preview, version history,
 * category base rates, and the building page (photos, floor hotspots, floors). JSON endpoints used by the
 * canvas live in LayoutApiController.
 */
final class LayoutController extends Controller
{
    public function __construct(
        private readonly FloorMapService $maps,
        private readonly LayoutDraftService $drafts,
        private readonly DesignerPresenter $presenter,
    ) {
    }

    public function index(): Response
    {
        $floors = $this->maps->floors();
        if ($floors === []) {
            return redirect(url('staff.layout.building'));
        }
        return redirect(url('staff.layout.floor', ['floor' => (string) $floors[0]['slug']]));
    }

    public function designer(string $floor): Response
    {
        $row = $this->floor($floor);
        $role = $this->role();
        $api = url('/staff/layout/api');
        return $this->view('staff/layout/designer', [
            'title' => 'Layout designer · ' . $row['name'],
            'floor' => $row,
            'floors' => $this->maps->floors(),
            'config' => $this->presenter->config($row, [
                'api' => $api,
                'createDraft' => $api . '/floors/' . (int) $row['id'] . '/draft',
                'preview' => url('staff.layout.preview', ['floor' => (string) $row['slug']]),
                'history' => url('staff.layout.history', ['floor' => (string) $row['slug']]),
                'rates' => url('staff.layout.rates'),
                'explorer' => url('spaces.floor', ['floor' => (string) $row['slug']]),
            ], $role->can('pricing.manage')),
        ]);
    }

    public function preview(Request $request, string $floor, ExplorerPresenter $explorer): Response
    {
        $row = $this->floor($floor);
        $versionId = $request->int('version');
        $version = $versionId > 0 ? $this->drafts->version($versionId) : ($this->drafts->draft((int) $row['id']) ?? $this->drafts->published((int) $row['id']));
        if ($version === null || (int) $version['floor_id'] !== (int) $row['id']) {
            throw new NotFoundException('Nothing to preview on this floor yet.');
        }
        $filters = $explorer->filters($request->all());
        $config = $explorer->floorConfig($row, $filters, false, null, [
            'preview' => true,
            'floorUrl' => url('staff.layout.preview', ['floor' => '__FLOOR__']),
            'buildingUrl' => url('staff.layout.building'),
        ], (int) $version['id']);
        return $this->view('staff/layout/preview', [
            'title' => 'Preview · ' . $row['name'],
            'floor' => $row,
            'version' => $version,
            'config' => $config,
        ]);
    }

    public function history(string $floor): Response
    {
        $row = $this->floor($floor);
        return $this->view('staff/layout/history', [
            'title' => 'Version history · ' . $row['name'],
            'floor' => $row,
            'floors' => $this->maps->floors(),
            'versions' => $this->drafts->history((int) $row['id']),
            'hasDraft' => $this->drafts->draft((int) $row['id']) !== null,
        ]);
    }

    public function restore(string $floor, int $version): Response
    {
        $row = $this->floor($floor);
        try {
            $this->drafts->createDraft((int) $row['id'], (int) App::guard('staff')->id(), $version);
        } catch (LayoutException $e) {
            return redirect(url('staff.layout.history', ['floor' => (string) $row['slug']]))->with('error', $e->getMessage());
        }
        return redirect(url('staff.layout.floor', ['floor' => (string) $row['slug']]))->with('success', 'A new draft was created from that version. Review it and publish when ready.');
    }

    // ------------------------------------------------------------------ rates

    public function rates(Request $request, RateService $rates): Response
    {
        $categories = $this->presenter->categories();
        $history = [];
        foreach ($categories as $c) {
            $history[$c['id']] = $rates->history(RateScope::Category, (int) $c['id']);
        }
        $overrides = db()->select(
            "SELECT r.*, u.name AS created_by_name,
                    COALESCE((SELECT s.code FROM seats s JOIN zones z ON z.id = s.zone_id JOIN layout_versions lv ON lv.id = z.layout_version_id
                              WHERE r.scope = 'seat' AND s.seat_key = r.scope_id ORDER BY lv.status = 'published' DESC, lv.version_no DESC LIMIT 1),
                             (SELECT z.name FROM zones z JOIN layout_versions lv ON lv.id = z.layout_version_id
                              WHERE r.scope = 'zone' AND z.zone_key = r.scope_id ORDER BY lv.status = 'published' DESC, lv.version_no DESC LIMIT 1)) AS target
             FROM rates r LEFT JOIN staff_users u ON u.id = r.created_by
             WHERE r.scope IN ('seat', 'zone') AND (r.effective_to IS NULL OR r.effective_to >= ?)
             ORDER BY r.scope, target, r.unit, r.effective_from",
            [date('Y-m-d')],
        );
        return $this->view('staff/layout/rates', [
            'title' => 'Rates',
            'floors' => $this->maps->floors(),
            'categories' => $categories,
            'history' => $history,
            'overrides' => $overrides,
            'open' => $request->int('category'),
        ]);
    }

    public function storeRate(Request $request, RateService $rates): Response
    {
        $data = $this->validate($request, [
            'category_id' => 'required|integer|exists:seat_categories,id',
            'unit' => ['required', BillingUnit::rule()],
            'amount' => 'required|numeric|min:1|max:10000000',
            'gst_rate' => 'required|numeric|min:0|max:28',
            'effective_from' => 'required|date_format:Y-m-d|after_or_equal:today',
            'note' => 'nullable|string|max:255',
        ], [], ['effective_from' => 'effective-from date', 'gst_rate' => 'GST rate']);
        $code = (string) db()->scalar('SELECT code FROM seat_categories WHERE id = ?', [(int) $data['category_id']]);
        $cat = SeatCategory::from($code);
        $unit = BillingUnit::from((string) $data['unit']);
        if (!in_array($unit, $cat->billingUnits(), true)) {
            return redirect(url('staff.layout.rates', ['category' => (int) $data['category_id']]))->with('error', sprintf('%s is not billed per %s.', $cat->shortLabel(), $unit->value));
        }
        try {
            $rates->set(RateScope::Category, [(int) $data['category_id']], $unit, (float) $data['amount'], (float) $data['gst_rate'], (string) $data['effective_from'], (int) App::guard('staff')->id(), $data['note'] ?? null);
        } catch (LayoutException $e) {
            return redirect(url('staff.layout.rates', ['category' => (int) $data['category_id']]))->with('error', $e->getMessage());
        }
        return redirect(url('staff.layout.rates', ['category' => (int) $data['category_id']]))
            ->with('success', sprintf('%s rate of %s per %s set from %s.', $cat->shortLabel(), money((float) $data['amount']), $unit->value, format_date((string) $data['effective_from'])));
    }

    // ------------------------------------------------------------------ building

    public function building(BuildingService $building): Response
    {
        return $this->view('staff/layout/building', [
            'title' => 'Building & floors',
            'building' => $building->building(),
            'floorRows' => $building->floors(),
            'floors' => $this->maps->floors(),
        ]);
    }

    public function buildingPhoto(Request $request, BuildingService $building): Response
    {
        $building->uploadBuildingPhoto($request->file('photo'));
        return redirect(url('staff.layout.building'))->with('success', 'Building photo updated. Check the floor hotspots still line up.');
    }

    public function floorPhoto(Request $request, int $floor, BuildingService $building): Response
    {
        $stored = $building->uploadFloorPhoto($floor, $request->file('photo'));
        $back = (string) $request->input('back', '');
        $to = str_starts_with($back, '/staff/layout/') ? url($back) : url('staff.layout.building');
        return redirect($to)->with('success', sprintf('Floor photo updated (%d × %d px). Seats keep their positions as percentages of the image.', $stored['width'], $stored['height']));
    }

    public function hotspots(Request $request, BuildingService $building): Response
    {
        try {
            $building->saveHotspots(is_array($request->input('floors')) ? array_values($request->input('floors')) : []);
        } catch (LayoutException $e) {
            return Response::json(['message' => $e->getMessage()], 422);
        }
        return Response::json(['ok' => true, 'floors' => $building->floors()]);
    }

    public function storeFloor(Request $request, BuildingService $building): Response
    {
        $data = $this->validate($request, [
            'name' => 'required|string|max:100',
            'code' => ['required', 'regex:/^[A-Za-z0-9]{1,5}$/'],
            'level' => 'required|integer|min:-5|max:60',
        ], ['code.regex' => 'Use 1–5 letters or digits (the seat-code prefix, e.g. S for S-FX-01).'], ['code' => 'seat-code prefix']);
        $building->addFloor(['name' => (string) $data['name'], 'code' => (string) $data['code'], 'level' => (int) $data['level']]);
        return redirect(url('staff.layout.building'))->with('success', sprintf('%s added. Draw its hotspot, upload its plan and design its layout.', $data['name']));
    }

    public function destroyFloor(int $floor, BuildingService $building): Response
    {
        try {
            $building->removeFloor($floor);
        } catch (LayoutException $e) {
            return redirect(url('staff.layout.building'))->with('error', $e->getMessage());
        }
        return redirect(url('staff.layout.building'))->with('success', 'Floor removed.');
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, mixed> */
    private function floor(string $slug): array
    {
        return $this->maps->findFloor($slug) ?? throw new NotFoundException('Floor not found.');
    }

    private function role(): StaffRole
    {
        return StaffRole::from((string) (App::guard('staff')->user()['role'] ?? 'receptionist'));
    }
}
