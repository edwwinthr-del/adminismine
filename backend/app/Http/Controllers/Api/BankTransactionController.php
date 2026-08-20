<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bank\MatchTransactionRequest;
use App\Http\Requests\Bank\StoreBankTransactionRequest;
use App\Http\Requests\Bank\UpdateBankTransactionRequest;
use App\Http\Resources\BankTransactionResource;
use App\Models\BankTransaction;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Services\CurrencyConverter;
use App\Services\PaymentBankMovement;
use App\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankTransactionController extends Controller
{
    use ConfirmsPassword;

    public function index(Request $request): AnonymousResourceCollection
    {
        // withRunningBalance() is what makes the ledger read like a bank
        // statement: each row carries the balance *as of* itself, so an expense
        // visibly subtracts in sequence instead of being a number the reader has
        // to add up. See BankTransaction::scopeWithRunningBalance.
        $query = BankTransaction::query()->withRunningBalance()->with(['supplier', 'client']);

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

    /**
     * Remove a movement, password-confirmed (ConfirmsPassword).
     *
     * Any invoice payment matched to it is *released*, not deleted: the money
     * really was settled, and un-paying an invoice because its bank line was
     * corrected would be a second error on top of the first. The link lives on
     * the payment row, so releasing it and deleting the movement happen in one
     * transaction — the payment can never be left pointing at a row that is gone.
     */
    public function destroy(Request $request, BankTransaction $bankTransaction): JsonResponse
    {
        $this->confirmPassword($request);

        $released = $bankTransaction->payments()->pluck('id')->all();

        DB::transaction(function () use ($bankTransaction) {
            $bankTransaction->payments()->update(['bank_transaction_id' => null]);
            $bankTransaction->delete();
        });

        activity()->performedOn($bankTransaction)->causedBy($request->user())
            ->withProperties(['released_payments' => $released])
            ->log('bank_transaction.deleted');

        return response()->json([
            'message' => 'Transaction deleted.',
            'released_payments' => count($released),
        ]);
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

        // A movement generated from another payment already belongs to an
        // invoice; matching it again would let this payment rewrite or delete
        // money somebody else booked.
        if (PaymentBankMovement::isGenerated($bankTransaction)) {
            return $this->refuseMatch(
                'bank_transaction_id',
                'That movement was recorded from another invoice payment and cannot be matched.',
            );
        }

        $invoice = $data['target'] === 'payable'
            ? PayableInvoice::findOrFail($data['invoice_id'])
            : ReceivableInvoice::findOrFail($data['invoice_id']);

        if ((float) $data['amount'] > (float) $invoice->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Amount exceeds the invoice remaining balance.',
                'errors' => ['amount' => ['Amount exceeds the invoice remaining balance.']],
            ], 422);
        }

        // Direction is the movement's own: money that left the bank cannot have
        // settled a client invoice, and money that arrived cannot have paid a
        // supplier. Without this an expense of −800 could mark a receivable as
        // received.
        $net = (float) $bankTransaction->net_amount;
        $expected = $data['target'] === 'payable' ? 'expense' : 'income';

        if (($expected === 'expense' && $net > 0.001) || ($expected === 'income' && $net < -0.001)) {
            return $this->refuseMatch(
                'target',
                $expected === 'expense'
                    ? 'This movement brought money in, so it cannot settle a supplier invoice.'
                    : 'This movement paid money out, so it cannot settle a client invoice.',
            );
        }

        // One statement line can settle several invoices — a single transfer
        // covering a supplier's month is the ordinary case — but never more than
        // the line itself is worth. Without a cap, one 100 EUR row could be
        // matched to three 1,000 EUR invoices and settle 3,000 EUR.
        //
        // Measured in EUR on both sides: settlements from any module may point
        // at a movement now, and one entered in another currency compared
        // against a EUR movement would let the same line be over-allocated.
        $alreadyMatched = (float) $bankTransaction->payments()->sum('amount_eur');
        $capacity = abs((float) $bankTransaction->net_amount_eur);
        $incoming = (float) app(CurrencyConverter::class)->toEur(
            $data['amount'],
            (string) ($bankTransaction->currency ?: Currencies::BASE),
            $bankTransaction->exchange_rate,
            optional($bankTransaction->date)->toDateString(),
        )['amount_eur'];

        if ($alreadyMatched + $incoming > $capacity + 0.001) {
            $left = max(0, round($capacity - $alreadyMatched, 2));

            return $this->refuseMatch(
                'amount',
                "This movement has only {$left} left to allocate against invoices.",
            );
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

    /** A refused match, shaped like a validation error so the form can show it on the field. */
    private function refuseMatch(string $field, string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => [$field => [$message]],
        ], 422);
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
