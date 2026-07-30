<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;
use App\Support\Import\LabelNormalizer;

/**
 * `NORTH-EX POZAJMICA` — loans and advances. The sheet has no header row: every
 * line is data, laid out as counterparty | reference | amount | … | date | amount
 * with an occasional `VRACENO` marker in the last column.
 */
class LoanParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['NORTH-EX POZAJMICA'];
    }

    protected function headerRows(): int
    {
        return 0;
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        $counterparty = CellValue::string($this->cell($cells, 0));
        $reference = CellValue::string($this->cell($cells, 1));

        // "UKUPNO ZA VRATIT" (total to return) is the sheet's own sum.
        if ($counterparty === null || CellValue::key($reference) === 'UKUPNO ZA VRATIT') {
            return [];
        }

        $amount = CellValue::number($this->cell($cells, 2));
        $marker = CellValue::string($this->cell($cells, 6));
        $status = LabelNormalizer::loanStatus($marker);

        $row = new ParsedRow(
            target: 'loan',
            rowNumber: $rowNumber,
            raw: [
                'counterparty' => $counterparty,
                'reference' => $reference,
                'amount' => CellValue::string($this->cell($cells, 2)),
                'date' => CellValue::string($this->cell($cells, 5)),
                'marker' => $marker,
            ],
            mapped: [
                'counterparty' => $counterparty,
                'reference_number' => $reference,
                // Amounts are written negative on this sheet when still owed.
                'original_amount' => $amount === null ? null : abs($amount),
                'loan_date' => CellValue::date($this->cell($cells, 5)),
                'currency' => 'EUR',
                'direction' => 'received',
                'repaid_in_full' => $status === 'repaid',
            ],
        );

        if ($marker !== null && $status === null && CellValue::number($marker) === null) {
            $row->withIssue('unknown_label', "Unrecognised marker '{$marker}'.", ['field' => 'status']);
        }

        return [$this->flagIncomplete($row, ['original_amount', 'loan_date'])];
    }
}
