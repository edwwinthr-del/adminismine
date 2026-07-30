<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\GenerateRentRequest;
use App\Http\Requests\Housing\RecordHousingPaymentRequest;
use App\Http\Requests\Housing\StoreRentPaymentRequest;
use App\Http\Requests\Housing\UpdateRentPaymentRequest;
use App\Http\Resources\RentPaymentResource;
use App\Models\RentPayment;
use App\Services\RentObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentPaymentController extends Controller
{
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
        DB::transaction(function () use ($rentPayment) {
            $rentPayment->payments()->delete();
            $rentPayment->delete();
        });

        activity()->performedOn($rentPayment)->causedBy($request->user())->log('rent_payment.deleted');

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

    public function recordPayment(RecordHousingPaymentRequest $request, RentPayment $rentPayment): JsonResponse
    {
        $data = $request->validated();

        if ((float) $data['amount'] > (float) $rentPayment->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Payment exceeds the remaining rent.',
                'errors' => ['amount' => ['Payment exceeds the remaining rent.']],
            ], 422);
        }

        DB::transaction(function () use ($rentPayment, $data) {
            $rentPayment->payments()->create($data + ['currency' => $rentPayment->currency]);
            $rentPayment->recalculate();
        });

        activity()->performedOn($rentPayment)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
            ->log('rent_payment.payment_recorded');

        return response()->json([
            'data' => new RentPaymentResource($rentPayment->fresh()->load('house', 'payments')),
        ], 201);
    }
}
