<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\SettlesWithPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\GenerateRentRequest;
use App\Http\Requests\Housing\RecordHousingPaymentRequest;
use App\Http\Requests\Housing\StoreRentPaymentRequest;
use App\Http\Requests\Housing\UpdateRentPaymentRequest;
use App\Http\Resources\RentPaymentResource;
use App\Models\Payment;
use App\Models\RentPayment;
use App\Services\RentObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentPaymentController extends Controller
{
    use SettlesWithPayments;

    public function index(Request $request): JsonResponse
    {
        $query = RentPayment::query()->with('house');

        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('house_id')) {
            $query->where('house_id', $request->integer('house_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }

        $query->orderByDesc('month')->orderBy('house_id');

        return response()->json([
            'data' => RentPaymentResource::collection($query->get()),
        ]);
    }

    public function store(StoreRentPaymentRequest $request): JsonResponse
    {
        $rent = RentPayment::create($request->validated());
        $rent->recalculate();

        activity()->performedOn($rent)->causedBy($request->user())->log('rent_payment.created');

        return (new RentPaymentResource($rent->load('house', 'payments')))->response()->setStatusCode(201);
    }

    public function update(UpdateRentPaymentRequest $request, RentPayment $rentPayment): RentPaymentResource
    {
        $rentPayment->update($request->validated());
        $rentPayment->recalculate();

        activity()->performedOn($rentPayment)->causedBy($request->user())->log('rent_payment.updated');

        return new RentPaymentResource($rentPayment->load('house', 'payments'));
    }

    public function destroy(Request $request, RentPayment $rentPayment): JsonResponse
    {
        $released = DB::transaction(function () use ($rentPayment): array {
            // The movements those payments booked go with them: an obligation
            // that no longer exists cannot leave money on the dashboard.
            $released = $this->releaseSettlements($rentPayment);
            $rentPayment->delete();

            return $released;
        });

        activity()->performedOn($rentPayment)->causedBy($request->user())
            ->withProperties(['released_bank_transactions' => $released])
            ->log('rent_payment.deleted');

        return response()->json(['message' => 'Rent record deleted.']);
    }

    /** Create the month's rent obligations from the active houses. */
    public function generate(GenerateRentRequest $request, RentObligationService $service): JsonResponse
    {
        $preview = $request->boolean('preview');
        $result = $service->generate($request->input('month'), $preview);

        if (! $preview) {
            activity()->causedBy($request->user())
                ->withProperties(['month' => $result['month'], 'created' => $result['created']->count()])
                ->log('rent_payments.generated');
        }

        return response()->json([
            'data' => RentPaymentResource::collection($result['created']),
            'meta' => [
                'month' => $result['month'],
                'preview' => $preview,
                'created_count' => $result['created']->count(),
                'skipped' => $result['skipped'],
                'already_existing_count' => count($result['existing']),
            ],
        ], $preview ? 200 : 201);
    }

    /**
     * Settle rent. `book_bank_transaction` writes the movement the payment
     * records, which is what puts it on the dashboard's balances and expenses —
     * see SettlesWithPayments.
     */
    public function recordPayment(RecordHousingPaymentRequest $request, RentPayment $rentPayment): JsonResponse
    {
        $data = $request->paymentData();

        $payment = $this->recordSettlement(
            $rentPayment,
            $data,
            (string) $rentPayment->currency,
            $request->booksMovement(),
        );

        activity()->performedOn($rentPayment)->causedBy($request->user())
            ->withProperties([
                'amount' => $data['amount'],
                'account_id' => $data['account_id'] ?? null,
                'bank_transaction_id' => $payment->bank_transaction_id,
            ])
            ->log('rent_payment.payment_recorded');

        return response()->json([
            'data' => new RentPaymentResource($rentPayment->fresh()->load('house', 'payments')),
        ], 201);
    }

    /**
     * Correct a payment that was entered wrong, rather than booking its opposite.
     *
     * See SettlesWithPayments: two rows that cancel out would both read as real
     * money in every report and in the bank match. A movement this app booked
     * from the payment follows the correction.
     */
    public function updatePayment(
        RecordHousingPaymentRequest $request,
        RentPayment $rentPayment,
        Payment $payment,
    ): JsonResponse {
        $before = $payment->only(['amount', 'payment_date', 'account_id']);

        $this->correctSettlement($rentPayment, $payment, $request->paymentData(), $request->booksMovement());

        activity()->performedOn($rentPayment)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->paymentData()])
            ->log('rent_payment.payment_updated');

        return response()->json([
            'data' => new RentPaymentResource($rentPayment->fresh()->load('house', 'payments')),
        ]);
    }

    /** Remove a payment; the obligation's figures follow from the lines that are left. */
    public function deletePayment(Request $request, RentPayment $rentPayment, Payment $payment): JsonResponse
    {
        $removed = $payment->only(['amount', 'payment_date', 'account_id']);

        $movement = $this->removeSettlement($rentPayment, $payment);

        activity()->performedOn($rentPayment)->causedBy($request->user())
            ->withProperties([
                'payment_id' => $payment->id,
                'removed' => $removed,
                'released_bank_transaction' => $movement,
            ])
            ->log('rent_payment.payment_deleted');

        return response()->json([
            'data' => new RentPaymentResource($rentPayment->fresh()->load('house', 'payments')),
        ]);
    }
}
