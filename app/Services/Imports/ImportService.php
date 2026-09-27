<?php

declare(strict_types=1);

namespace App\Services\Imports;

use App\Core\Container;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Services\AuditLog;
use App\Services\Bookings\WorkflowException;
use App\Services\Layout\LayoutException;
use App\Services\Space\SpaceRuleException;
use App\Services\Visitors\VisitorRegistration;
use App\Support\Clock;
use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Throwable;

/**
 * XLSX bulk import (spec 10): upload → validate every row → masked preview → confirm → error report → history.
 *
 * Storage & privacy
 *   - the uploaded workbook is kept ONLY as storage/imports/tmp/{token}.xlsx (dir 0700, file 0600, random name) until
 *     the batch is confirmed, discarded or expires (setting import_expiry_minutes; `imports:cleanup` + lazily on the
 *     page) — then it is deleted and import_batches.file_path/token are cleared
 *   - the preview (storage/imports/tmp/{token}.json) and the error report (storage/imports/reports/{id}-errors.xlsx)
 *     hold DISPLAY values only: sensitive columns (Aadhaar) are masked XXXX XXXX 1234; validated data (which contains
 *     full Aadhaar numbers) lives only in memory during the request
 *   - confirm RE-READS and RE-VALIDATES the workbook against the current database (seats may have been booked since)
 *
 * Modes: per_row (each valid row in its own transaction; invalid / failing rows are reported) or all_or_nothing (one
 * transaction — any invalid row or failure imports nothing). Portal invites are sent after the commit.
 */
final class ImportService
{
    /** @var array<string, class-string<Importer>> */
    public const TYPES = [
        'individuals' => Types\IndividualsImporter::class,
        'institutions' => Types\InstitutionsImporter::class,
        'bookings' => Types\BookingsImporter::class,
        'payments' => Types\PaymentsImporter::class,
        'facilities' => Types\FacilitiesImporter::class,
        'rates' => Types\RatesImporter::class,
    ];

    public const XLSX_MIMES = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/octet-stream'];

    public function __construct(
        private readonly Database $db,
        private readonly Container $container,
        private readonly SpreadsheetReader $reader,
        private readonly TemplateBuilder $templates,
        private readonly VisitorRegistration $visitors,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
    ) {
    }

    /** @return array<string, Importer> */
    public function importers(): array
    {
        $out = [];
        foreach (self::TYPES as $key => $class) {
            /** @var Importer $imp */
            $imp = $this->container->get($class);
            $out[$key] = $imp;
        }
        return $out;
    }

    public function importer(string $type): Importer
    {
        $class = self::TYPES[$type] ?? throw new ImportException('Unknown import type.');
        /** @var Importer */
        return $this->container->get($class);
    }

    public static function root(string $sub = ''): string
    {
        return storage_path('imports' . ($sub !== '' ? '/' . $sub : ''));
    }

    public function templateBytes(string $type): string
    {
        return $this->write($this->templates->build($this->importer($type)));
    }

    // ------------------------------------------------------------------ upload + validate

    /**
     * Store the upload securely, validate every row and save the masked preview + error report.
     *
     * @param array<string, mixed>|null $upload $_FILES entry
     * @param array<string, mixed> $staff
     */
    public function upload(string $type, ?array $upload, array $staff, bool $trusted = false): int
    {
        $importer = $this->importer($type);
        $maxBytes = max(1, (int) setting('import_max_mb', 5)) * 1024 * 1024;
        $fail = static fn (string $m) => new ValidationException(['file' => [$m]]);
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw $fail(sprintf('The file is larger than %d MB.', $maxBytes / 1048576));
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || (!$trusted && !is_uploaded_file($tmp))) {
            throw $fail('Choose the .xlsx file to upload.');
        }
        $size = (int) filesize($tmp);
        $name = mb_substr(basename(str_replace('\\', '/', (string) ($upload['name'] ?? 'upload.xlsx'))), 0, 200);
        if ($size <= 0 || $size > $maxBytes) {
            throw $fail($size <= 0 ? 'The file is empty.' : sprintf('The file is larger than %d MB.', $maxBytes / 1048576));
        }
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw $fail('Only .xlsx workbooks are accepted — save the template as "Excel Workbook (.xlsx)".');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!in_array($mime, self::XLSX_MIMES, true) || (string) file_get_contents($tmp, false, null, 0, 2) !== 'PK') {
            throw $fail('That is not a valid .xlsx workbook.');
        }
        $bomb = \App\Services\Security\UploadGuard::zip($tmp);
        if ($bomb !== null) {
            throw $fail($bomb);
        }
        try {
            if (!(new XlsxReader())->canRead($tmp)) {
                throw $fail('That is not a valid .xlsx workbook.');
            }
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            throw $fail('That is not a valid .xlsx workbook.');
        }

        $dir = self::secureDir('tmp');
        $token = bin2hex(random_bytes(16));
        $dest = "{$dir}/{$token}.xlsx";
        if (!($trusted ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest))) {
            throw new \RuntimeException('Could not store the uploaded file.');
        }
        @chmod($dest, 0600);

        $expires = $this->clock->now()->modify('+' . max(5, (int) setting('import_expiry_minutes', 120)) . ' minutes')->format('Y-m-d H:i:s');
        $id = $this->db->insert('import_batches', [
            'type' => $type, 'original_name' => $name, 'file_path' => "tmp/{$token}.xlsx", 'token' => $token, 'file_size' => $size,
            'status' => 'uploaded', 'created_by' => (int) $staff['id'], 'expires_at' => $expires,
        ]);
        try {
            $result = $this->evaluate($importer, $dest, new ImportContext($staff, $id, $this->clock->today()));
        } catch (ImportException $e) {
            $this->deleteTemp($token);
            $this->db->update('import_batches', ['status' => 'failed', 'file_path' => null, 'token' => null, 'summary' => json_encode(['message' => $e->getMessage()])], ['id' => $id]);
            throw $fail($e->getMessage());
        }
        $valid = count(array_filter($result['rows'], static fn (array $r) => $r['errors'] === []));
        $preview = ['type' => $type, 'rows' => array_map(static fn (array $r) => array_diff_key($r, ['data' => 1]), $result['rows']),
            'unknown' => $result['unknown'], 'truncated' => $result['truncated'], 'max_rows' => (int) setting('import_max_rows', 1000)];
        $json = "{$dir}/{$token}.json";
        file_put_contents($json, (string) json_encode($preview, JSON_UNESCAPED_UNICODE));
        @chmod($json, 0600);
        $report = $this->errorReport($id, $importer, $result['rows']);
        $this->db->update('import_batches', [
            'status' => 'validated', 'total_rows' => count($result['rows']), 'valid_rows' => $valid, 'error_rows' => count($result['rows']) - $valid,
            'error_report_path' => $report,
        ], ['id' => $id]);
        $this->audit->record('import.upload', 'import_batch', $id, null, ['type' => $type, 'file' => $name, 'rows' => count($result['rows']), 'valid' => $valid]);
        return $id;
    }

    /**
     * Read + validate every row. Returns display values (masked) per row, errors, the preview note and — in memory
     * only — the validated data.
     *
     * @return array{rows: list<array{row: int, values: array<string, string>, errors: array<string, string>, note: string, data: array<string, mixed>}>, unknown: list<string>, truncated: bool}
     */
    private function evaluate(Importer $importer, string $path, ImportContext $ctx): array
    {
        $cols = $importer->columns();
        $read = $this->reader->read($path, $cols, max(1, (int) setting('import_max_rows', 1000)));
        if ($read['missing'] !== []) {
            throw new ImportException('Missing required column(s): ' . implode(', ', $read['missing']) . '. Download the template for this import type.');
        }
        if ($read['rows'] === []) {
            throw new ImportException('The sheet has no data rows (row 2 onwards, the unchanged example row is ignored).');
        }
        $out = [];
        foreach ($read['rows'] as $row) {
            $n = (int) $row['_row'];
            $missing = [];
            foreach ($cols as $c) {
                if ($c->required && ($row[$c->key] ?? '') === '') {
                    $missing[$c->key] = 'Required.';
                }
            }
            try {
                $v = $importer->validate($row, $ctx);
            } catch (Throwable $e) {
                logger()->warning('Import row {row} validation crashed: {e}', ['row' => $n, 'e' => $e->getMessage()]);
                $v = ['data' => [], 'errors' => ['_' => 'This row could not be checked.'], 'note' => ''];
            }
            $values = [];
            foreach ($cols as $c) {
                $values[$c->key] = $importer->display($c, (string) ($row[$c->key] ?? ''));
            }
            $out[] = ['row' => $n, 'values' => $values, 'errors' => $missing + $v['errors'], 'note' => $v['note'], 'data' => $v['data']];
        }
        return ['rows' => $out, 'unknown' => $read['unknown'], 'truncated' => $read['truncated']];
    }

    // ------------------------------------------------------------------ preview / confirm / discard

    /** @return array<string, mixed>|null */
    public function batch(int $id): ?array
    {
        return $this->db->first('SELECT b.*, s.name AS created_by_name, i.name AS imported_by_name FROM import_batches b
            LEFT JOIN staff_users s ON s.id = b.created_by LEFT JOIN staff_users i ON i.id = b.imported_by WHERE b.id = ?', [$id]);
    }

    /**
     * Masked preview of a validated batch (null once imported / expired).
     *
     * @param array<string, mixed> $batch
     * @return array<string, mixed>|null
     */
    public function preview(array $batch): ?array
    {
        if ($batch['token'] === null) {
            return null;
        }
        $file = self::root("tmp/{$batch['token']}.json");
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Import the valid rows through the services.
     *
     * @param array<string, mixed> $staff
     * @return array{imported: int, failed: int, skipped: int, invites: int, status: string, message: string}
     */
    public function confirm(int $id, array $staff, string $mode, bool $sendInvites): array
    {
        $batch = $this->batch($id) ?? throw new ImportException('Import not found.');
        if ($batch['status'] !== 'validated') {
            throw new ImportException('This upload has already been ' . ($batch['status'] === 'expired' ? 'deleted (it expired)' : $batch['status']) . '.');
        }
        if ($this->expired($batch)) {
            $this->expire($batch);
            throw new ImportException('This upload expired — please upload the file again.');
        }
        $path = self::root((string) $batch['file_path']);
        if (!is_file($path)) {
            throw new ImportException('The uploaded file is no longer available — please upload it again.');
        }
        $mode = $mode === 'all_or_nothing' ? 'all_or_nothing' : 'per_row';
        $importer = $this->importer((string) $batch['type']);
        $ctx = new ImportContext($staff, $id, $this->clock->today(), $sendInvites && $importer->invites());
        $result = $this->evaluate($importer, $path, $ctx); // fresh re-validation against the current database
        $ctx = new ImportContext($staff, $id, $this->clock->today(), $ctx->sendInvites); // clean state for the import phase
        $rows = $result['rows'];
        $valid = array_values(array_filter($rows, static fn (array $r) => $r['errors'] === []));
        $refs = [];
        $failures = [];
        $message = '';

        if ($mode === 'all_or_nothing') {
            if (count($valid) !== count($rows)) {
                $message = sprintf('Nothing imported: %d row(s) have errors and "all or nothing" was chosen.', count($rows) - count($valid));
            } else {
                try {
                    $this->db->transaction(function () use ($valid, $importer, $ctx, &$refs, &$failures): void {
                        foreach ($valid as $r) {
                            try {
                                $refs[$r['row']] = $importer->import($r['data'], $ctx);
                            } catch (Throwable $e) {
                                $failures[$r['row']] = self::reason($e);
                                throw $e;
                            }
                        }
                    });
                } catch (Throwable) {
                    $refs = [];
                    $ctx->state['created_customers'] = [];
                    $message = 'Nothing imported: a row failed during import, so every row was rolled back.';
                }
            }
        } else {
            foreach ($valid as $r) {
                $before = count($ctx->state['created_customers'] ?? []);
                try {
                    $refs[$r['row']] = $this->db->transaction(fn () => $importer->import($r['data'], $ctx));
                } catch (Throwable $e) {
                    $failures[$r['row']] = self::reason($e);
                    $ctx->state['created_customers'] = array_slice($ctx->state['created_customers'] ?? [], 0, $before);
                }
            }
        }

        $invites = 0;
        if ($ctx->sendInvites) {
            foreach ($ctx->state['created_customers'] ?? [] as $customerId) {
                $invites += $this->visitors->invite((int) $customerId, (int) $staff['id']) ? 1 : 0;
            }
        }

        // error report: invalid rows + rows that failed during import
        foreach ($rows as &$r) {
            if (isset($failures[$r['row']])) {
                $r['errors']['_'] = $failures[$r['row']];
            } elseif ($r['errors'] === [] && !isset($refs[$r['row']]) && $mode === 'all_or_nothing') {
                $r['errors']['_'] = 'Not imported (all or nothing).';
            }
        }
        unset($r);
        $report = $this->errorReport($id, $importer, $rows);
        $imported = count($refs);
        $status = $imported === 0 ? 'failed' : ($imported === count($rows) ? 'imported' : 'partial');
        $this->db->update('import_batches', [
            'status' => $status, 'mode' => $mode, 'send_invites' => $ctx->sendInvites ? 1 : 0, 'invites_sent' => $invites,
            'total_rows' => count($rows), 'valid_rows' => count($valid), 'error_rows' => count($rows) - count($valid),
            'imported_rows' => $imported, 'failed_rows' => count($failures),
            'summary' => json_encode(['refs' => $refs, 'failures' => $failures, 'message' => $message], JSON_UNESCAPED_UNICODE),
            'error_report_path' => $report, 'imported_at' => $this->clock->sql(), 'imported_by' => (int) $staff['id'],
        ], ['id' => $id]);
        $this->deleteTemp((string) $batch['token']);
        $this->audit->record('import.confirm', 'import_batch', $id, null, ['type' => $batch['type'], 'mode' => $mode, 'imported' => $imported, 'failed' => count($failures), 'invalid' => count($rows) - count($valid), 'invites' => $invites]);
        logger()->info('Import #{id} ({type}): {n} imported, {f} failed', ['id' => $id, 'type' => $batch['type'], 'n' => $imported, 'f' => count($failures)]);
        return ['imported' => $imported, 'failed' => count($failures), 'skipped' => count($rows) - count($valid), 'invites' => $invites, 'status' => $status, 'message' => $message];
    }

    public function discard(int $id): void
    {
        $batch = $this->batch($id) ?? throw new ImportException('Import not found.');
        if ($batch['status'] !== 'validated') {
            throw new ImportException('Only an upload awaiting confirmation can be discarded.');
        }
        $this->deleteTemp((string) $batch['token']);
        $this->db->update('import_batches', ['status' => 'discarded'], ['id' => $id]);
        $this->audit->record('import.discard', 'import_batch', $id);
    }

    /** @param array<string, mixed> $batch */
    public function expired(array $batch): bool
    {
        return $batch['status'] === 'validated' && $batch['expires_at'] !== null && (string) $batch['expires_at'] < $this->clock->sql();
    }

    /** @param array<string, mixed> $batch */
    private function expire(array $batch): void
    {
        $this->deleteTemp((string) $batch['token']);
        $this->db->update('import_batches', ['status' => 'expired'], ['id' => (int) $batch['id']]);
    }

    /** Delete temp files of unconfirmed uploads past their expiry (and orphans older than a day). */
    public function cleanupExpired(): int
    {
        $n = 0;
        foreach ($this->db->select("SELECT * FROM import_batches WHERE status IN ('validated', 'uploaded') AND expires_at < ?", [$this->clock->sql()]) as $b) {
            $this->expire($b);
            $n++;
        }
        $known = array_flip(array_map('strval', $this->db->column('SELECT token FROM import_batches WHERE token IS NOT NULL')));
        foreach (glob(self::root('tmp') . '/*') ?: [] as $f) {
            $token = pathinfo($f, PATHINFO_FILENAME);
            if (!isset($known[$token]) && filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        return $n;
    }

    /** @return list<array<string, mixed>> */
    public function history(int $limit = 30): array
    {
        return $this->db->select('SELECT b.*, s.name AS created_by_name FROM import_batches b LEFT JOIN staff_users s ON s.id = b.created_by ORDER BY b.id DESC LIMIT ' . max(1, $limit));
    }

    /** @param array<string, mixed> $batch */
    public function errorReportFile(array $batch): ?string
    {
        $p = (string) ($batch['error_report_path'] ?? '');
        return $p !== '' && is_file(self::root($p)) ? self::root($p) : null;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Error report: the rows that were not imported, in template column order (so the file can be fixed and uploaded
     * again), then "Source row" and "Error". Cells with an error and the Error column are filled red. Masked values.
     *
     * @param list<array{row: int, values: array<string, string>, errors: array<string, string>, note: string}> $rows
     */
    private function errorReport(int $batchId, Importer $importer, array $rows): ?string
    {
        $bad = array_values(array_filter($rows, static fn (array $r) => $r['errors'] !== []));
        $rel = "reports/{$batchId}-errors.xlsx";
        if ($bad === []) {
            @unlink(self::root($rel));
            return null;
        }
        $cols = $importer->columns();
        $book = new Spreadsheet();
        $ws = $book->getActiveSheet();
        $ws->setTitle('Errors');
        $n = count($cols);
        $rowCol = Coordinate::stringFromColumnIndex($n + 1);
        $errCol = Coordinate::stringFromColumnIndex($n + 2);
        foreach ($cols as $i => $c) {
            $col = Coordinate::stringFromColumnIndex($i + 1);
            $ws->setCellValueExplicit($col . '1', $c->label(), DataType::TYPE_STRING);
            $ws->getColumnDimension($col)->setWidth(max($c->width, mb_strlen($c->label()) + 3));
        }
        $ws->setCellValue($rowCol . '1', 'Source row');
        $ws->setCellValue($errCol . '1', 'Error');
        $ws->getColumnDimension($rowCol)->setWidth(11);
        $ws->getColumnDimension($errCol)->setWidth(70);
        $hs = $ws->getStyle("A1:{$errCol}1");
        $hs->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $hs->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB(ltrim((string) config('mail.theme.brand', '#1d4ed8'), '#'));
        $ws->getStyle("{$errCol}1")->getFill()->getStartColor()->setRGB('B91C1C');
        foreach ($bad as $k => $r) {
            $line = $k + 2;
            $msgs = [];
            foreach ($cols as $i => $c) {
                $cell = Coordinate::stringFromColumnIndex($i + 1) . $line;
                $ws->setCellValueExplicit($cell, (string) ($r['values'][$c->key] ?? ''), DataType::TYPE_STRING);
                if (isset($r['errors'][$c->key])) {
                    $ws->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FECACA');
                    $msgs[] = $c->header . ': ' . $r['errors'][$c->key];
                }
            }
            foreach ($r['errors'] as $key => $m) {
                if ($importer->column((string) $key) === null) {
                    $msgs[] = $m;
                }
            }
            $ws->setCellValueExplicit($rowCol . $line, (string) $r['row'], DataType::TYPE_NUMERIC);
            $ws->setCellValueExplicit($errCol . $line, implode(' | ', $msgs), DataType::TYPE_STRING);
            $es = $ws->getStyle($errCol . $line);
            $es->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FEE2E2');
            $es->getFont()->getColor()->setRGB('991B1B');
            $es->getAlignment()->setWrapText(true);
        }
        $ws->freezePane('A2');
        $ws->setAutoFilter("A1:{$errCol}" . (count($bad) + 1));
        self::secureDir('reports');
        $path = self::root($rel);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        @chmod($path, 0600);
        return $rel;
    }

    /** User-facing message for a failed row import. */
    private static function reason(Throwable $e): string
    {
        return match (true) {
            $e instanceof ValidationException => (string) (array_values($e->errors())[0][0] ?? 'Invalid data.'),
            $e instanceof SpaceRuleException, $e instanceof WorkflowException, $e instanceof LayoutException, $e instanceof ImportException,
            $e instanceof \App\Services\Finance\FinanceException, $e instanceof \RuntimeException && !$e instanceof \PDOException => $e->getMessage(),
            default => (static function () use ($e): string {
                logger()->error('Import row failed: {e}', ['e' => $e->getMessage()]);
                return 'Could not be imported (unexpected error — see the application log).';
            })(),
        };
    }

    private static function secureDir(string $sub): string
    {
        $dir = self::root($sub);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the import directory.');
        }
        @chmod(self::root(), 0700);
        @chmod($dir, 0700);
        return $dir;
    }

    private function deleteTemp(string $token): void
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            return;
        }
        foreach (['xlsx', 'json'] as $ext) {
            $f = self::root("tmp/{$token}.{$ext}");
            if (is_file($f)) {
                @unlink($f);
            }
        }
        $this->db->execute('UPDATE import_batches SET file_path = NULL, token = NULL WHERE token = ?', [$token]);
    }

    private function write(Spreadsheet $book): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tpl');
        if ($tmp === false) {
            throw new \RuntimeException('Cannot create a temporary file.');
        }
        try {
            (new Xlsx($book))->save($tmp);
            return (string) file_get_contents($tmp);
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }
    }

    /**
     * Minutes left before an unconfirmed upload is deleted.
     *
     * @param array<string, mixed> $batch
     */
    public function minutesLeft(array $batch): int
    {
        if ($batch['expires_at'] === null) {
            return 0;
        }
        return max(0, (int) floor(((new DateTimeImmutable((string) $batch['expires_at']))->getTimestamp() - $this->clock->now()->getTimestamp()) / 60));
    }
}
