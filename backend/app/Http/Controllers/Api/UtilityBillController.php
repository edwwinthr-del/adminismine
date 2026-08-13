<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\CorrectsPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\RecordHousingPaymentRequest;
use App\Http\Requests\Housing\SplitBillRequest;
use App\Http\Requests\Housing\StoreUtilityBillRequest;
use App\Http\Requests\Housing\UpdateUtilityBillRequest;
use App\Http\Resources\HousingDeductionResource;
use App\Http\Resources\UtilityBillResource;
use App\Models\HousingDeduction;
use App\Models\Payment;
use App\Models\UtilityBill;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UtilityBillController extends Controller
{
    use CorrectsPayments;

    public function index(Request $request): JsonResponse
    {
        $query = UtilityBill::query()->with('house')->withCount('attachments');

        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('house_id')) {
            $query->where('house_id', $request->integer('house_id'));
        }
        if ($request->filled('bill_type')) {
            $query->where('bill_type', $request->input('bill_type'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }
        if ($request->boolean('overdue')) {
            $query->overdue();
        }

        $query->orderByDesc('billing_period')->orderBy('house_id');

        return response()->json([
            'data' => UtilityBillResource::collection($query->get()),
        ]);
    }

    public function store(StoreUtilityBillRequest $request): JsonResponse
    {
        $bill = UtilityBill::create($request->validated());
        $bill->recalculate();

        activity()->performedOn($bill)->causedBy($request->user())->log('utility_bill.created');

        return (new UtilityBillResource($bill->load('house')))->response()->setStatusCode(201);
    }

    public function show(UtilityBill $bill): UtilityBillResource
    {
        return new UtilityBillResource($bill->load('house', 'payments', 'attachments'));
    }

    public function update(UpdateUtilityBillRequest $request, UtilityBill $bill): UtilityBillResource
    {
        $bill->update($request->validated());
        $bill->recalculate();

        activity()->performedOn($bill)->causedBy($request->user())->log('utility_bill.updated');

        return new UtilityBillResource($bill->load('house', 'payments'));
    }

    public function destroy(Request $request, UtilityBill $bill, FileAttachmentService $files): JsonResponse
    {
        DB::transaction(function () use ($bill, $files) {
            $files->deleteAllFor($bill);
            $bill->payments()->delete();
            $bill->delete();
        });

        activity()->performedOn($bill)->causedBy($request->user())->log('utility_bill.deleted');

        return response()->json(['message' => 'Utility bill deleted.']);
    }

    public function recordPayment(RecordHousingPaymentRequest $request, UtilityBill $bill): JsonResponse
    {
        $data = $request->validated();

        if ((float) $data['amount'] > (float) $bill->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Payment exceeds the remaining amount on this bill.',
                'errors' => ['amount' => ['Payment exceeds the remaining amount on this bill.']],
            ], 422);
        }

        DB::transaction(function () use ($bill, $data) {
            $bill->payments()->create($data + ['currency' => $bill->currency]);
            $bill->recalculate();
        });

        activity()->performedOn($bill)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
            ->log('utility_bill.payment_recorded');

        return response()->json([
            'data' => new UtilityBillResource($bill->fresh()->load('house', 'payments')),
        ], 201);
    }

    /**
     * The explicit exception: charge a bill to the workers living in the house.
     * Bills are company costs by default, so this only ever runs when an
     * authorized user asks for it and gives a reason.
     */
    public function split(SplitBillRequest $request, UtilityBill $bill): JsonResponse
    {
        $data = $request->validated();
        $amount = round((float) ($data['amount'] ?? $bill->amount), 2);

        $occupantIds = $data['employee_ids'] ?? $bill->house
            ->currentOccupancies()
            ->pluck('employee_id')
            ->unique()
            ->values()
            ->all();

        if ($occupantIds === []) {
            return response()->json([
                'message' => 'This house has no current occupants to split the bill between.',
            ], 422);
        }

        $share = round($amount / count($occupantIds), 2);

        $deductions = DB::transaction(function () use ($bill, $occupantIds, $share, $data) {
            $rows = [];

            foreach ($occupantIds as $employeeId) {
                $deduction = HousingDeduction::create([
                    'employee_id' => $employeeId,
                    'house_id' => $bill->house_id,
                    'month' => $bill->billing_period->toDateString(),
                    'currency' => $bill->currency,
                    'utility_share' => $share,
                    'reason' => $data['reason'],
                    'utility_bill_id' => $bill->id,
                    'source' => 'bill_split',
                ]);
                $deduction->recalculate();

                $rows[] = $deduction->load('employee', 'house');
            }

            // The bill is no longer a plain company cost once it is charged on.
            $bill->update([
                'cost_bearer' => 'workers',
                'exception_reason' => $data['reason'],
            ]);

            return $rows;
        });

        activity()->performedOn($bill)->causedBy($request->user())
            ->withProperties([
                'reason' => $data['reason'],
                'amount' => $amount,
                'employee_ids' => $occupantIds,
                'share' => $share,
            ])
            ->log('utility_bill.split_to_workers');

        return response()->json([
            'data' => HousingDeductionResource::collection(collect($deductions)),
            'meta' => ['share' => $share, 'occupants' => count($occupantIds)],
        ], 201);
    }

    /**
     * Correct a payment that was entered wrong, rather than booking its opposite.
     *
     * See CorrectsPayments: two rows that cancel out would both read as real
     * money in every report and in the bank match.
     */
    public function updatePayment(
        RecordHousingPaymentRequest $request,
        UtilityBill $bill,
        Payment $payment,
    ): JsonResponse {
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->correctPayment($bill, $payment, $request->validated());

        activity()->performedOn($bill)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('utility_bill.payment_updated');

        return response()->json([
            'data' => new UtilityBillResource($bill->fresh()->load('house', 'payments')),
        ]);
    }

    /** Remove a payment; the obligation's figures follow from the lines that are left. */
    public function deletePayment(Request $request, UtilityBill $bill, Payment $payment): JsonResponse
    {
        $removed = $payment->only(['amount', 'payment_date', 'method']);

        $this->removePayment($bill, $payment);

        activity()->performedOn($bill)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'removed' => $removed])
            ->log('utility_bill.payment_deleted');

        return response()->json([
            'data' => new UtilityBillResource($bill->fresh()->load('house', 'payments')),
        ]);
    }
}
