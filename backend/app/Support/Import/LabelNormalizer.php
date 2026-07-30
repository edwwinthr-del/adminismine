<?php

namespace App\Support\Import;

/**
 * Turns the workbook's Turkish and Serbian labels into the app's canonical enum
 * values (rule 4: store canonicals, never translated strings).
 *
 * The workbook is inconsistent on purpose-built typos — `GIDERR` for `GIDER`,
 * `GIDIS#` for `GIDIS` — so matching is done on a stripped key rather than an
 * exact string, and anything unrecognised returns null so the row can be flagged
 * for a human instead of being silently mis-filed.
 */
class LabelNormalizer
{
    /** Payment state: ODENDI = paid, ODENMEDI = not paid. */
    private const PAYMENT_STATUS = [
        'ODENDI' => 'paid',
        'ODENDİ' => 'paid',
        'PLACENO' => 'paid',
        'PLAĆENO' => 'paid',
        'ODENMEDI' => 'unpaid',
        'ODENMEDİ' => 'unpaid',
        'NEPLACENO' => 'unpaid',
        'NEPLAĆENO' => 'unpaid',
        'KISMEN' => 'partial',
        'DELIMICNO' => 'partial',
    ];

    /** Movement direction: GELIR = income, GIDER = expense. */
    private const CATEGORY = [
        'GELIR' => 'income',
        'GELİR' => 'income',
        'PRIHOD' => 'income',
        'GIDER' => 'expense',
        'GİDER' => 'expense',
        'GIDERR' => 'expense',
        'RASHOD' => 'expense',
        'TROSAK' => 'expense',
        'TRANSFER' => 'transfer',
        'PRENOS' => 'transfer',
        'MAAS' => 'payroll',
        'PLATA' => 'payroll',
        'KIRA' => 'housing',
        'STAN' => 'housing',
        'YOL' => 'travel',
        'PUT' => 'travel',
        'POZAJMICA' => 'loan',
        'BORC' => 'loan',
    ];

    /** Loan state: VRACENO = returned. */
    private const LOAN_STATUS = [
        'VRACENO' => 'repaid',
        'VRAĆENO' => 'repaid',
        'IADE' => 'repaid',
        'ODENDI' => 'repaid',
    ];

    /** Travel cost booked to the worker or not: YAZILDI = written. */
    private const COST_STATUS = [
        'YAZILDI' => 'written',
        'YAZILMADI' => 'not_written',
        'UPISANO' => 'written',
        'NIJE UPISANO' => 'not_written',
    ];

    /** Ticket direction. GIDIS = departure, DONUS = return, GITGEL = round trip. */
    private const DIRECTION = [
        'GIDIS' => 'departure',
        'GİDİŞ' => 'departure',
        'ODLAZAK' => 'departure',
        'DONUS' => 'arrival',
        'DÖNÜŞ' => 'arrival',
        'GELIS' => 'arrival',
        'ILK GELIS' => 'arrival',
        'DOLAZAK' => 'arrival',
        'GITGEL' => 'round_trip',
        'GIT GEL' => 'round_trip',
        'POVRATNA' => 'round_trip',
    ];

    /** Travel expense kind: ARABA = car, UCAK = flight. */
    private const EXPENSE_TYPE = [
        'ARABA' => 'car',
        'AUTO' => 'car',
        'UCAK' => 'flight',
        'UÇAK' => 'flight',
        'AVION' => 'flight',
        'OTOBUS' => 'bus',
        'AUTOBUS' => 'bus',
    ];

    /** Bank account state on the workers' sheet: TAMAM = done. */
    private const BANK_ACCOUNT_STATUS = [
        'TAMAM' => 'open',
        'ACIK' => 'open',
        'OTVOREN' => 'open',
        'BEKLEMEDE' => 'pending',
        'U TOKU' => 'pending',
        'YOK' => 'none',
        'NEMA' => 'none',
    ];

    public static function paymentStatus(?string $label): ?string
    {
        return self::match(self::PAYMENT_STATUS, $label);
    }

    public static function category(?string $label): ?string
    {
        return self::match(self::CATEGORY, $label);
    }

    public static function loanStatus(?string $label): ?string
    {
        return self::match(self::LOAN_STATUS, $label);
    }

    public static function costStatus(?string $label): ?string
    {
        return self::match(self::COST_STATUS, $label);
    }

    public static function direction(?string $label): ?string
    {
        return self::match(self::DIRECTION, $label);
    }

    public static function expenseType(?string $label): ?string
    {
        return self::match(self::EXPENSE_TYPE, $label);
    }

    public static function bankAccountStatus(?string $label): ?string
    {
        return self::match(self::BANK_ACCOUNT_STATUS, $label);
    }

    /**
     * Names are compared on a stripped key so "DOO Tadic Mia" and "DOO  TADIC
     * MIA" are recognised as the same company. Only ever used for matching —
     * the stored name keeps its original spelling.
     */
    public static function nameKey(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $key = mb_strtoupper(trim($name));
        $key = strtr($key, [
            'Č' => 'C', 'Ć' => 'C', 'Š' => 'S', 'Ž' => 'Z', 'Đ' => 'D',
            'İ' => 'I', 'I' => 'I', 'Ü' => 'U', 'Ö' => 'O', 'Ğ' => 'G', 'Ş' => 'S',
        ]);

        // Drop legal-form suffixes and punctuation so they never split a match.
        $key = preg_replace('/\b(DOO|D\.?O\.?O\.?|LTD|A\.?S\.?|AD)\b/u', ' ', $key);
        $key = preg_replace('/[^A-Z0-9 ]/u', ' ', $key);

        return trim(preg_replace('/\s+/', ' ', $key)) ?: null;
    }

    /** @param array<string, string> $table */
    private static function match(array $table, ?string $label): ?string
    {
        $key = CellValue::key($label);

        if ($key === null) {
            return null;
        }

        if (isset($table[$key])) {
            return $table[$key];
        }

        // The workbook carries stray marks like "GIDIS#" and "DONUS*".
        $stripped = trim(preg_replace('/[^\p{L}\p{N} ]/u', '', $key));

        return $table[$stripped] ?? null;
    }
}
