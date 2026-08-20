<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\SettlesWithPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\RecordRepaymentRequest;
use App\Http\Requests\Loans\StoreLoanRequest;
use App\Http\Requests\Loans\UpdateLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Models\Payment;
use App\Services\CurrencyConverter;
use App\Support\Currencies;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
    use SettlesWithPayments;

    public function __construct(private readonly CurrencyConverter $converter) {}

    public function index(Request $request): JsonResponse
    {
        // Filters are applied through a closure so the listing and the totals
        // are guaranteed to be answering the same question.
        $filtered = fn (): Builder => $this->applyFilters(Loan::query(), $request);

        // The totals cover everything the filters select, not just the page on
        // screen — which is why they are their own aggregate rather than a sum
        // over the rows that happen to be loaded.
        $totals = $filtered()->selectRaw(
            'COALESCE(SUM(amount_eur), 0) as total, COALESCE(SUM(repaid_amount), 0) as repaid, '
            .'COALESCE(SUM(remaining_amount), 0) as remaining',
        )->first();

        $loans = $filtered()
            ->with(['supplier', 'client', 'employee'])
            ->withCount('repayments')
            ->orderByDesc('loan_date')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 50));

        return response()->json([
            'data' => LoanResource::collection($loans->getCollection()),
            'meta' => [
                'current_page' => $loans->currentPage(),
                'last_page' => $loans->lastPage(),
                'per_page' => $loans->perPage(),
                'total' => $loans->total(),
                'from' => $loans->firstItem(),
                'to' => $loans->lastItem(),
                // The balance question the module exists to answer.
                'total_eur' => round((float) $totals->total, 2),
                'repaid_eur' => round((float) $totals->repaid, 2),
                'remaining_eur' => round((float) $totals->remaining, 2),
                // Counted server-side: a page of loans cannot answer how many
                // are overdue across the whole selection.
                'overdue_count' => $filtered()->overdue()->count(),
            ],
        ]);
    }

    /** @param  Builder<Loan>  $query */
    private function applyFilters(Builder $query, Request $request): Builder
    {
        // `counterparty` is kept as the historical filter name; both it and the
        // conventional `search` box hit the same scope.
        $query->search($request->input('search') ?? $request->input('counterparty'));

        if ($request->filled('direction')) {
            $query->where('direction', $request->input('direction'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('reference_number')) {
            $query->where('reference_number', $request->input('reference_number'));
        }
        if ($request->filled('from')) {
            $query->whereDate('loan_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('loan_date', '<=', $request->input('to'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }
        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        return $query;
    }

    public function store(StoreLoanRequest $request): JsonResponse
    {
        $data = $this->converter->fill($request->validated(), amountKey: 'original_amount', dateKey: 'loan_date');

        $loan = Loan::create($data);
        $loan->recalculate();

        activity()->performedOn($loan)->causedBy($request->user())->log('loan.created');

        return (new LoanResource($loan->load('supplier', 'client', 'employee')))->response()->setStatusCode(201);
    }

    public function show(Loan $loan): LoanResource
    {
        return new LoanResource($loan->load('supplier', 'client', 'employee', 'repayments'));
    }

    public function update(UpdateLoanRequest $request, Loan $loan): LoanResource
    {
        $data = $request->validated();

        if (array_intersect_key($data, array_flip(['original_amount', 'currency', 'exchange_rate', 'loan_date'])) !== []) {
            $data = $this->converter->fill($data + [
                'original_amount' => $loan->original_amount,
                'currency' => $loan->currency,
                'exchange_rate' => $loan->exchange_rate,
                'loan_date' => optional($loan->loan_date)->toDateString(),
            ], amountKey: 'original_amount', dateKey: 'loan_date');
        }

        $loan->update($data);
        $loan->recalculate();

        activity()->performedOn($loan)->causedBy($request->user())->log('loan.updated');

        return new LoanResource($loan->load('supplier', 'client', 'employee', 'repayments'));
    }

    public function destroy(Request $request, Loan $loan): JsonResponse
    {
        $released = DB::transaction(function () use ($loan): array {
            $released = $this->releaseSettlements($loan);
            $loan->delete();

            return $released;
        });

        activity()->performedOn($loan)->causedBy($request->user())
            ->withProperties(['released_bank_transactions' => $released])
            ->log('loan.deleted');

        return response()->json(['message' => 'Loan deleted.']);
    }

    /**
     * Record a repayment, in the accounting currency. `book_bank_transaction`
     * writes the movement it represents: money out for a loan the company took,
     * money in for one it gave (Loan::movementDirection()).
     */
    public function recordRepayment(RecordRepaymentRequest $request, Loan $loan): JsonResponse
    {
        $data = $request->paymentData();

        $payment = $this->recordSettlement($loan, $data, Currencies::BASE, $request->booksMovement());

        activity()->performedOn($loan)->causedBy($request->user())
            ->withProperties([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'bank_transaction_id' => $payment->bank_transaction_id,
            ])
            ->log('loan.repayment_recorded');

        return response()->json([
            'data' => new LoanResource($loan->fresh()->load('supplier', 'client', 'employee', 'repayments')),
        ], 201);
    }

    /**
     * Correct a repayment that was entered wrong, rather than booking its opposite.
     *
     * See SettlesWithPayments: two rows that cancel out would both read as real
     * money in every report and in the bank match.
     */
    public function updateRepayment(
        RecordRepaymentRequest $request,
        Loan $loan,
        Payment $payment,
    ): JsonResponse {
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->correctSettlement($loan, $payment, $request->paymentData(), $request->booksMovement());

        activity()->performedOn($loan)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->paymentData()])
            ->log('loan.repayment_updated');

        return response()->json([
            'data' => new LoanResource($loan->fresh()->load('supplier', 'client', 'employee', 'repayments')),
        ]);
    }

    /** Remove a repayment; the obligation's figures follow from the lines that are left. */
    public function deleteRepayment(Request $request, Loan $loan, Payment $payment): JsonResponse
    {
        $removed = $payment->only(['amount', 'payment_date', 'method']);

        $movement = $this->removeSettlement($loan, $payment);

        activity()->performedOn($loan)->causedBy($request->user())
            ->withProperties([
                'payment_id' => $payment->id,
                'removed' => $removed,
                'released_bank_transaction' => $movement,
            ])
            ->log('loan.repayment_deleted');

        return response()->json([
            'data' => new LoanResource($loan->fresh()->load('supplier', 'client', 'employee', 'repayments')),
        ]);
    }
}
