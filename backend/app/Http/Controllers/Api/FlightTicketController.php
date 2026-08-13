<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\CorrectsPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\RecordTravelPaymentRequest;
use App\Http\Requests\Travel\StoreFlightTicketRequest;
use App\Http\Requests\Travel\UpdateFlightTicketRequest;
use App\Http\Resources\FlightTicketResource;
use App\Models\FlightTicket;
use App\Models\Payment;
use App\Services\CurrencyConverter;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FlightTicketController extends Controller
{
    use CorrectsPayments;

    public function __construct(private readonly CurrencyConverter $converter) {}

    public function index(Request $request): JsonResponse
    {
        $query = FlightTicket::query()->with('employee')->withCount('attachments');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('cost_status')) {
            $query->where('cost_status', $request->input('cost_status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('ticket_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('ticket_date', '<=', $request->input('to'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }
        if ($request->boolean('unwritten')) {
            $query->unwritten();
        }

        $query->orderByDesc('ticket_date')->orderByDesc('id');

        return response()->json([
            'data' => FlightTicketResource::collection($query->get()),
        ]);
    }

    public function store(StoreFlightTicketRequest $request): JsonResponse
    {
        $data = $this->converter->fill($request->validated(), dateKey: 'ticket_date');

        $ticket = FlightTicket::create($data);
        $ticket->recalculate();

        activity()->performedOn($ticket)->causedBy($request->user())->log('flight_ticket.created');

        return (new FlightTicketResource($ticket->load('employee')))->response()->setStatusCode(201);
    }

    public function show(FlightTicket $ticket): FlightTicketResource
    {
        return new FlightTicketResource($ticket->load('employee', 'payments', 'attachments'));
    }

    public function update(UpdateFlightTicketRequest $request, FlightTicket $ticket): FlightTicketResource
    {
        $data = $request->validated();

        // Recompute the EUR value whenever anything it depends on moves. The
        // record's own values fill the gaps, so a rate pinned earlier survives
        // an amount-only edit — and is only dropped if explicitly sent as null.
        if (array_intersect_key($data, array_flip(['amount', 'currency', 'exchange_rate', 'ticket_date'])) !== []) {
            $data = $this->converter->fill($data + [
                'amount' => $ticket->amount,
                'currency' => $ticket->currency,
                'exchange_rate' => $ticket->exchange_rate,
                'ticket_date' => optional($ticket->ticket_date)->toDateString(),
            ], dateKey: 'ticket_date');
        }

        $ticket->update($data);
        $ticket->recalculate();

        activity()->performedOn($ticket)->causedBy($request->user())->log('flight_ticket.updated');

        return new FlightTicketResource($ticket->load('employee', 'payments'));
    }

    public function destroy(Request $request, FlightTicket $ticket, FileAttachmentService $files): JsonResponse
    {
        DB::transaction(function () use ($ticket, $files) {
            $files->deleteAllFor($ticket);
            $ticket->payments()->delete();
            $ticket->delete();
        });

        activity()->performedOn($ticket)->causedBy($request->user())->log('flight_ticket.deleted');

        return response()->json(['message' => 'Flight ticket deleted.']);
    }

    public function recordPayment(RecordTravelPaymentRequest $request, FlightTicket $ticket): JsonResponse
    {
        $data = $request->validated();

        if ((float) $data['amount'] > (float) $ticket->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Payment exceeds the remaining amount on this ticket.',
                'errors' => ['amount' => ['Payment exceeds the remaining amount on this ticket.']],
            ], 422);
        }

        DB::transaction(function () use ($ticket, $data) {
            // Settlement is booked in the accounting currency, not the ticket's.
            $ticket->payments()->create($data + ['currency' => 'EUR']);
            $ticket->recalculate();
        });

        activity()->performedOn($ticket)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
            ->log('flight_ticket.payment_recorded');

        return response()->json([
            'data' => new FlightTicketResource($ticket->fresh()->load('employee', 'payments')),
        ], 201);
    }

    /**
     * Correct a payment that was entered wrong, rather than booking its opposite.
     *
     * See CorrectsPayments: two rows that cancel out would both read as real
     * money in every report and in the bank match.
     */
    public function updatePayment(
        RecordTravelPaymentRequest $request,
        FlightTicket $ticket,
        Payment $payment,
    ): JsonResponse {
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->correctPayment($ticket, $payment, $request->validated());

        activity()->performedOn($ticket)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('flight_ticket.payment_updated');

        return response()->json([
            'data' => new FlightTicketResource($ticket->fresh()->load('employee', 'payments')),
        ]);
    }

    /** Remove a payment; the obligation's figures follow from the lines that are left. */
    public function deletePayment(Request $request, FlightTicket $ticket, Payment $payment): JsonResponse
    {
        $removed = $payment->only(['amount', 'payment_date', 'method']);

        $this->removePayment($ticket, $payment);

        activity()->performedOn($ticket)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'removed' => $removed])
            ->log('flight_ticket.payment_deleted');

        return response()->json([
            'data' => new FlightTicketResource($ticket->fresh()->load('employee', 'payments')),
        ]);
    }
}
