<?php

namespace App\Services;

use App\Exceptions\MissingExchangeRateException;
use App\Models\ExchangeRate;

/**
 * Turns an amount in any currency into the accounting currency (EUR) and hands
 * back the rate that produced it, so the record can store both. Rate convention
 * matches ExchangeRate: 1 base = `rate` quote, i.e. EUR = amount / rate.
 *
 * A user-supplied rate always wins — that is how an operator records the rate
 * the bank actually charged rather than the daily reference rate.
 */
class CurrencyConverter
{
    public function baseCurrency(): string
    {
        return strtoupper((string) config('services.exchange_rate.base', 'EUR'));
    }

    /**
     * The stored rate to use for a date: the newest rate on or before it, and
     * only if none exists, the newest rate overall.
     */
    public function rateOn(string $quote, ?string $date = null): ?ExchangeRate
    {
        $base = $this->baseCurrency();
        $quote = strtoupper($quote);

        $query = fn () => ExchangeRate::query()
            ->where('base_currency', $base)
            ->where('quote_currency', $quote)
            ->orderByDesc('rate_date')
            ->orderByDesc('id');

        if ($date !== null) {
            $onOrBefore = $query()->whereDate('rate_date', '<=', $date)->first();

            if ($onOrBefore !== null) {
                return $onOrBefore;
            }
        }

        return $query()->first();
    }

    /**
     * @return array{amount_eur: float, exchange_rate: float|null, exchange_rate_date: string|null}
     *
     * @throws MissingExchangeRateException when a conversion is needed but no rate exists
     */
    public function toEur(
        float|int|string $amount,
        string $currency,
        float|int|string|null $rate = null,
        ?string $date = null,
    ): array {
        $amount = (float) $amount;
        $currency = strtoupper($currency);

        if ($currency === $this->baseCurrency()) {
            return [
                'amount_eur' => round($amount, 2),
                'exchange_rate' => null,
                'exchange_rate_date' => null,
            ];
        }

        if ($rate !== null && (float) $rate > 0) {
            return [
                'amount_eur' => round($amount / (float) $rate, 2),
                'exchange_rate' => (float) $rate,
                'exchange_rate_date' => $date,
            ];
        }

        $stored = $this->rateOn($currency, $date);

        if ($stored === null) {
            throw new MissingExchangeRateException($currency, $date);
        }

        return [
            'amount_eur' => round($stored->toBase($amount), 2),
            'exchange_rate' => (float) $stored->rate,
            'exchange_rate_date' => $stored->rate_date->toDateString(),
        ];
    }

    /**
     * Merge the converted values into a validated payload. `$amountKey` is the
     * original-currency amount; the EUR columns are always derived here and are
     * never accepted from the request.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws MissingExchangeRateException
     */
    public function fill(array $data, string $amountKey = 'amount', string $dateKey = 'date'): array
    {
        $converted = $this->toEur(
            $data[$amountKey] ?? 0,
            $data['currency'] ?? $this->baseCurrency(),
            $data['exchange_rate'] ?? null,
            isset($data[$dateKey]) ? (string) $data[$dateKey] : null,
        );

        return array_merge($data, $converted);
    }
}
