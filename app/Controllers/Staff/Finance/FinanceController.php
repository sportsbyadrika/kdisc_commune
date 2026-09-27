<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Finance\FinanceOverview;
use App\Services\Finance\FinancialYear;
use App\Support\Clock;

/** Finance dashboard (/staff/finance) — the same overview Finance Admins see on /staff/dashboard. */
final class FinanceController extends Controller
{
    public function __construct(private readonly FinanceOverview $overview, private readonly Clock $clock)
    {
    }

    public function index(Request $request): Response
    {
        $fy = FinancialYear::valid($request->string('fy')) ? $request->string('fy') : FinancialYear::of($this->clock->today());
        return $this->view('staff/finance/dashboard', ['title' => 'Finance', 'finance' => $this->overview->build($fy)]);
    }
}
