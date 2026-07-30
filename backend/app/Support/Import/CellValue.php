<?php

namespace App\Support\Import;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

/**
 * Coercion for the workbook's cells. The same column mixes Excel date serials
 * (45432), dotted Turkish dates (06.03.2024) and slashed ones (13/05/2024), so
 * every read goes through here rather than being guessed at the call site.
 */
class CellValue
{
    /** Below this a number is far more likely a quantity than a date serial. */
    private const MIN_DATE_SERIAL = 20000;

    public static function string(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /** Upper-cased and whitespace-collapsed, for matching labels and names. */
    public static function key(mixed $value): ?string
    {
        $text = self::string($value);

        return $text === null ? null : mb_strtoupper(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A number, accepting both decimal separators. Thousands separators are only
     * stripped when they cannot be the decimal point, so "1.234,56" and "1,234.56"
     * both survive but "232.9" is never read as 2329.
     */
    public static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }

        $text = preg_replace('/[^\d,.\-]/', '', (string) $value);

        if ($text === '' || $text === '-') {
            return null;
        }

        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // Whichever comes last is the decimal separator.
            $decimal = $lastComma > $lastDot ? ',' : '.';
            $thousands = $decimal === ',' ? '.' : ',';
            $text = str_replace($thousands, '', $text);
            $text = str_replace($decimal, '.', $text);
        } elseif ($lastComma !== false) {
            $text = str_replace(',', '.', $text);
        }

        return is_numeric($text) ? (float) $text : null;
    }

    /**
     * A date as Y-m-d. Handles Excel serials plus d.m.Y, d/m/Y and Y-m-d text.
     * Day-first is assumed throughout: the workbook is European.
     */
    public static function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value >= self::MIN_DATE_SERIAL) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            } catch (Throwable) {
                return null;
            }
        }

        $text = trim((string) $value);

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})$/', $text, $matches) === 1) {
            [, $day, $month, $year] = $matches;

            return checkdate((int) $month, (int) $day, (int) $year)
                ? sprintf('%04d-%02d-%02d', $year, $month, $day)
                : null;
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $matches) === 1) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        return null;
    }

    /** True when every cell in the row is empty — the sheet's filler rows. */
    public static function isBlank(array $row): bool
    {
        foreach ($row as $cell) {
            if (self::string($cell) !== null) {
                return false;
            }
        }

        return true;
    }
}
