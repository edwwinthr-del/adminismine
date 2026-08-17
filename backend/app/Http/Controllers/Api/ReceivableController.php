<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Payable\RecordPaymentRequest;
use App\Http\Requests\Payable\UpdatePaymentRequest;
use App\Http\Requests\Receivable\RecordDeductionRequest;
use App\Http\Requests\Receivable\StoreReceivableRequest;
use App\Http\Requests\Receivable\UpdateDeductionRequest;
use App\Http\Requests\Receivable\UpdateReceivableRequest;
use App\Http\Resources\ReceivableInvoiceResource;
use App\Models\Client;
use App\Models\Payment;
use App\Models\ReceivableDeduction;
use App\Models\ReceivableInvoice;
use App\Services\InvoiceSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class ReceivableController extends Controller
{
    public function __construct(private readonly InvoiceSettlementService $settlements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ReceivableInvoice::query()->with('client');

        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->date('date_to'));
        }
        if ($request->boolean('overdue')) {
            $query->overdue();
        }
        $query->search($request->input('search'));

        $query->orderByDesc('invoice_date')->orderByDesc('id');

        return ReceivableInvoiceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StoreReceivableRequest $request): JsonResponse
    {
        $invoice = ReceivableInvoice::create($request->validated());
        $invoice->recalculate();

        activity()->performedOn($invoice)->causedBy($request->user())->log('receivable.created');

        return (new ReceivableInvoiceResource($invoice->load('client', 'payments', 'deductions')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ReceivableInvoice $receivable): ReceivableInvoiceResource
    {
        return new ReceivableInvoiceResource($receivable->load('client', 'payments', 'deductions'));
    }

    /**
     * Editable after it is paid, for the same reason a payable is: correcting a
     * record beats inventing an offsetting one. The amount may not drop below
     * what payments and deductions have already settled.
     */
    public function update(UpdateReceivableRequest $request, ReceivableInvoice $receivable): ReceivableInvoiceResource
    {
        $data = $request->validated();

        $this->settlements->assertAmountCoversSettlements($receivable, $data);

        $receivable->update($data);
        $receivable->recalculate();

        activity()->performedOn($receivable)->causedBy($request->user())->log('receivable.updated');

        return new ReceivableInvoiceResource($receivable->load('client', 'payments', 'deductions'));
    }

    public function destroy(Request $request, ReceivableInvoice $receivable): JsonResponse
    {
        // The movements those payments generated go with them — a bank row left
        // behind after the invoice that explains it is gone would keep adding
        // money to every balance on the dashboard. Movements typed off a real
        // statement are only released; they are the bank's record, not this
        // invoice's to erase.
        $removedMovements = DB::transaction(function () use ($receivable): array {
            $removed = $this->settlements->releasePayments($receivable);
            $receivable->deductions()->delete();
            $receivable->delete();

            return $removed;
        });

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties(['removed_bank_transactions' => $removedMovements])
            ->log('receivable.deleted');

        return response()->json(['message' => 'Receivable deleted.']);
    }

    /**
     * Record money received. When the payment is itself the record that the
     * money arrived, `book_bank_transaction` writes the matching bank/cash
     * movement — without it the receipt never reaches the balances, the
     * cashflow or the dashboard, all of which are summed from that table.
     */
    public function recordPayment(RecordPaymentRequest $request, ReceivableInvoice $receivable): JsonResponse
    {
        $data = $request->paymentData();
        $booking = $request->boolean('book_bank_transaction');

        $payment = $this->settlements->recordPayment($receivable, $data, $booking);

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties([
                'amount' => $data['amount'],
                'method' => $data['method'],
                'bank_transaction_id' => $payment->fresh()->bank_transaction_id,
                'booked_bank_transaction' => $booking,
            ])
            ->log('receivable.payment_recorded');

        return response()->json([
            'data' => new ReceivableInvoiceResource($receivable->fresh()->load('client', 'payments', 'deductions')),
        ], 201);
    }

    public function updatePayment(
        UpdatePaymentRequest $request,
        ReceivableInvoice $receivable,
        Payment $payment,
    ): ReceivableInvoiceResource {
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->settlements->updatePayment(
            $receivable,
            $payment,
            $request->paymentData(),
            book: $request->boolean('book_bank_transaction'),
        );

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('receivable.payment_updated');

        return $this->fresh($receivable);
    }

    /**
     * Undo a receipt. A movement this app generated from the payment goes with
     * it; one typed off a bank statement is only released, unless
     * `delete_bank_transaction` says it too was only ever this payment's record.
     */
    public function deletePayment(
        Request $request,
        ReceivableInvoice $receivable,
        Payment $payment,
    ): ReceivableInvoiceResource {
        $removed = $payment->only(['amount', 'payment_date', 'method', 'bank_transaction_id']);

        $this->settlements->deletePayment($receivable, $payment, $request->boolean('delete_bank_transaction'));

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'removed' => $removed])
            ->log('receivable.payment_deleted');

        return $this->fresh($receivable);
    }

    public function recordDeduction(RecordDeductionRequest $request, ReceivableInvoice $receivable): JsonResponse
    {
        $data = $request->validated();

        $this->settlements->recordDeduction($receivable, $data);

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'reason' => $data['reason'] ?? null])
            ->log('receivable.deduction_recorded');

        return response()->json([
            'data' => new ReceivableInvoiceResource($receivable->fresh()->load('client', 'payments', 'deductions')),
        ], 201);
    }

    public function updateDeduction(
        UpdateDeductionRequest $request,
        ReceivableInvoice $receivable,
        ReceivableDeduction $deduction,
    ): ReceivableInvoiceResource {
        $before = $deduction->only(['amount', 'deduction_date', 'reason']);

        $this->settlements->updateDeduction($receivable, $deduction, $request->validated());

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties(['deduction_id' => $deduction->id, 'before' => $before])
            ->log('receivable.deduction_updated');

        return $this->fresh($receivable);
    }

    public function deleteDeduction(
        Request $request,
        ReceivableInvoice $receivable,
        ReceivableDeduction $deduction,
    ): ReceivableInvoiceResource {
        $removed = $deduction->only(['amount', 'deduction_date', 'reason']);

        $this->settlements->deleteDeduction($receivable, $deduction);

        activity()->performedOn($receivable)->causedBy($request->user())
            ->withProperties(['deduction_id' => $deduction->id, 'removed' => $removed])
            ->log('receivable.deduction_deleted');

        return $this->fresh($receivable);
    }

    /**
     * Client account statement (the Uniprom statement, generalized to any
     * client): a chronological ledger of invoices (debit), payments and
     * deductions (credit) with a running balance.
     */
    public function statement(Request $request): JsonResponse
    {
        $request->validate(['client_id' => ['required', 'integer', 'exists:klijenti,id']]);

        $client = Client::findOrFail($request->integer('client_id'));
        $invoices = ReceivableInvoice::with(['payments', 'deductions'])
            ->where('client_id', $client->id)
            ->get();

        $entries = [];
        foreach ($invoices as $invoice) {
            $entries[] = [
                'date' => optional($invoice->invoice_date)->toDateString(),
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'debit' => (float) $invoice->invoice_amount,
                'credit' => 0.0,
            ];
            foreach ($invoice->payments as $payment) {
                $entries[] = [
                    'date' => optional($payment->payment_date)->toDateString(),
                    'type' => 'payment',
                    'reference' => $payment->reference,
                    'debit' => 0.0,
                    'credit' => (float) $payment->amount,
                ];
            }
            foreach ($invoice->deductions as $deduction) {
                $entries[] = [
                    'date' => optional($deduction->deduction_date)->toDateString(),
                    'type' => 'deduction',
                    'reference' => $deduction->reason,
                    'debit' => 0.0,
                    'credit' => (float) $deduction->amount,
                ];
            }
        }

        usort($entries, fn ($a, $b) => strcmp($a['date'] ?? '9999-12-31', $b['date'] ?? '9999-12-31'));

        $balance = 0.0;
        foreach ($entries as &$entry) {
            $balance = round($balance + $entry['debit'] - $entry['credit'], 2);
            $entry['balance'] = $balance;
        }
        unset($entry);

        return response()->json([
            'client' => ['id' => $client->id, 'name' => $client->name],
            'totals' => [
                'invoiced' => round((float) $invoices->sum(fn ($i) => (float) $i->invoice_amount), 2),
                'received' => round((float) $invoices->sum(fn ($i) => (float) $i->received_amount), 2),
                'deducted' => round((float) $invoices->sum(fn ($i) => (float) $i->deducted_amount), 2),
                'remaining' => round((float) $invoices->sum(fn ($i) => (float) $i->remaining_amount), 2),
            ],
            'entries' => $entries,
        ]);
    }

    private function fresh(ReceivableInvoice $receivable): ReceivableInvoiceResource
    {
        return new ReceivableInvoiceResource($receivable->fresh()->load('client', 'payments', 'deductions'));
    }
}
