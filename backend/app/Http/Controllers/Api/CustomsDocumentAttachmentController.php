<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Customs\StoreCustomsAttachmentRequest;
use App\Http\Resources\FileAttachmentResource;
use App\Models\CustomsDocument;
use App\Models\FileAttachment;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Scans and photos of customs/transport papers (CMR, declarations, delivery notes). */
class CustomsDocumentAttachmentController extends Controller
{
    public function __construct(private readonly FileAttachmentService $files) {}

    public function index(CustomsDocument $customsDocument): JsonResponse
    {
        return response()->json([
            'data' => FileAttachmentResource::collection($customsDocument->attachments),
        ]);
    }

    public function store(StoreCustomsAttachmentRequest $request, CustomsDocument $customsDocument): JsonResponse
    {
        $attachment = $this->files->store(
            $customsDocument,
            $request->file('file'),
            // A CMR scan is the default here, unlike machines where invoices dominate.
            $request->safe()->only(['kind', 'label', 'notes']) + ['kind' => 'cmr'],
        );

        activity()->performedOn($customsDocument)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id, 'kind' => $attachment->kind])
            ->log('customs_document.attachment_added');

        return (new FileAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(CustomsDocument $customsDocument, FileAttachment $attachment): StreamedResponse
    {
        $this->assertBelongsTo($customsDocument, $attachment);

        return $this->files->download($attachment);
    }

    public function destroy(Request $request, CustomsDocument $customsDocument, FileAttachment $attachment): JsonResponse
    {
        $this->assertBelongsTo($customsDocument, $attachment);

        $this->files->delete($attachment);

        activity()->performedOn($customsDocument)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id])
            ->log('customs_document.attachment_removed');

        return response()->json(['message' => 'Attachment removed.']);
    }

    private function assertBelongsTo(CustomsDocument $document, FileAttachment $attachment): void
    {
        abort_unless(
            $attachment->attachable_type === CustomsDocument::class && $attachment->attachable_id === $document->id,
            404,
        );
    }
}
