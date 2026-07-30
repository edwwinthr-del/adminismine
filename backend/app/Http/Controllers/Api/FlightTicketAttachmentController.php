<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\StoreTravelAttachmentRequest;
use App\Http\Resources\FileAttachmentResource;
use App\Models\FileAttachment;
use App\Models\FlightTicket;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Ticket scans, boarding passes and agency invoices. */
class FlightTicketAttachmentController extends Controller
{
    public function __construct(private readonly FileAttachmentService $files) {}

    public function index(FlightTicket $ticket): JsonResponse
    {
        return response()->json([
            'data' => FileAttachmentResource::collection($ticket->attachments),
        ]);
    }

    public function store(StoreTravelAttachmentRequest $request, FlightTicket $ticket): JsonResponse
    {
        $attachment = $this->files->store(
            $ticket,
            $request->file('file'),
            $request->safe()->only(['kind', 'label', 'notes']) + ['kind' => 'invoice'],
        );

        activity()->performedOn($ticket)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id, 'kind' => $attachment->kind])
            ->log('flight_ticket.attachment_added');

        return (new FileAttachmentResource($attachment))->response()->setStatusCode(201);
    }

    public function download(FlightTicket $ticket, FileAttachment $attachment): StreamedResponse
    {
        $this->assertBelongsTo($ticket, $attachment);

        return $this->files->download($attachment);
    }

    public function destroy(Request $request, FlightTicket $ticket, FileAttachment $attachment): JsonResponse
    {
        $this->assertBelongsTo($ticket, $attachment);

        $this->files->delete($attachment);

        activity()->performedOn($ticket)->causedBy($request->user())
            ->withProperties(['attachment_id' => $attachment->id])
            ->log('flight_ticket.attachment_removed');

        return response()->json(['message' => 'Attachment removed.']);
    }

    private function assertBelongsTo(FlightTicket $ticket, FileAttachment $attachment): void
    {
        abort_unless(
            $attachment->attachable_type === FlightTicket::class && $attachment->attachable_id === $ticket->id,
            404,
        );
    }
}
