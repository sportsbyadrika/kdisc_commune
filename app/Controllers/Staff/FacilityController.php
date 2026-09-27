<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Models\Facility;
use App\Services\Layout\FacilityService;

/** Facility master CRUD (spec 5.3) at /staff/facilities (can:facilities.manage). */
final class FacilityController extends Controller
{
    public function __construct(private readonly FacilityService $facilities)
    {
    }

    public function index(): Response
    {
        return $this->view('staff/facilities/index', ['title' => 'Facilities', 'facilities' => $this->facilities->all()]);
    }

    public function create(): Response
    {
        return $this->form(['kind' => 'addon', 'unit' => 'month', 'gst_rate' => setting('gst_rate', 18), 'is_active' => 1, 'icon' => 'package', 'price' => '', 'sort_order' => 50]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, $this->facilities->rules(), [], ['gst_rate' => 'GST rate', 'stock_qty' => 'stock']);
        $this->facilities->create($data);
        return redirect(url('staff.facilities.index'))->with('success', sprintf('%s added. Drag it onto a floor plan in the Layout designer.', $data['name']));
    }

    public function edit(int $id): Response
    {
        return $this->form(Facility::find($id) ?? throw new NotFoundException());
    }

    public function update(Request $request, int $id): Response
    {
        Facility::find($id) ?? throw new NotFoundException();
        $data = $this->validate($request, $this->facilities->rules($id), [], ['gst_rate' => 'GST rate', 'stock_qty' => 'stock']);
        $this->facilities->update($id, $data);
        return redirect(url('staff.facilities.index'))->with('success', sprintf('%s updated.', $data['name']));
    }

    public function destroy(int $id): Response
    {
        $f = Facility::find($id) ?? throw new NotFoundException();
        if ($this->facilities->delete($id)) {
            return redirect(url('staff.facilities.index'))->with('success', sprintf('%s deleted.', $f['name']));
        }
        $this->facilities->setActive($id, false);
        return redirect(url('staff.facilities.index'))->with('warning', sprintf('%s has been booked or is on a published plan, so it was deactivated instead of deleted (history keeps it).', $f['name']));
    }

    public function toggle(int $id): Response
    {
        $f = Facility::find($id) ?? throw new NotFoundException();
        $this->facilities->setActive($id, !(bool) $f['is_active']);
        return redirect(url('staff.facilities.index'))->with('success', sprintf('%s %s.', $f['name'], $f['is_active'] ? 'deactivated — hidden from maps and add-ons' : 'activated'));
    }

    /** @param array<string, mixed> $facility */
    private function form(array $facility): Response
    {
        return $this->view('staff/facilities/form', [
            'title' => isset($facility['id']) ? 'Edit facility' : 'New facility',
            'facility' => $facility,
            'icons' => FacilityService::icons(),
        ]);
    }
}
