<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Machine\StoreMachineAttachmentRequest;
use App\Http\Resources\FileAttachmentResource;
use App\Models\FileAttachment;
use App\Models\Machine;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Machine paperwork: purchase invoice, warranty, customs papers, photos.
 * The files themselves are handled by FileAttachmentService and never exposed
 * through a public URL.
 */
class MachineAttachmentController extends Controller
{
    public function __construct(private readonly FileAttachmentService $files) {}

    public function index(Machine $machine): JsonResponse
    {
        return response()->json([
            'data' => FileAttachmentResource::collection($machine->attachments),
        ]);
    }

    public function store(StoreMachineAttachmentRequest $request, Machine $machine): JsonResponse
    {
        $attachment = $this->files->store($machine, $request->file('file'), $request->safe()->only([
            'kind', 'label', 'notes',
        ]));

        activity()->performedOn($machine)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id, 'kind' => $attachment->kind])
            ->log('machine.attachment_added');

        return (new FileAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(Machine $machine, FileAttachment $attachment): StreamedResponse
    {
        $this->assertBelongsTo($machine, $attachment);

        return $this->files->download($attachment);
    }

    public function destroy(Request $request, Machine $machine, FileAttachment $attachment): JsonResponse
    {
        $this->assertBelongsTo($machine, $attachment);

        $this->files->delete($attachment);

        activity()->performedOn($machine)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id])
            ->log('machine.attachment_removed');

        return response()->json(['message' => 'Attachment removed.']);
    }

    /** An attachment id from another record must not be reachable here. */
    private function assertBelongsTo(Machine $machine, FileAttachment $attachment): void
    {
        abort_unless(
            $attachment->attachable_type === Machine::class && $attachment->attachable_id === $machine->id,
            404,
        );
    }
}
