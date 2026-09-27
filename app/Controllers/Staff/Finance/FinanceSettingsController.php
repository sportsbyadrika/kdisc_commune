<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\NumberSequence;
use App\Services\Finance\FinancialYear;
use App\Services\Pdf\PdfService;
use App\Support\Clock;
use App\Support\IndianStates;

/** Supplier, numbering, bank and signatory settings for finance documents (/staff/finance/settings). */
final class FinanceSettingsController extends Controller
{
    public function __construct(private readonly FinanceSettings $settings, private readonly Clock $clock)
    {
    }

    public function edit(): Response
    {
        $fy = FinancialYear::of($this->clock->today());
        $next = [];
        foreach ([NumberSequence::INVOICE, NumberSequence::RECEIPT, NumberSequence::CREDIT_NOTE, NumberSequence::REFUND_VOUCHER] as $name) {
            $last = (int) db()->scalar('SELECT last_value FROM number_sequences WHERE name = ? AND period = ?', [$name, $fy]);
            $next[$name] = NumberSequence::format($name, $fy, $last + 1);
        }
        $values = $this->settings->values();
        $images = [];
        foreach (array_keys(FinanceSettings::IMAGES) as $key) {
            $images[$key] = PdfService::imageDataUri($this->settings->imagePath((string) $values[$key]), 240);
        }
        return $this->view('staff/finance/settings', [
            'title' => 'Finance settings',
            'values' => $values,
            'states' => IndianStates::options(),
            'next' => $next,
            'fy' => $fy,
            'images' => $images,
        ]);
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, FinanceSettings::rules(), [], array_map(static fn (array $f) => strtolower($f[0]), FinanceSettings::FIELDS));
        $data['finance_email_documents'] = $request->bool('finance_email_documents');
        $uploads = [];
        foreach (array_keys(FinanceSettings::IMAGES) as $key) {
            $uploads[$key] = $request->file($key);
        }
        $this->settings->save($data, $uploads, array_values(array_intersect(array_keys(FinanceSettings::IMAGES), (array) $request->input('remove', []))));
        return redirect(url('staff.finance.settings'))->with('success', 'Finance settings saved. New documents use them; issued documents keep the details they were issued with.');
    }
}
