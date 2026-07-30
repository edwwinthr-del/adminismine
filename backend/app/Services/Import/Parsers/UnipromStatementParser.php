<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;

/**
 * `UNIPROM ALACAKLAR` — a client statement, so each row is either an invoice
 * raised or a payment received.
 *
 * SIRA | ACIKLAMA | İLERLEME | FATURA NO | FATURA TARİHİ
 *      | KESİLEN FATURA TUTARI | ALINAN ÖDEME | NOT | … | PDV
 *
 * Rows with a payment become a `client_payment`, which the committer applies
 * against the matching invoice — so the balance stays derived from records.
 */
class UnipromStatementParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['UNIPROM ALACAKLAR'];
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        $invoiceNumber = CellValue::string($this->cell($cells, 3));
        $date = CellValue::date($this->cell($cells, 4));
        $invoiced = CellValue::number($this->cell($cells, 5));
        $received = CellValue::number($this->cell($cells, 6));
        $description = CellValue::string($this->cell($cells, 1));

        if ($invoiced === null && $received === null) {
            return [];
        }

        $raw = [
            'sira' => CellValue::string($this->cell($cells, 0)),
            'aciklama' => $description,
            'fatura_no' => $invoiceNumber,
            'fatura_tarihi' => CellValue::string($this->cell($cells, 4)),
            'kesilen_tutar' => CellValue::string($this->cell($cells, 5)),
            'alinan_odeme' => CellValue::string($this->cell($cells, 6)),
            'not' => CellValue::string($this->cell($cells, 7)),
            'pdv' => CellValue::string($this->cell($cells, 12)),
        ];

        if ($invoiced !== null) {
            $row = new ParsedRow(
                target: 'receivable_invoice',
                rowNumber: $rowNumber,
                raw: $raw,
                mapped: [
                    'client_name' => 'UNIPROM',
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => $date,
                    'description' => $description,
                    'currency' => 'EUR',
                    'invoice_amount' => $invoiced,
                    'vat_amount' => CellValue::number($this->cell($cells, 12)),
                    'notes' => CellValue::string($this->cell($cells, 7)),
                ],
            );

            return [$this->flagIncomplete($row, ['invoice_date', 'invoice_amount'])];
        }

        $row = new ParsedRow(
            target: 'client_payment',
            rowNumber: $rowNumber,
            raw: $raw,
            mapped: [
                'client_name' => 'UNIPROM',
                'invoice_number' => $invoiceNumber,
                'payment_date' => $date,
                'amount' => $received,
                'currency' => 'EUR',
                'reference' => $description,
                'notes' => CellValue::string($this->cell($cells, 7)),
            ],
        );

        return [$this->flagIncomplete($row, ['payment_date', 'amount'])];
    }
}
