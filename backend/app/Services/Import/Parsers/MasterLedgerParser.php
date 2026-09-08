<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;

/**
 * `SELIM` — one master's own ledger, two blocks side by side:
 *
 *  A–F  SIRA | ODEME TARİHİ | FATURA TARIHI | FİRMA ADI | FATURA NO | TUTAR EURO
 *  H–I  TARIH | CEKILEN PARA          (cash drawn)
 *
 * The left block is supplier invoices this master handled and the right is cash
 * he drew. Both carry the master's name in `notes` so the origin stays visible —
 * the sheet is a person's working notes, and every row lands flagged for review
 * rather than being filed silently.
 */
class MasterLedgerParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['SELIM'];
    }

    public function __construct(private readonly string $masterName = 'SELIM') {}

    public static function forSheet(string $sheetName): self
    {
        return new self(CellValue::string($sheetName) ?? 'SELIM');
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        return array_values(array_filter([
            $this->invoice($cells, $rowNumber),
            $this->cashDrawn($cells, $rowNumber),
        ]));
    }

    private function invoice(array $cells, int $rowNumber): ?ParsedRow
    {
        $supplier = CellValue::string($this->cell($cells, 3));
        $amount = CellValue::number($this->cell($cells, 5));

        if ($supplier === null || $amount === null) {
            return null;
        }

        $row = new ParsedRow(
            target: 'payable_invoice',
            rowNumber: $rowNumber,
            raw: [
                'block' => 'FATURALAR',
                'sira' => CellValue::string($this->cell($cells, 0)),
                'odeme_tarihi' => CellValue::string($this->cell($cells, 1)),
                'fatura_tarihi' => CellValue::string($this->cell($cells, 2)),
                'firma_adi' => $supplier,
                'fatura_no' => CellValue::string($this->cell($cells, 4)),
                'tutar' => CellValue::string($this->cell($cells, 5)),
            ],
            mapped: [
                'supplier_name' => $supplier,
                'invoice_number' => CellValue::string($this->cell($cells, 4)),
                'invoice_date' => CellValue::date($this->cell($cells, 2))
                    ?? CellValue::date($this->cell($cells, 1)),
                'currency' => 'EUR',
                'original_amount' => $amount,
                // The payment date column is what the master settled it on.
                'opening_paid_amount' => CellValue::date($this->cell($cells, 1)) !== null ? $amount : 0.0,
                'notes' => "{$this->masterName} sheet",
            ],
        );

        $row->withIssue(
            'review',
            "From {$this->masterName}'s personal sheet — confirm it is not already in the debt list.",
        );

        return $this->flagIncomplete($row, ['invoice_date', 'original_amount']);
    }

    private function cashDrawn(array $cells, int $rowNumber): ?ParsedRow
    {
        $amount = CellValue::number($this->cell($cells, 8));
        $date = CellValue::date($this->cell($cells, 7));

        if ($amount === null || $date === null) {
            return null;
        }

        $row = new ParsedRow(
            target: 'bank_transaction',
            rowNumber: $rowNumber,
            raw: [
                'block' => 'CEKILEN PARA',
                'tarih' => CellValue::string($this->cell($cells, 7)),
                'cekilen_para' => CellValue::string($this->cell($cells, 8)),
            ],
            mapped: [
                'date' => $date,
                'description_1' => "{$this->masterName} — cash drawn",
                // Money leaving the cash box.
                'lines' => [['account' => 'Cash', 'amount' => -abs($amount)]],
                'category' => 'expense',
                'currency' => 'EUR',
                'notes' => "{$this->masterName} sheet",
            ],
        );

        $row->withIssue('review', "Cash drawn on {$this->masterName}'s sheet — confirm against the bank movements.");

        return $row;
    }
}
