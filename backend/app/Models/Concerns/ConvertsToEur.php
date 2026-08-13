<?php

namespace App\Models\Concerns;

use App\Services\CurrencyConverter;
use App\Support\Currencies;

/**
 * Keeps a record's EUR figure in step with its own amount, currency and rate.
 *
 * On the model rather than in the controllers, for the reason the bank
 * movement's sign normalisation is: invoices and payments are also written by
 * the Excel importer, by seeders and by the assistant's confirm step, and an EUR
 * column that only the API maintained would be quietly wrong everywhere else —
 * which is the shape of the bug this whole change exists to end.
 *
 * Precedence follows CurrencyConverter: EUR converts 1:1 and stores no rate, a
 * rate pinned on the record wins (that is how an operator records what the bank
 * actually charged), otherwise the newest stored rate on or before the record's
 * date. With no rate available at all it throws MissingExchangeRateException,
 * which renders itself as a 422 on `exchange_rate`.
 */
trait ConvertsToEur
{
    public static function bootConvertsToEur(): void
    {
        static::saving(fn ($model) => $model->syncEurAmount());
    }

    public function syncEurAmount(): void
    {
        $currency = strtoupper((string) ($this->currency ?: Currencies::BASE));
        $amount = (float) $this->{$this->eurSourceColumn()};

        if ($currency === Currencies::BASE) {
            $this->exchange_rate = null;
            $this->exchange_rate_date = null;
            $this->amount_eur = round($amount, 2);

            return;
        }

        $previousRate = $this->exchange_rate;
        $previousDate = $this->exchange_rate_date;

        $converted = app(CurrencyConverter::class)->toEur(
            $amount,
            $currency,
            $previousRate,
            $this->eurRateDate(),
        );

        $this->amount_eur = $converted['amount_eur'];
        $this->exchange_rate = $converted['exchange_rate'];

        // Every later save re-enters through the "a rate is already pinned"
        // branch, which dates the conversion to the record rather than to the
        // rate it came from. Keeping the original date while the rate itself is
        // unchanged means recalculate() cannot quietly rewrite which rate the
        // figure was built from.
        $this->exchange_rate_date = $previousDate !== null
            && $previousRate !== null
            && (float) $previousRate === (float) $converted['exchange_rate']
                ? $previousDate
                : ($converted['exchange_rate_date'] ?? $this->eurRateDate());
    }

    /** The column holding the amount in the record's own currency. */
    abstract protected function eurSourceColumn(): string;

    /** The date the rate should be read as of. */
    abstract protected function eurRateDate(): ?string;
}
