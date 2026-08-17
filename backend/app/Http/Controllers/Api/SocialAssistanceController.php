<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Travel\StoreSocialAssistanceRequest;
use App\Http\Requests\Travel\UpdateSocialAssistanceRequest;
use App\Http\Resources\SocialAssistancePaymentResource;
use App\Models\SocialAssistancePayment;
use App\Services\CurrencyConverter;
use App\Services\SocialAssistanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialAssistanceController extends Controller
{
    public function __construct(private readonly CurrencyConverter $converter) {}

    public function index(Request $request): JsonResponse
    {
        $query = SocialAssistancePayment::query()->with('employee:id,first_name,last_name');

        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('year')) {
            $query->forYear($request->integer('year'));
        }
        if ($request->filled('from')) {
            $query->whereDate('payment_date', '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->whereDate('payment_date', '<=', $request->input('to'));
        }

        $query->search($request->input('search'));

        $query->orderByDesc('payment_date')->orderByDesc('id');

        return response()->json([
            'data' => SocialAssistancePaymentResource::collection($query->limit(500)->get()),
        ]);
    }

    /** One payout, for the row's detail view. */
    public function show(SocialAssistancePayment $socialAssistance): SocialAssistancePaymentResource
    {
        return new SocialAssistancePaymentResource($socialAssistance->load('employee'));
    }

    /** Paid vs still payable per worker for a year — the workbook's ODENEBILIR column. */
    public function summary(Request $request, SocialAssistanceService $assistance): JsonResponse
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'employee_id' => ['nullable', 'integer', 'exists:radnici,id'],
        ]);

        return response()->json([
            'data' => $assistance->summary(
                $request->integer('year') ?: (int) now()->year,
                $request->filled('employee_id') ? $request->integer('employee_id') : null,
            ),
        ]);
    }

    public function store(StoreSocialAssistanceRequest $request): JsonResponse
    {
        $data = $this->converter->fill($request->validated(), dateKey: 'payment_date');

        $payment = SocialAssistancePayment::create($data);

        activity()->performedOn($payment)->causedBy($request->user())->log('social_assistance.created');

        return (new SocialAssistancePaymentResource($payment->load('employee')))->response()->setStatusCode(201);
    }

    public function update(
        UpdateSocialAssistanceRequest $request,
        SocialAssistancePayment $socialAssistance,
    ): SocialAssistancePaymentResource {
        $data = $request->validated();

        if (array_intersect_key($data, array_flip(['amount', 'currency', 'exchange_rate', 'payment_date'])) !== []) {
            $data = $this->converter->fill($data + [
                'amount' => $socialAssistance->amount,
                'currency' => $socialAssistance->currency,
                'exchange_rate' => $socialAssistance->exchange_rate,
                'payment_date' => optional($socialAssistance->payment_date)->toDateString(),
            ], dateKey: 'payment_date');
        }

        $socialAssistance->update($data);

        activity()->performedOn($socialAssistance)->causedBy($request->user())->log('social_assistance.updated');

        return new SocialAssistancePaymentResource($socialAssistance->load('employee'));
    }

    public function destroy(Request $request, SocialAssistancePayment $socialAssistance): JsonResponse
    {
        $socialAssistance->delete();

        activity()->performedOn($socialAssistance)->causedBy($request->user())->log('social_assistance.deleted');

        return response()->json(['message' => 'Social assistance payment deleted.']);
    }
}
