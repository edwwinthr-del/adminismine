<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;
use App\Support\Import\LabelNormalizer;

/**
 * `BANKA HAREKETLERİ` — cash and bank movements.
 *
 * TARİH | AÇIKLAMA 1 | AÇIKLAMA 2 | KASA | NLB BANK | LOVCEN BANK
 *       | FATURA NO | FATURA TARİHİ | GİDER KALEMİ | NOTLAR
 *
 * The three account columns are already signed in the sheet (+ in, − out), which
 * is exactly how a movement's lines store them. The workbook's three columns are
 * this company's three accounts, so they are emitted by name and resolved to
 * account rows on commit — a sheet written before the accounts were rows still
 * lands on the right ones.
 */
class BankMovementParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['BANKA HAREKETLERİ', 'BANKA HAREKETLERI'];
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        $date = CellValue::date($this->cell($cells, 0));
        $cash = CellValue::number($this->cell($cells, 3));
        $nlb = CellValue::number($this->cell($cells, 4));
        $lovcen = CellValue::number($this->cell($cells, 5));

        // The opening-balance line ("PRETHODNO STANJE=") carries a zero in an
        // account column but no date — it states a position, not a movement.
        $moved = ($cash ?? 0.0) !== 0.0 || ($nlb ?? 0.0) !== 0.0 || ($lovcen ?? 0.0) !== 0.0;

        if ($date === null && ! $moved) {
            return [];
        }

        $categoryLabel = CellValue::string($this->cell($cells, 8));

        $row = new ParsedRow(
            target: 'bank_transaction',
            rowNumber: $rowNumber,
            raw: [
                'tarih' => CellValue::string($this->cell($cells, 0)),
                'aciklama_1' => CellValue::string($this->cell($cells, 1)),
                'aciklama_2' => CellValue::string($this->cell($cells, 2)),
                'kasa' => CellValue::string($this->cell($cells, 3)),
                'nlb' => CellValue::string($this->cell($cells, 4)),
                'lovcen' => CellValue::string($this->cell($cells, 5)),
                'fatura_no' => CellValue::string($this->cell($cells, 6)),
                'gider_kalemi' => $categoryLabel,
                'notlar' => CellValue::string($this->cell($cells, 9)),
            ],
            mapped: [
                'date' => $date,
                'description_1' => CellValue::string($this->cell($cells, 1)),
                'description_2' => CellValue::string($this->cell($cells, 2)),
                'lines' => array_values(array_filter([
                    ['account' => 'Cash', 'amount' => $cash ?? 0],
                    ['account' => 'NLB', 'amount' => $nlb ?? 0],
                    ['account' => 'Lovćen', 'amount' => $lovcen ?? 0],
                ], fn (array $line): bool => (float) $line['amount'] !== 0.0)),
                'category' => LabelNormalizer::category($categoryLabel),
                'currency' => 'EUR',
                'invoice_number' => CellValue::string($this->cell($cells, 6)),
                'notes' => CellValue::string($this->cell($cells, 9)),
            ],
        );

        if ($categoryLabel !== null && $row->mapped['category'] === null) {
            $row->withIssue('unknown_label', "Unrecognised category '{$categoryLabel}'.", ['field' => 'category']);
        }

        return [$this->flagIncomplete($row, ['date'])];
    }
}
