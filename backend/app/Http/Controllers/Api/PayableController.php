<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payable\RecordPaymentRequest;
use App\Http\Requests\Payable\StorePayableRequest;
use App\Http\Requests\Payable\UpdatePayableRequest;
use App\Http\Requests\Payable\UpdatePaymentRequest;
use App\Http\Resources\PayableInvoiceResource;
use App\Models\PayableInvoice;
use App\Models\Payment;
use App\Services\InvoiceSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class PayableController extends Controller
{
    use ConfirmsPassword;

    public function __construct(private readonly InvoiceSettlementService $settlements) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = PayableInvoice::query()->with('supplier');

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->integer('supplier_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('category')) {
            $query->where('expense_category', $request->input('category'));
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

        return PayableInvoiceResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    public function store(StorePayableRequest $request): JsonResponse
    {
        // The EUR figure and the rate behind it are derived by the model on save
        // (ConvertsToEur), so every write path gets them, not just this one.
        $invoice = PayableInvoice::create($request->validated());
        $invoice->recalculate();

        activity()->performedOn($invoice)->causedBy($request->user())->log('payable.created');

        return (new PayableInvoiceResource($invoice->load('supplier', 'payments')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(PayableInvoice $payable): PayableInvoiceResource
    {
        return new PayableInvoiceResource($payable->load('supplier', 'payments'));
    }

    /**
     * An invoice stays editable after it is paid: a wrong supplier, number, date
     * or amount is a bookkeeping error, and closing the record would only push
     * the correction into a second, fictitious entry. The one thing refused is
     * dropping the amount below what has already been settled against it.
     */
    public function update(UpdatePayableRequest $request, PayableInvoice $payable): PayableInvoiceResource
    {
        $data = $request->validated();

        $this->settlements->assertAmountCoversSettlements($payable, $data);

        $payable->update($data);
        $payable->recalculate();

        activity()->performedOn($payable)->causedBy($request->user())->log('payable.updated');

        return new PayableInvoiceResource($payable->load('supplier', 'payments'));
    }

    /**
     * Remove an invoice and the lines settling it.
     *
     * Gated on the signed-in user's own password (ConfirmsPassword): this is the
     * one action that leaves nothing behind but an audit row, so it is never
     * something an unattended session can do.
     */
    public function destroy(Request $request, PayableInvoice $payable): JsonResponse
    {
        $this->confirmPassword($request);

        $removed = [
            'invoice_number' => $payable->invoice_number,
            'supplier_id' => $payable->supplier_id,
            'original_amount' => $payable->original_amount,
            // Bank movements these payments were matched to: the link lives on
            // the payment rows, so it goes with them and the movements stay,
            // unmatched, as the bank statement's own record.
            'released_bank_transactions' => $payable->payments()
                ->whereNotNull('bank_transaction_id')
                ->pluck('bank_transaction_id')
                ->all(),
        ];

        // Movements generated from these payments are the exception: nothing
        // would explain them once the invoice is gone, and they would go on
        // counting against every balance the dashboard shows.
        $removed['removed_bank_transactions'] = DB::transaction(function () use ($payable): array {
            $generated = $this->settlements->releasePayments($payable);
            $payable->delete();

            return $generated;
        });

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties(['removed' => $removed])
            ->log('payable.deleted');

        return response()->json(['message' => 'Payable deleted.']);
    }

    /**
     * Record money paid out. When the payment is itself the record that the
     * money left, `book_bank_transaction` writes the matching bank/cash
     * movement — without it the payment never reaches the balances, the
     * cashflow or the dashboard, all of which are summed from that table.
     */
    public function recordPayment(RecordPaymentRequest $request, PayableInvoice $payable): JsonResponse
    {
        $data = $request->paymentData();
        $booking = $request->boolean('book_bank_transaction');

        $payment = $this->settlements->recordPayment($payable, $data, $booking);

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties([
                'amount' => $data['amount'],
                'account_id' => $data['account_id'] ?? null,
                'bank_transaction_id' => $payment->fresh()->bank_transaction_id,
                'booked_bank_transaction' => $booking,
            ])
            ->log('payable.payment_recorded');

        return response()->json([
            'data' => new PayableInvoiceResource($payable->fresh()->load('supplier', 'payments')),
        ], 201);
    }

    /** Correct a payment that was entered wrong, rather than booking its opposite. */
    public function updatePayment(
        UpdatePaymentRequest $request,
        PayableInvoice $payable,
        Payment $payment,
    ): PayableInvoiceResource {
        $before = $payment->only(['amount', 'payment_date', 'account_id']);

        $this->settlements->updatePayment(
            $payable,
            $payment,
            $request->paymentData(),
            book: $request->boolean('book_bank_transaction'),
        );

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('payable.payment_updated');

        return new PayableInvoiceResource($payable->fresh()->load('supplier', 'payments'));
    }

    /**
     * Undo a settlement. The invoice's status follows from the lines that are
     * left, and the matched bank movement is released — optionally removed with
     * it. See InvoiceSettlementService::deletePayment for why that is a choice
     * rather than a cascade.
     */
    public function deletePayment(Request $request, PayableInvoice $payable, Payment $payment): PayableInvoiceResource
    {
        $this->confirmPassword($request);

        $removed = $payment->only(['amount', 'payment_date', 'account_id', 'bank_transaction_id']);
        $alsoDeleteMovement = $request->boolean('delete_bank_transaction');

        $this->settlements->deletePayment($payable, $payment, $alsoDeleteMovement);

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties([
                'payment_id' => $payment->id,
                'removed' => $removed,
                'bank_transaction_deleted' => $alsoDeleteMovement,
            ])
            ->log('payable.payment_deleted');

        return new PayableInvoiceResource($payable->fresh()->load('supplier', 'payments'));
    }
}
