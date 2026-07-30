<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;
use App\Support\Import\LabelNormalizer;

/**
 * `BORÇ LİSTESİ` — the supplier debt list.
 *
 * SIRA | FATURA TARİHİ | FİRMA ADI | FATURA NO | AÇIKLAMA | BORÇ TUTAR EURO
 *      | KALAN BORÇ TUTARI EURO | (ODENDI/ODENMEDI) | NOTLAR
 *
 * The remaining-balance column is not imported as a field: balances are always
 * computed from payments (rule 1). It is used only to derive how much had
 * already been paid, which is written as an opening payment.
 */
class DebtListParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['BORÇ LİSTESİ', 'BORC LISTESI'];
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        $supplier = CellValue::string($this->cell($cells, 2));
        $amount = CellValue::number($this->cell($cells, 5));

        // Total lines carry an amount but no company — they are the sheet's own
        // arithmetic, not a record.
        if ($supplier === null && $amount === null) {
            return [];
        }

        $remaining = CellValue::number($this->cell($cells, 6));
        $statusLabel = CellValue::string($this->cell($cells, 7));
        $status = LabelNormalizer::paymentStatus($statusLabel);

        // What the sheet says was already settled, so the invoice lands with the
        // right balance without ever storing one.
        $paid = $amount !== null && $remaining !== null
            ? round(max($amount - $remaining, 0), 2)
            : ($status === 'paid' ? $amount : 0.0);

        $row = new ParsedRow(
            target: 'payable_invoice',
            rowNumber: $rowNumber,
            raw: [
                'sira' => CellValue::string($this->cell($cells, 0)),
                'fatura_tarihi' => CellValue::string($this->cell($cells, 1)),
                'firma_adi' => $supplier,
                'fatura_no' => CellValue::string($this->cell($cells, 3)),
                'aciklama' => CellValue::string($this->cell($cells, 4)),
                'borc_tutar' => CellValue::string($this->cell($cells, 5)),
                'kalan_borc' => CellValue::string($this->cell($cells, 6)),
                'durum' => $statusLabel,
                'notlar' => CellValue::string($this->cell($cells, 8)),
            ],
            mapped: [
                'supplier_name' => $supplier,
                'invoice_number' => CellValue::string($this->cell($cells, 3)),
                'invoice_date' => CellValue::date($this->cell($cells, 1)),
                'description' => CellValue::string($this->cell($cells, 4)),
                'currency' => 'EUR',
                'original_amount' => $amount,
                'opening_paid_amount' => $paid,
                'notes' => CellValue::string($this->cell($cells, 8)),
            ],
        );

        if ($statusLabel !== null && $status === null) {
            $row->withIssue('unknown_label', "Unrecognised status '{$statusLabel}'.", ['field' => 'status']);
        }

        return [$this->flagIncomplete($row, ['supplier_name', 'invoice_date', 'original_amount'])];
    }
}
