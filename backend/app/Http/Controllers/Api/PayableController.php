<?php

namespace App\Http\Controllers\Api;

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

    public function destroy(Request $request, PayableInvoice $payable): JsonResponse
    {
        DB::transaction(function () use ($payable) {
            $payable->payments()->delete();
            $payable->delete();
        });

        activity()->performedOn($payable)->causedBy($request->user())->log('payable.deleted');

        return response()->json(['message' => 'Payable deleted.']);
    }

    public function recordPayment(RecordPaymentRequest $request, PayableInvoice $payable): JsonResponse
    {
        $data = $request->validated();

        $this->settlements->recordPayment($payable, $data);

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
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
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->settlements->updatePayment($payable, $payment, $request->validated());

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('payable.payment_updated');

        return new PayableInvoiceResource($payable->fresh()->load('supplier', 'payments'));
    }

    public function deletePayment(Request $request, PayableInvoice $payable, Payment $payment): PayableInvoiceResource
    {
        $removed = $payment->only(['amount', 'payment_date', 'method', 'bank_transaction_id']);

        $this->settlements->deletePayment($payable, $payment);

        activity()->performedOn($payable)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'removed' => $removed])
            ->log('payable.payment_deleted');

        return new PayableInvoiceResource($payable->fresh()->load('supplier', 'payments'));
    }
}
