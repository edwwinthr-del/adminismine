<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\StoreTravelAttachmentRequest;
use App\Http\Resources\FileAttachmentResource;
use App\Models\FileAttachment;
use App\Models\TravelExpense;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Receipts backing a travel expense. */
class TravelExpenseAttachmentController extends Controller
{
    public function __construct(private readonly FileAttachmentService $files) {}

    public function index(TravelExpense $travelExpense): JsonResponse
    {
        return response()->json([
            'data' => FileAttachmentResource::collection($travelExpense->attachments),
        ]);
    }

    public function store(StoreTravelAttachmentRequest $request, TravelExpense $travelExpense): JsonResponse
    {
        $attachment = $this->files->store(
            $travelExpense,
            $request->file('file'),
            $request->safe()->only(['kind', 'label', 'notes']) + ['kind' => 'invoice'],
        );

        activity()->performedOn($travelExpense)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id, 'kind' => $attachment->kind])
            ->log('travel_expense.attachment_added');

        return (new FileAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(TravelExpense $travelExpense, FileAttachment $attachment): StreamedResponse
    {
        $this->assertBelongsTo($travelExpense, $attachment);

        return $this->files->download($attachment);
    }

    public function destroy(Request $request, TravelExpense $travelExpense, FileAttachment $attachment): JsonResponse
    {
        $this->assertBelongsTo($travelExpense, $attachment);

        $this->files->delete($attachment);

        activity()->performedOn($travelExpense)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id])
            ->log('travel_expense.attachment_removed');

        return response()->json(['message' => 'Attachment removed.']);
    }

    private function assertBelongsTo(TravelExpense $expense, FileAttachment $attachment): void
    {
        abort_unless(
            $attachment->attachable_type === TravelExpense::class && $attachment->attachable_id === $expense->id,
            404,
        );
    }
}
