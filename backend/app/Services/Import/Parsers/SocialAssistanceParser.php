<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;
use App\Support\Import\LabelNormalizer;

/**
 * `S.YARDIM VE YOL MASRAF` — three independent blocks laid out side by side, so
 * one sheet row can produce up to three records:
 *
 *  A–F  SOSYAL YARDIM      BR | ODENEN TARIH | ADI | SOYADI | ODENEN MIKTAR | ODENEBILIR
 *  H–L  ODENEN YOL MASRAF  BR | ODENEN TARIH | ADI SOYADI | ODENEN MIKTAR | DURUM
 *  O–R  YAZILAN YOL MASRAF MJESEC | IME | UKUPNO | (ARABA/UCAK)
 *
 * The `ODENEBILIR` (still payable) column is deliberately not imported: the
 * remaining entitlement is computed from the payments (rule 1).
 */
class SocialAssistanceParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['S.YARDIM VE YOL MASRAF'];
    }

    /** Two rows of banner + column headers. */
    protected function headerRows(): int
    {
        return 2;
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        return array_values(array_filter([
            $this->assistance($cells, $rowNumber),
            $this->paidTravel($cells, $rowNumber),
            $this->writtenTravel($cells, $rowNumber),
        ]));
    }

    private function assistance(array $cells, int $rowNumber): ?ParsedRow
    {
        $amount = CellValue::number($this->cell($cells, 4));
        $first = CellValue::string($this->cell($cells, 2));
        $last = CellValue::string($this->cell($cells, 3));

        if ($amount === null || ($first === null && $last === null)) {
            return null;
        }

        $date = CellValue::date($this->cell($cells, 1));

        $row = new ParsedRow(
            target: 'social_assistance_payment',
            rowNumber: $rowNumber,
            raw: [
                'block' => 'SOSYAL YARDIM',
                'br' => CellValue::string($this->cell($cells, 0)),
                'odenen_tarih' => CellValue::string($this->cell($cells, 1)),
                'adi' => $first,
                'soyadi' => $last,
                'odenen_miktar' => CellValue::string($this->cell($cells, 4)),
                'odenebilir' => CellValue::string($this->cell($cells, 5)),
            ],
            mapped: [
                'person_name' => trim("{$first} {$last}"),
                'payment_date' => $date,
                'entitlement_year' => $date === null ? null : (int) substr($date, 0, 4),
                'currency' => 'EUR',
                'amount' => $amount,
            ],
        );

        return $this->flagIncomplete($row, ['payment_date', 'amount']);
    }

    private function paidTravel(array $cells, int $rowNumber): ?ParsedRow
    {
        $amount = CellValue::number($this->cell($cells, 10));
        $name = CellValue::string($this->cell($cells, 9));

        if ($amount === null || $name === null) {
            return null;
        }

        $date = CellValue::date($this->cell($cells, 8));

        $row = new ParsedRow(
            target: 'travel_expense',
            rowNumber: $rowNumber,
            raw: [
                'block' => 'ODENEN YOL MASRAFLARI',
                'br' => CellValue::string($this->cell($cells, 7)),
                'odenen_tarih' => CellValue::string($this->cell($cells, 8)),
                'adi_soyadi' => $name,
                'odenen_miktar' => CellValue::string($this->cell($cells, 10)),
                'durum' => CellValue::string($this->cell($cells, 11)),
            ],
            mapped: [
                'person_name' => $name,
                'expense_date' => $date,
                'period_month' => $date === null ? null : substr($date, 0, 7).'-01',
                'expense_type' => 'other',
                'currency' => 'EUR',
                'amount' => $amount,
                // This block is the money that was actually handed over.
                'cost_status' => 'not_written',
                'settled' => true,
            ],
        );

        return $this->flagIncomplete($row, ['expense_date', 'amount']);
    }

    private function writtenTravel(array $cells, int $rowNumber): ?ParsedRow
    {
        $amount = CellValue::number($this->cell($cells, 16));
        $name = CellValue::string($this->cell($cells, 15));

        if ($amount === null || $name === null) {
            return null;
        }

        $month = CellValue::date($this->cell($cells, 14));
        $typeLabel = CellValue::string($this->cell($cells, 17));

        $row = new ParsedRow(
            target: 'travel_expense',
            rowNumber: $rowNumber,
            raw: [
                'block' => 'YAZILAN YOL MASRAFI',
                'mjesec' => CellValue::string($this->cell($cells, 14)),
                'ime' => $name,
                'ukupno' => CellValue::string($this->cell($cells, 16)),
                'tip' => $typeLabel,
            ],
            mapped: [
                'person_name' => $name,
                'expense_date' => $month,
                'period_month' => $month === null ? null : substr($month, 0, 7).'-01',
                'expense_type' => LabelNormalizer::expenseType($typeLabel) ?? 'other',
                'currency' => 'EUR',
                'amount' => $amount,
                // This block is cost booked to the worker, not cash paid out.
                'cost_status' => 'written',
                'settled' => false,
            ],
        );

        if ($typeLabel !== null && LabelNormalizer::expenseType($typeLabel) === null) {
            $row->withIssue('unknown_label', "Unrecognised type '{$typeLabel}'.", ['field' => 'expense_type']);
        }

        return $this->flagIncomplete($row, ['expense_date', 'amount']);
    }
}
