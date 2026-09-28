<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Imports\ImportException;
use App\Services\Imports\ImportService;
use App\Services\Reports\Export\ReportExporter;

/**
 * Bulk upload (/staff/imports, Centre Manager — imports.manage): templates, upload → masked preview → confirm,
 * error report, history. All rules live in Services\Imports (ImportService + one Importer per type).
 */
final class ImportController extends StaffController
{
    public function __construct(private readonly ImportService $imports)
    {
    }

    public function index(): Response
    {
        $this->imports->cleanupExpired();
        return $this->view('staff/imports/index', [
            'title' => 'Bulk upload',
            'subtitle' => 'Download a template, fill it in Excel, upload it — every row is checked with the same rules as the forms before anything is saved.',
            'importers' => $this->imports->importers(),
            'history' => $this->imports->history(30),
            'maxMb' => (int) setting('import_max_mb', 5),
            'maxRows' => (int) setting('import_max_rows', 1000),
        ]);
    }

    public function template(string $type): Response
    {
        if (!isset(ImportService::TYPES[$type])) {
            throw new NotFoundException();
        }
        return ReportExporter::attachment($this->imports->templateBytes($type), "commune-{$type}-template.xlsx", ReportExporter::XLSX_MIME);
    }

    public function upload(Request $request): Response
    {
        $data = $this->validate($request, ['type' => 'required|in:' . implode(',', array_keys(ImportService::TYPES))], [], ['type' => 'import type']);
        $id = $this->imports->upload((string) $data['type'], $request->file('file'), $this->user());
        return redirect(url('staff.imports.show', ['id' => $id]));
    }

    public function show(Request $request, int $id): Response
    {
        $batch = $this->imports->batch($id) ?? throw new NotFoundException();
        if ($this->imports->expired($batch)) {
            $this->imports->cleanupExpired();
            $batch = (array) $this->imports->batch($id);
        }
        $importer = $this->imports->importer((string) $batch['type']);
        $preview = $batch['status'] === 'validated' ? $this->imports->preview($batch) : null;
        $tab = in_array($request->string('tab'), ['all', 'valid', 'errors'], true) ? $request->string('tab') : ((int) $batch['error_rows'] > 0 ? 'errors' : 'all');
        $rows = $preview['rows'] ?? [];
        $shown = array_values(array_filter($rows, static fn (array $r) => $tab === 'all' || ($tab === 'errors') === ($r['errors'] !== [])));
        return $this->view('staff/imports/show', [
            'title' => $importer->label() . ' import #' . $id,
            'batch' => $batch,
            'importer' => $importer,
            'preview' => $preview,
            'rows' => array_slice($shown, 0, 500),
            'shownTotal' => count($shown),
            'tab' => $tab,
            'summary' => json_decode((string) ($batch['summary'] ?? ''), true) ?: [],
            'minutesLeft' => $this->imports->minutesLeft($batch),
            'hasReport' => $this->imports->errorReportFile($batch) !== null,
        ]);
    }

    public function confirm(Request $request, int $id): Response
    {
        try {
            $r = $this->imports->confirm($id, $this->user(), $request->string('mode'), $request->bool('send_invites'));
        } catch (ImportException $e) {
            return redirect(url('staff.imports.show', ['id' => $id]))->with('error', $e->getMessage());
        }
        $msg = $r['message'] !== '' ? $r['message'] : sprintf(
            '%d row%s imported%s%s%s.',
            $r['imported'], $r['imported'] === 1 ? '' : 's',
            $r['skipped'] > 0 ? ", {$r['skipped']} skipped (errors)" : '',
            $r['failed'] > 0 ? ", {$r['failed']} failed during import" : '',
            $r['invites'] > 0 ? ", {$r['invites']} portal invite" . ($r['invites'] === 1 ? '' : 's') . ' sent' : '',
        );
        return redirect(url('staff.imports.show', ['id' => $id]))->with($r['status'] === 'imported' ? 'success' : ($r['imported'] > 0 ? 'warning' : 'error'), $msg);
    }

    public function discard(int $id): Response
    {
        try {
            $this->imports->discard($id);
        } catch (ImportException $e) {
            return redirect(url('staff.imports.show', ['id' => $id]))->with('error', $e->getMessage());
        }
        return redirect(url('staff.imports.index'))->with('info', "Upload #{$id} discarded — the file was deleted.");
    }

    public function errors(int $id): Response
    {
        $batch = $this->imports->batch($id) ?? throw new NotFoundException();
        $file = $this->imports->errorReportFile($batch) ?? throw new NotFoundException('No error report for this upload.');
        return ReportExporter::attachment((string) file_get_contents($file), sprintf('import-%d-%s-errors.xlsx', $id, $batch['type']), ReportExporter::XLSX_MIME);
    }
}
