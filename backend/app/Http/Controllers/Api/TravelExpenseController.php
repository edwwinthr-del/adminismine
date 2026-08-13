<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\CorrectsPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\RecordTravelPaymentRequest;
use App\Http\Requests\Travel\StoreTravelExpenseRequest;
use App\Http\Requests\Travel\UpdateTravelExpenseRequest;
use App\Http\Resources\TravelExpenseResource;
use App\Models\Payment;
use App\Models\TravelExpense;
use App\Services\CurrencyConverter;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TravelExpenseController extends Controller
{
    use CorrectsPayments;

    public function __construct(private readonly CurrencyConverter $converter) {}

    public function index(Request $request): JsonResponse
    {
        $query = TravelExpense::query()->with('employee')->withCount('attachments');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('expense_type')) {
            $query->where('expense_type', $request->input('expense_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('cost_status')) {
            $query->where('cost_status', $request->input('cost_status'));
        }
        if ($request->filled('from')) {
            $query->whereDate('expense_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('expense_date', '<=', $request->input('to'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }
        if ($request->boolean('unwritten')) {
            $query->unwritten();
        }

        $query->orderByDesc('expense_date')->orderByDesc('id');

        return response()->json([
            'data' => TravelExpenseResource::collection($query->get()),
        ]);
    }

    public function store(StoreTravelExpenseRequest $request): JsonResponse
    {
        $data = $this->converter->fill($request->validated(), dateKey: 'expense_date');

        $expense = TravelExpense::create($data);
        $expense->recalculate();

        activity()->performedOn($expense)->causedBy($request->user())->log('travel_expense.created');

        return (new TravelExpenseResource($expense->load('employee')))->response()->setStatusCode(201);
    }

    public function show(TravelExpense $travelExpense): TravelExpenseResource
    {
        return new TravelExpenseResource($travelExpense->load('employee', 'payments', 'attachments'));
    }

    public function update(UpdateTravelExpenseRequest $request, TravelExpense $travelExpense): TravelExpenseResource
    {
        $data = $request->validated();

        if (array_intersect_key($data, array_flip(['amount', 'currency', 'exchange_rate', 'expense_date'])) !== []) {
            $data = $this->converter->fill($data + [
                'amount' => $travelExpense->amount,
                'currency' => $travelExpense->currency,
                'exchange_rate' => $travelExpense->exchange_rate,
                'expense_date' => optional($travelExpense->expense_date)->toDateString(),
            ], dateKey: 'expense_date');
        }

        $travelExpense->update($data);
        $travelExpense->recalculate();

        activity()->performedOn($travelExpense)->causedBy($request->user())->log('travel_expense.updated');

        return new TravelExpenseResource($travelExpense->load('employee', 'payments'));
    }

    public function destroy(Request $request, TravelExpense $travelExpense, FileAttachmentService $files): JsonResponse
    {
        DB::transaction(function () use ($travelExpense, $files) {
            $files->deleteAllFor($travelExpense);
            $travelExpense->payments()->delete();
            $travelExpense->delete();
        });

        activity()->performedOn($travelExpense)->causedBy($request->user())->log('travel_expense.deleted');

        return response()->json(['message' => 'Travel expense deleted.']);
    }

    public function recordPayment(RecordTravelPaymentRequest $request, TravelExpense $travelExpense): JsonResponse
    {
        $data = $request->validated();

        if ((float) $data['amount'] > (float) $travelExpense->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Payment exceeds the remaining amount on this expense.',
                'errors' => ['amount' => ['Payment exceeds the remaining amount on this expense.']],
            ], 422);
        }

        DB::transaction(function () use ($travelExpense, $data) {
            $travelExpense->payments()->create($data + ['currency' => 'EUR']);
            $travelExpense->recalculate();
        });

        activity()->performedOn($travelExpense)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
            ->log('travel_expense.payment_recorded');

        return response()->json([
            'data' => new TravelExpenseResource($travelExpense->fresh()->load('employee', 'payments')),
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
        TravelExpense $travelExpense,
        Payment $payment,
    ): JsonResponse {
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->correctPayment($travelExpense, $payment, $request->validated());

        activity()->performedOn($travelExpense)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('travel_expense.payment_updated');

        return response()->json([
            'data' => new TravelExpenseResource($travelExpense->fresh()->load('employee', 'payments')),
        ]);
    }

    /** Remove a payment; the obligation's figures follow from the lines that are left. */
    public function deletePayment(Request $request, TravelExpense $travelExpense, Payment $payment): JsonResponse
    {
        $removed = $payment->only(['amount', 'payment_date', 'method']);

        $this->removePayment($travelExpense, $payment);

        activity()->performedOn($travelExpense)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'removed' => $removed])
            ->log('travel_expense.payment_deleted');

        return response()->json([
            'data' => new TravelExpenseResource($travelExpense->fresh()->load('employee', 'payments')),
        ]);
    }
}
