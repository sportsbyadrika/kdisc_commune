<?php

declare(strict_types=1);

namespace App\Controllers\Staff\Finance;

use App\Controllers\Controller;
use App\Core\Exceptions\NotFoundException;
use App\Core\Response;
use App\Services\AuditLog;
use App\Services\Finance\FinanceDocuments;

/**
 * Staff downloads of issued finance PDFs (stored original) and reprints (DUPLICATE COPY watermark).
 * URLs: /staff/finance/documents/{type}/{id}/{slug}.pdf — the slug must match the document number.
 */
final class FinanceDocumentController extends Controller
{
    public function __construct(private readonly FinanceDocuments $docs, private readonly AuditLog $audit)
    {
    }

    public function pdf(string $type, int $id, string $slug): Response
    {
        [$t, $row] = $this->resolve($type, $id, $slug);
        $doc = $this->docs->pdf($t, $row);
        $this->audit->record($t . '.download', $t, $id);
        return self::inline($doc['bytes'], $doc['filename']);
    }

    public function reprint(string $type, int $id): Response
    {
        $t = FinanceDocuments::fromUrlType($type);
        $row = $this->docs->find($t, $id) ?? throw new NotFoundException();
        $doc = $this->docs->pdf($t, $row, true, (int) (staff()['id'] ?? 0) ?: null);
        return self::inline($doc['bytes'], $doc['filename']);
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private function resolve(string $type, int $id, string $slug): array
    {
        $t = FinanceDocuments::fromUrlType($type);
        $row = $this->docs->find($t, $id) ?? throw new NotFoundException();
        if (!hash_equals(FinanceDocuments::slug($t, $row), $slug)) {
            throw new NotFoundException();
        }
        return [$t, $row];
    }

    public static function inline(string $bytes, string $filename): Response
    {
        $safe = str_replace(['"', "\r", "\n"], '', $filename);
        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('inline; filename="%s"', $safe),
            'Content-Length' => (string) strlen($bytes),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; img-src data:; frame-ancestors 'self'",
        ]);
    }
}
