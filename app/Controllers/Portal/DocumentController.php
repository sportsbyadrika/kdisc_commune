<?php

declare(strict_types=1);

namespace App\Controllers\Portal;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Enums\DocumentType;
use App\Enums\HolderType;
use App\Models\Customer;
use App\Models\CustomerDocument;
use App\Services\Kyc\DocumentStore;
use App\Services\Visitors\ProfileService;

/** The visitor's own KYC documents: list, upload/replace, delete (until verified), and authorised download. */
final class DocumentController extends PortalController
{
    public function __construct(private readonly DocumentStore $store, private readonly ProfileService $profiles)
    {
    }

    public function index(): Response
    {
        $customer = $this->customer();
        return $this->view('portal/documents', [
            'title' => 'My documents',
            'customer' => Customer::safe($customer),
            'checklist' => $this->profiles->documentChecklist($customer),
            'locked' => ProfileService::documentsLocked($customer),
        ]);
    }

    public function store(Request $request): Response
    {
        $customer = $this->customer();
        if (ProfileService::documentsLocked($customer)) {
            throw new ForbiddenException('Documents cannot be changed after KYC verification. Edit your profile first, or contact the front desk.');
        }
        $type = DocumentType::tryFrom($request->string('doc_type')) ?? throw new ValidationException(['file' => ['Unknown document type.']]);
        $file = $request->file('file') ?? throw new ValidationException(['file' => ['Please choose a file to upload.']]);
        $this->store->store((int) $customer['id'], $type, $file, HolderType::Account, $this->accountId());
        return back('portal.documents')->with('success', $type->label() . ' uploaded.');
    }

    public function destroy(int $id): Response
    {
        $customer = $this->customer();
        $doc = $this->ownDocument($id, $customer);
        if (ProfileService::documentsLocked($customer)) {
            throw new ForbiddenException('Documents cannot be deleted after KYC verification.');
        }
        $this->store->delete($doc, HolderType::Account, $this->accountId());
        return back('portal.documents')->with('success', DocumentType::from((string) $doc['doc_type'])->label() . ' removed.');
    }

    public function file(int $id, Request $request): Response
    {
        $customer = $this->customer();
        $doc = $this->ownDocument($id, $customer);
        return Response::file(
            $this->store->path($doc),
            DocumentStore::downloadName($doc, $customer['unique_id']),
            (string) $doc['mime'],
            $request->bool('download') ? 'attachment' : 'inline',
        )->header('Cache-Control', 'private, no-store')->header('Content-Security-Policy', DocumentStore::FILE_CSP);
    }

    /**
     * @param array<string, mixed> $customer
     * @return array<string, mixed>
     */
    private function ownDocument(int $id, array $customer): array
    {
        $doc = CustomerDocument::find($id);
        if ($doc === null || (int) $doc['customer_id'] !== (int) $customer['id']) {
            throw new NotFoundException(); // never confirm other visitors' document ids
        }
        return $doc;
    }
}
