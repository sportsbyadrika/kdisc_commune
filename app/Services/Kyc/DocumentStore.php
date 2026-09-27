<?php

declare(strict_types=1);

namespace App\Services\Kyc;

use App\Core\App;
use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Models\CustomerDocument;
use App\Services\AuditLog;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Secure KYC document storage (spec 12 "Uploads").
 *
 *  - Accepts pdf, jpg/jpeg, png, webp up to 5 MB. The MIME type is sniffed with finfo (the browser's
 *    Content-Type is ignored) and must agree with the extension; PDFs must start with "%PDF-".
 *  - Files get random names under storage/uploads/kyc/{customer_id}/ — never inside public/.
 *  - Images are decoded and re-encoded with intervention/image (GD): EXIF/GPS metadata is stripped and
 *    anything that is not really an image fails. Oversized photos are scaled down to 2400 px.
 *  - One current file per document type: storing a type again replaces (and deletes) the old file.
 *  - Files are only ever streamed by authorised controllers (Portal\DocumentController,
 *    Staff\DocumentController) via Response::file().
 *
 *   $doc = $store->store($customerId, DocumentType::Pan, $request->file('file'), HolderType::Account, $accountId);
 */
final class DocumentStore
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    /** sniffed MIME => allowed extensions (first = canonical) */
    public const ALLOWED = [
        'application/pdf' => ['pdf'],
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => ['png'],
        'image/webp' => ['webp'],
    ];

    /**
     * CSP for streamed documents: nothing may execute, but Chrome's built-in PDF viewer (a plugin) needs
     * object-src, which the site-wide policy sets to 'none'. Framing is limited to our own pages.
     */
    public const FILE_CSP = "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'self'";

    private const MAX_IMAGE_EDGE = 2400;

    public function __construct(private readonly Database $db, private readonly AuditLog $audit)
    {
    }

    /** "pdf, jpg, jpeg, png, webp" — for help texts. */
    public static function extensionsList(): string
    {
        return implode(', ', array_merge(...array_values(self::ALLOWED)));
    }

    /** accept="" attribute for file inputs. */
    public static function acceptAttribute(): string
    {
        return implode(',', array_merge(array_keys(self::ALLOWED), array_map(static fn ($e) => '.' . $e, array_merge(...array_values(self::ALLOWED)))));
    }

    public static function root(): string
    {
        return rtrim((string) (App::config('app.uploads_path') ?: App::basePath('storage/uploads')), '/');
    }

    /**
     * Validate and persist an uploaded file ($_FILES entry). Throws ValidationException on bad input
     * (error key = $field).
     *
     * @param array<string, mixed> $upload
     * @param bool $trusted true skips is_uploaded_file() (tests / console imports only)
     * @return array<string, mixed> the new customer_documents row
     */
    public function store(
        int $customerId,
        DocumentType $type,
        array $upload,
        HolderType $byType,
        ?int $byId,
        string $field = 'file',
        bool $trusted = false,
    ): array {
        $tmp = (string) ($upload['tmp_name'] ?? '');
        $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        $fail = static fn (string $msg): ValidationException => new ValidationException([$field => [$msg]]);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw $fail('The file is larger than 5 MB.');
        }
        if ($error !== UPLOAD_ERR_OK || $tmp === '' || !is_file($tmp) || (!$trusted && !is_uploaded_file($tmp))) {
            throw $fail('The upload failed. Please choose the file again.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0) {
            throw $fail('The file is empty.');
        }
        if ($size > self::MAX_BYTES) {
            throw $fail('The file is larger than 5 MB.');
        }
        $originalName = self::cleanName((string) ($upload['name'] ?? 'document'));
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::ALLOWED[$mime])) {
            throw $fail('Only PDF, JPG, PNG or WebP files are allowed.');
        }
        if ($ext === '' && str_starts_with($originalName, 'capture')) {
            $ext = self::ALLOWED[$mime][0]; // webcam/camera blobs may arrive without an extension
        }
        if (!in_array($ext, self::ALLOWED[$mime], true)) {
            throw $fail('The file extension does not match its content. Allowed: ' . self::extensionsList() . '.');
        }
        if ($mime === 'application/pdf' && (string) file_get_contents($tmp, false, null, 0, 5) !== '%PDF-') {
            throw $fail('The PDF appears to be damaged.');
        }

        $dir = self::root() . '/kyc/' . $customerId;
        if (!is_dir($dir) && !mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create upload directory.');
        }
        $canonical = self::ALLOWED[$mime][0];
        $name = bin2hex(random_bytes(16)) . '.' . $canonical;
        $dest = $dir . '/' . $name;

        if ($mime === 'application/pdf') {
            $moved = $trusted ? copy($tmp, $dest) : move_uploaded_file($tmp, $dest);
            if (!$moved) {
                throw new \RuntimeException('Could not store the uploaded file.');
            }
        } else {
            try {
                $this->reencode($tmp, $dest, $mime);
            } catch (Throwable $e) {
                @unlink($dest);
                logger()->warning('Rejected image upload: {error}', ['error' => $e->getMessage()]);
                throw $fail('The image could not be read. Please upload a clear JPG, PNG or WebP photo.');
            }
        }
        @chmod($dest, 0640);

        $relative = 'kyc/' . $customerId . '/' . $name;
        return $this->db->transaction(function (Database $db) use ($customerId, $type, $originalName, $relative, $mime, $dest, $byType, $byId): array {
            $old = $db->select('SELECT * FROM customer_documents WHERE customer_id = ? AND doc_type = ?', [$customerId, $type->value]);
            $id = $db->insert('customer_documents', [
                'customer_id' => $customerId,
                'doc_type' => $type->value,
                'original_name' => mb_substr($originalName, 0, 255),
                'file_path' => $relative,
                'mime' => $mime,
                'size' => (int) filesize($dest),
                'uploaded_by_type' => $byType->value,
                'uploaded_by' => $byId,
            ]);
            foreach ($old as $row) {
                $this->removeFile($row);
                $db->delete('customer_documents', ['id' => $row['id']]);
            }
            $this->audit->record(
                $old === [] ? 'document.upload' : 'document.replace',
                'customer',
                $customerId,
                $old === [] ? null : ['document_id' => $old[0]['id']],
                ['document_id' => $id, 'doc_type' => $type->value, 'mime' => $mime],
                actorType: $byType->value,
                actorId: $byId,
            );
            return (array) CustomerDocument::find($id);
        });
    }

    /** @param array<string, mixed> $doc */
    public function delete(array $doc, HolderType $byType, ?int $byId): void
    {
        $this->removeFile($doc);
        $this->db->delete('customer_documents', ['id' => $doc['id']]);
        $this->audit->record('document.delete', 'customer', (int) $doc['customer_id'], ['document_id' => $doc['id'], 'doc_type' => $doc['doc_type']],
            null, actorType: $byType->value, actorId: $byId);
    }

    /**
     * Absolute path of a stored document, refusing anything outside storage/uploads.
     * @param array<string, mixed> $doc
     */
    public function path(array $doc): string
    {
        $root = realpath(self::root());
        $file = realpath(self::root() . '/' . ltrim((string) $doc['file_path'], '/'));
        if ($root === false || $file === false || !str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            throw new NotFoundException('Document file not found');
        }
        return $file;
    }

    /**
     * Friendly download name: "CMN-KTR-I-2026-00001-pan.pdf".
     * @param array<string, mixed> $doc
     */
    public static function downloadName(array $doc, ?string $uniqueId): string
    {
        $ext = self::ALLOWED[(string) $doc['mime']][0] ?? 'bin';
        return ($uniqueId !== null && $uniqueId !== '' ? $uniqueId : 'visitor-' . $doc['customer_id']) . '-' . $doc['doc_type'] . '.' . $ext;
    }

    public static function isImage(string $mime): bool
    {
        return str_starts_with($mime, 'image/');
    }

    private function reencode(string $source, string $dest, string $mime): void
    {
        $manager = ImageManager::gd(autoOrientation: true, strip: true);
        $image = $manager->read($source);
        $image->scaleDown(self::MAX_IMAGE_EDGE, self::MAX_IMAGE_EDGE);
        $encoded = match ($mime) {
            'image/png' => $image->toPng(),
            'image/webp' => $image->toWebp(quality: 85),
            default => $image->toJpeg(quality: 85, progressive: true),
        };
        $encoded->save($dest);
    }

    /** @param array<string, mixed> $doc */
    private function removeFile(array $doc): void
    {
        try {
            @unlink($this->path($doc));
        } catch (NotFoundException) {
            // already gone
        }
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[^\pL\pN._ -]+/u', '_', $name);
        return trim($name) !== '' ? trim($name) : 'document';
    }
}
