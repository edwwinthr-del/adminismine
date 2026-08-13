<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\CorrectsPayments;
use App\Http\Controllers\Controller;
use App\Http\Requests\Salary\GenerateSalaryPaymentsRequest;
use App\Http\Requests\Salary\RecordSalaryPaymentRequest;
use App\Http\Requests\Salary\StoreSalaryPaymentRequest;
use App\Http\Requests\Salary\UpdateSalaryPaymentRequest;
use App\Http\Resources\SalaryPaymentResource;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Services\SalaryObligationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class SalaryPaymentController extends Controller
{
    use CorrectsPayments;

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = SalaryPayment::query()->with('employee');

        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }

        $query->orderByDesc('salary_month')->orderBy('employee_id');

        return SalaryPaymentResource::collection($query->paginate($request->integer('per_page', 100)));
    }

    public function store(StoreSalaryPaymentRequest $request): JsonResponse
    {
        $obligation = SalaryPayment::create($request->validated());
        $obligation->recalculate();

        activity()->performedOn($obligation)->causedBy($request->user())->log('salary_payment.created');

        return (new SalaryPaymentResource($obligation->load('employee', 'payments')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(SalaryPayment $salaryPayment): SalaryPaymentResource
    {
        return new SalaryPaymentResource($salaryPayment->load('employee', 'payments'));
    }

    public function update(UpdateSalaryPaymentRequest $request, SalaryPayment $salaryPayment): SalaryPaymentResource
    {
        $salaryPayment->update($request->validated());
        $salaryPayment->recalculate();

        activity()->performedOn($salaryPayment)->causedBy($request->user())->log('salary_payment.updated');

        return new SalaryPaymentResource($salaryPayment->load('employee', 'payments'));
    }

    public function destroy(Request $request, SalaryPayment $salaryPayment): JsonResponse
    {
        DB::transaction(function () use ($salaryPayment) {
            $salaryPayment->payments()->delete();
            $salaryPayment->delete();
        });

        activity()->performedOn($salaryPayment)->causedBy($request->user())->log('salary_payment.deleted');

        return response()->json(['message' => 'Salary record deleted.']);
    }

    /**
     * Create the month's obligations from the active employees' salary settings.
     * `preview=1` returns what would be created without saving anything.
     */
    public function generate(GenerateSalaryPaymentsRequest $request, SalaryObligationService $service): JsonResponse
    {
        $preview = $request->boolean('preview');
        $result = $service->generate($request->input('month'), $preview);

        if (! $preview) {
            activity()->causedBy($request->user())
                ->withProperties(['month' => $result['month'], 'created' => $result['created']->count()])
                ->log('salary_payments.generated');
        }

        return response()->json([
            'data' => SalaryPaymentResource::collection($result['created']),
            'meta' => [
                'month' => $result['month'],
                'preview' => $preview,
                'created_count' => $result['created']->count(),
                'skipped' => $result['skipped'],
                'already_existing_count' => count($result['existing']),
            ],
        ], $preview ? 200 : 201);
    }

    public function recordPayment(RecordSalaryPaymentRequest $request, SalaryPayment $salaryPayment): JsonResponse
    {
        $data = $request->validated();

        if ((float) $data['amount'] > (float) $salaryPayment->remaining_amount + 0.001) {
            return response()->json([
                'message' => 'Payment exceeds the remaining salary amount.',
                'errors' => ['amount' => ['Payment exceeds the remaining salary amount.']],
            ], 422);
        }

        DB::transaction(function () use ($salaryPayment, $data) {
            $salaryPayment->payments()->create($data + ['currency' => $salaryPayment->currency]);
            $salaryPayment->recalculate();
        });

        activity()->performedOn($salaryPayment)->causedBy($request->user())
            ->withProperties(['amount' => $data['amount'], 'method' => $data['method']])
            ->log('salary_payment.payment_recorded');

        return response()->json([
            'data' => new SalaryPaymentResource($salaryPayment->fresh()->load('employee', 'payments')),
        ], 201);
    }

    /** Totals for a month (defaults to the current one) plus the unpaid head-count. */
    public function summary(Request $request): JsonResponse
    {
        $month = SalaryPayment::normalizeMonth($request->input('month', now()->toDateString()));

        $rows = SalaryPayment::query()->forMonth($month)->get();

        return response()->json([
            'data' => [
                'month' => $month,
                'employees' => $rows->count(),
                'net_due' => round((float) $rows->sum('net_salary_due'), 2),
                'paid' => round((float) $rows->sum('paid_amount'), 2),
                'remaining' => round((float) $rows->sum('remaining_amount'), 2),
                'unpaid_count' => $rows->where('status', 'unpaid')->count(),
                'partial_count' => $rows->where('status', 'partial')->count(),
                'paid_count' => $rows->where('status', 'paid')->count(),
            ],
        ]);
    }

    /**
     * Correct a payment that was entered wrong, rather than booking its opposite.
     *
     * See CorrectsPayments: two rows that cancel out would both read as real
     * money in every report and in the bank match.
     */
    public function updatePayment(
        RecordSalaryPaymentRequest $request,
        SalaryPayment $salaryPayment,
        Payment $payment,
    ): JsonResponse {
        $before = $payment->only(['amount', 'payment_date', 'method']);

        $this->correctPayment($salaryPayment, $payment, $request->validated());

        activity()->performedOn($salaryPayment)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'before' => $before, 'after' => $request->validated()])
            ->log('salary_payment.payment_updated');

        return response()->json([
            'data' => new SalaryPaymentResource($salaryPayment->fresh()->load('employee', 'payments')),
        ]);
    }

    /** Remove a payment; the obligation's figures follow from the lines that are left. */
    public function deletePayment(Request $request, SalaryPayment $salaryPayment, Payment $payment): JsonResponse
    {
        $removed = $payment->only(['amount', 'payment_date', 'method']);

        $this->removePayment($salaryPayment, $payment);

        activity()->performedOn($salaryPayment)->causedBy($request->user())
            ->withProperties(['payment_id' => $payment->id, 'removed' => $removed])
            ->log('salary_payment.payment_deleted');

        return response()->json([
            'data' => new SalaryPaymentResource($salaryPayment->fresh()->load('employee', 'payments')),
        ]);
    }
}
