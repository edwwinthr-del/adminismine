<?php

namespace App\Services;

use App\Models\ExchangeRate;
use Illuminate\Support\Facades\Http;

class ExchangeRateService
{
    public function baseCurrency(): string
    {
        return config('services.exchange_rate.base', 'EUR');
    }

    /**
     * Pull the latest rates for the given quote currencies from the configured
     * provider and store them (one row per pair per day). Manual overrides for
     * the same day are left untouched.
     *
     * @param  array<int,string>  $quotes
     * @return array<int,ExchangeRate>
     */
    public function sync(array $quotes = ['TRY']): array
    {
        $base = $this->baseCurrency();
        $url = rtrim((string) config('services.exchange_rate.url', 'https://api.frankfurter.app'), '/');

        // Params cover both frankfurter (from/to) and exchangerate.host (base/symbols).
        $response = Http::acceptJson()->get($url.'/latest', [
            'base' => $base,
            'from' => $base,
            'symbols' => implode(',', $quotes),
            'to' => implode(',', $quotes),
        ]);
        $response->throw();

        $data = $response->json();
        $date = $data['date'] ?? now()->toDateString();
        $rates = $data['rates'] ?? [];

        $stored = [];
        foreach ($quotes as $quote) {
            if (! isset($rates[$quote])) {
                continue;
            }

            $existing = ExchangeRate::where('base_currency', $base)
                ->where('quote_currency', $quote)
                ->where('rate_date', $date)
                ->first();

            // Never clobber a manual override for the day.
            if ($existing && $existing->is_manual) {
                $stored[] = $existing;

                continue;
            }

            $stored[] = ExchangeRate::updateOrCreate(
                ['base_currency' => $base, 'quote_currency' => $quote, 'rate_date' => $date],
                [
                    'rate' => $rates[$quote],
                    'provider' => config('services.exchange_rate.provider', 'frankfurter'),
                    'is_manual' => false,
                    'fetched_at' => now(),
                ],
            );
        }

        return $stored;
    }
}
