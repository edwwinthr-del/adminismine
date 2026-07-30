<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreManualRateRequest;
use App\Models\ExchangeRate;
use App\Services\ExchangeRateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExchangeRateController extends Controller
{
    public function index(): JsonResponse
    {
        $latest = ExchangeRate::query()
            ->orderByDesc('rate_date')
            ->orderByDesc('id')
            ->get()
            ->groupBy('quote_currency')
            ->map(fn ($rows) => $rows->first())
            ->values();

        return response()->json([
            'data' => ExchangeRate::query()->orderByDesc('rate_date')->orderByDesc('id')->limit(100)->get(),
            'latest' => $latest,
        ]);
    }

    public function sync(Request $request, ExchangeRateService $service): JsonResponse
    {
        $quotes = (array) $request->input('quotes', ['TRY']);
        $stored = $service->sync($quotes);

        activity()->causedBy($request->user())
            ->withProperties(['quotes' => $quotes])
            ->log('exchange_rate.synced');

        return response()->json(['data' => $stored], 201);
    }

    public function storeManual(StoreManualRateRequest $request): JsonResponse
    {
        $data = $request->validated();

        $rate = ExchangeRate::updateOrCreate(
            [
                'base_currency' => $data['base_currency'] ?? 'EUR',
                'quote_currency' => $data['quote_currency'],
                'rate_date' => $data['rate_date'] ?? now()->toDateString(),
            ],
            [
                'rate' => $data['rate'],
                'is_manual' => true,
                'override_reason' => $data['override_reason'],
                'provider' => 'manual',
                'fetched_at' => now(),
            ],
        );

        activity()
            ->performedOn($rate)
            ->causedBy($request->user())
            ->withProperties(['reason' => $data['override_reason']])
            ->log('exchange_rate.manual_override');

        return response()->json(['data' => $rate], 201);
    }
}
