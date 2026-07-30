<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\StoreUtilityBillAttachmentRequest;
use App\Http\Resources\FileAttachmentResource;
use App\Models\FileAttachment;
use App\Models\UtilityBill;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Scans and photos of utility bills. */
class UtilityBillAttachmentController extends Controller
{
    public function __construct(private readonly FileAttachmentService $files) {}

    public function index(UtilityBill $bill): JsonResponse
    {
        return response()->json([
            'data' => FileAttachmentResource::collection($bill->attachments),
        ]);
    }

    public function store(StoreUtilityBillAttachmentRequest $request, UtilityBill $bill): JsonResponse
    {
        $attachment = $this->files->store(
            $bill,
            $request->file('file'),
            $request->safe()->only(['kind', 'label', 'notes']) + ['kind' => 'invoice'],
        );

        activity()->performedOn($bill)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id, 'kind' => $attachment->kind])
            ->log('utility_bill.attachment_added');

        return (new FileAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(UtilityBill $bill, FileAttachment $attachment): StreamedResponse
    {
        $this->assertBelongsTo($bill, $attachment);

        return $this->files->download($attachment);
    }

    public function destroy(Request $request, UtilityBill $bill, FileAttachment $attachment): JsonResponse
    {
        $this->assertBelongsTo($bill, $attachment);

        $this->files->delete($attachment);

        activity()->performedOn($bill)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id])
            ->log('utility_bill.attachment_removed');

        return response()->json(['message' => 'Attachment removed.']);
    }

    private function assertBelongsTo(UtilityBill $bill, FileAttachment $attachment): void
    {
        abort_unless(
            $attachment->attachable_type === UtilityBill::class && $attachment->attachable_id === $bill->id,
            404,
        );
    }
}
