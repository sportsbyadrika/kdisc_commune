<?php

declare(strict_types=1);

namespace App\Controllers\Staff;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\AuditLog;
use App\Services\Kyc\DocumentStore;
use App\Services\Visitors\ProfileService;

/**
 * KYC documents from the staff side: scan/upload/webcam capture, delete, and the authorised viewer
 * (every view of a document is audit-logged, spec 12).
 */
final class VisitorDocumentController extends StaffController
{
    public function __construct(private readonly DocumentStore $store, private readonly AuditLog $audit)
    {
    }

    public function store(Request $request, string $ref): Response
    {
        $customer = $this->findCustomer($ref);
        $this->assertEditable($customer);
        $type = DocumentType::tryFrom($request->string('doc_type')) ?? throw new ValidationException(['file' => ['Unknown document type.']]);
        $file = $request->file('file') ?? throw new ValidationException(['file' => ['Please choose or capture a file.']]);
        $this->store->store((int) $customer['id'], $type, $file, HolderType::Staff, $this->staffId());
        return back('staff.visitors.index')->with('success', $type->label() . ' uploaded.');
    }

    public function destroy(string $ref, int $id): Response
    {
        $customer = $this->findCustomer($ref);
        $this->assertEditable($customer);
        $doc = CustomerDocument::find($id);
        if ($doc === null || (int) $doc['customer_id'] !== (int) $customer['id']) {
            throw new NotFoundException();
        }
        $this->store->delete($doc, HolderType::Staff, $this->staffId());
        return back('staff.visitors.index')->with('success', DocumentType::from((string) $doc['doc_type'])->label() . ' removed.');
    }

    public function file(int $id, Request $request): Response
    {
        $doc = CustomerDocument::find($id) ?? throw new NotFoundException();
        $customer = (array) Customer::find((int) $doc['customer_id']);
        $this->audit->record('kyc.document.view', 'customer', (int) $doc['customer_id'], null, ['document_id' => $id, 'doc_type' => $doc['doc_type']]);
        return Response::file(
            $this->store->path($doc),
            DocumentStore::downloadName($doc, $customer['unique_id'] ?? null),
            (string) $doc['mime'],
            $request->bool('download') ? 'attachment' : 'inline',
        )->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', DocumentStore::FILE_CSP);
    }

    /** @param array<string, mixed> $customer */
    private function assertEditable(array $customer): void
    {
        if (ProfileService::documentsLocked($customer) && !$this->can('kyc.verify')) {
            throw new ForbiddenException('Documents of a verified visitor can only be changed by the Centre Manager.');
        }
    }
}
