<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;
use App\Support\Import\LabelNormalizer;

/**
 * `ISCILER ICIN BANKA HESAPLARI` — whether each worker's bank account is sorted.
 *
 * EVRAK TEMSIL TARIHI | ISIM | DURUM | NOTLAR | (ODENDI marker)
 *
 * This sheet updates existing workers rather than creating financial records, so
 * a matching worker is required — an unmatched name is flagged, never guessed.
 */
class WorkerBankAccountParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['ISCILER ICIN BANKA HESAPLARI'];
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        $name = CellValue::string($this->cell($cells, 1));

        if ($name === null) {
            return [];
        }

        $statusLabel = CellValue::string($this->cell($cells, 2));

        $row = new ParsedRow(
            target: 'employee_bank_account',
            rowNumber: $rowNumber,
            raw: [
                'evrak_tarihi' => CellValue::string($this->cell($cells, 0)),
                'isim' => $name,
                'durum' => $statusLabel,
                'notlar' => CellValue::string($this->cell($cells, 3)),
                'odeme' => CellValue::string($this->cell($cells, 4)),
            ],
            mapped: [
                'employee_name' => $name,
                'bank_account_status' => LabelNormalizer::bankAccountStatus($statusLabel) ?? 'unknown',
                'document_date' => CellValue::date($this->cell($cells, 0)),
                'notes' => CellValue::string($this->cell($cells, 3)),
            ],
        );

        if ($statusLabel !== null && LabelNormalizer::bankAccountStatus($statusLabel) === null) {
            $row->withIssue(
                'unknown_label',
                "Unrecognised account status '{$statusLabel}'.",
                ['field' => 'bank_account_status'],
            );
        }

        return [$row];
    }
}
