<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\MatchTransactionRequest;
use App\Http\Requests\Bank\StoreBankTransactionRequest;
use App\Http\Requests\Bank\UpdateBankTransactionRequest;
use App\Http\Resources\BankTransactionResource;
use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankTransactionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = BankTransaction::query()->with(['supplier', 'client']);

        if ($request->filled('category')) {
            $query->where('category', $request->input('category'));
        }
        if ($request->boolean('uncategorized')) {
            $query->whereNull('category');
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date('date_to'));
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        $query->search($request->input('search'));

        $query->orderByDesc('date')->orderByDesc('id');

        $transactions = $query->paginate($request->integer('per_page', 50));

        $this->flagDuplicates($transactions->getCollection());

        return BankTransactionResource::collection($transactions);
    }

    public function store(StoreBankTransactionRequest $request): JsonResponse
    {
        $transaction = BankTransaction::create($request->validated());

        activity()->performedOn($transaction)->causedBy($request->user())->log('bank_transaction.created');

        return (new BankTransactionResource($transaction->load('supplier', 'client')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(BankTransaction $bankTransaction): BankTransactionResource
    {
        return new BankTransactionResource($bankTransaction->load('supplier', 'client', 'payments'));
    }

    public function update(UpdateBankTransactionRequest $request, BankTransaction $bankTransaction): BankTransactionResource
    {
        $bankTransaction->update($request->validated());

        activity()->performedOn($bankTransaction)->causedBy($request->user())->log('bank_transaction.updated');

        return new BankTransactionResource($bankTransaction->load('supplier', 'client'));
    }

    public function destroy(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        $bankTransaction->delete();

        activity()->performedOn($bankTransaction)->causedBy($request->user())->log('bank_transaction.deleted');

        return response()->json(['message' => 'Transaction deleted.']);
    }

    /** Current balance of each account (sum of signed movements). */
    public function balances(): JsonResponse
    {
        return response()->json(['data' => BankTransaction::accountBalances()]);
    }

    /** Match this movement to a supplier/client invoice by recording a payment. */
    public function match(MatchTransactionRequest $request, BankTransaction $bankTransaction): JsonResponse
    {
        $data = $request->validated();

        $invoice = $data['target'] === 'payable'
            ? PayableInvoice::findOrFail($data['invoice_id'])
            : ReceivableInvoice::findOrFail($data['invoice_id']);

        if ((float) $data['amount'] > (float) $invoice->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Amount exceeds the invoice remaining balance.',
                'errors' => ['amount' => ['Amount exceeds the invoice remaining balance.']],
            ], 422);
        }

        $method = $this->deriveMethod($bankTransaction);

        DB::transaction(function () use ($invoice, $bankTransaction, $data, $method) {
            $invoice->payments()->create([
                'amount' => $data['amount'],
                'currency' => $bankTransaction->currency,
                'payment_date' => $bankTransaction->date->toDateString(),
                'method' => $method,
                'bank_transaction_id' => $bankTransaction->id,
            ]);
            $invoice->recalculate();
        });

        activity()->performedOn($bankTransaction)->causedBy($request->user())
            ->withProperties(['target' => $data['target'], 'invoice_id' => $data['invoice_id'], 'amount' => $data['amount']])
            ->log('bank_transaction.matched');

        return response()->json([
            'data' => new BankTransactionResource($bankTransaction->fresh()->load('supplier', 'client', 'payments')),
        ], 201);
    }

    /**
     * Flag rows that share date + all three amounts with another row. Only the
     * ids on this page are asked about — a duplicate is still defined against
     * the whole table, but the answer never carries more rows than are shown.
     */
    private function flagDuplicates(Collection|\Illuminate\Database\Eloquent\Collection $pageRows): void
    {
        if ($pageRows->isEmpty()) {
            return;
        }

        $duplicateIds = BankTransaction::duplicateIds($pageRows->pluck('id')->all())->flip();

        $pageRows->each(function (BankTransaction $t) use ($duplicateIds) {
            $t->setAttribute('possible_duplicate', isset($duplicateIds[$t->id]));
        });
    }

    private function deriveMethod(BankTransaction $t): string
    {
        if ((float) $t->nlb_amount !== 0.0) {
            return 'nlb';
        }
        if ((float) $t->lovcen_amount !== 0.0) {
            return 'lovcen';
        }
        if ((float) $t->cash_amount !== 0.0) {
            return 'cash';
        }

        return 'other';
    }
}
