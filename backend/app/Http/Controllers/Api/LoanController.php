<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Loans\RecordRepaymentRequest;
use App\Http\Requests\Loans\StoreLoanRequest;
use App\Http\Requests\Loans\UpdateLoanRequest;
use App\Http\Resources\LoanResource;
use App\Models\Loan;
use App\Services\CurrencyConverter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LoanController extends Controller
{
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
        DB::transaction(function () use ($loan) {
            $loan->repayments()->delete();
            $loan->delete();
        });

        activity()->performedOn($loan)->causedBy($request->user())->log('loan.deleted');

        return response()->json(['message' => 'Loan deleted.']);
    }

    public function recordRepayment(RecordRepaymentRequest $request, Loan $loan): JsonResponse
    {
        $data = $request->validated();

        if ((float) $data['amount'] > (float) $loan->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Repayment exceeds the remaining balance on this loan.',
                'errors' => ['amount' => ['Repayment exceeds the remaining balance on this loan.']],
            ], 422);
        }

        DB::transaction(function () use ($loan, $data) {
            // Repayments are booked in the accounting currency.
            $loan->repayments()->create($data + ['currency' => 'EUR']);
            $loan->recalculate();
        });

        activity()->performedOn($loan)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
            ->log('loan.repayment_recorded');

        return response()->json([
            'data' => new LoanResource($loan->fresh()->load('supplier', 'client', 'employee', 'repayments')),
        ], 201);
    }
}
