<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\AuditTrail;

/** Audit log viewer (/staff/audit — Centre Manager, State Admin): filters + old/new JSON diff per entry. */
final class AuditController extends StaffController
{
    public function __construct(private readonly AuditTrail $trail)
    {
    }

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (['user', 'action', 'entity', 'entity_id', 'from', 'to', 'q'] as $k) {
            $filters[$k] = mb_substr(trim($request->string($k)), 0, 100);
        }
        return $this->view('staff/audit/index', [
            'title' => 'Audit log',
            'subtitle' => 'Every change to money, pricing, layout, KYC and bookings — who, when, what changed.',
            'filters' => $filters,
            'options' => $this->trail->options(),
            'result' => $this->trail->search($filters, max(1, $request->int('page', 1))),
        ]);
    }

    public function show(int $id): Response
    {
        $entry = $this->trail->find($id) ?? throw new NotFoundException();
        return $this->view('staff/audit/show', [
            'title' => 'Audit entry #' . $id,
            'entry' => $entry,
            'diff' => AuditTrail::diff($entry),
        ]);
    }
}
