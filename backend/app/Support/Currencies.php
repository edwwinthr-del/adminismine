<?php

namespace App\Support;

use Illuminate\Validation\Rule;

/**
 * The currencies this app will accept on a financial record.
 *
 * `size:3` was the only check, which meant any three characters were a
 * currency: `ZZZ` and `123` were both stored happily. That is not merely untidy
 * — the frontend formats money with `Intl.NumberFormat({style: 'currency'})`,
 * which throws a RangeError on a non-currency code, and the app has no error
 * boundary, so a single bad row white-screened the bank list and the dashboard
 * for every user with no way to fix it from the UI.
 *
 * The list matches what the import templates offer (see ImportCatalogue), so a
 * file the app hands out can never describe a currency the API refuses.
 */
final class Currencies
{
    /** @var list<string> */
    public const SUPPORTED = ['EUR', 'TRY', 'USD'];

    /** The accounting currency. Every EUR figure is stored, never re-derived. */
    public const BASE = 'EUR';

    /**
     * Validation rules for an optional currency column.
     *
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return ['sometimes', 'string', Rule::in(self::SUPPORTED)];
    }
}
