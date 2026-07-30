<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;

/**
 * ` ALACAKLAR` (receivables) and `KESİLEN FATURALAR` (issued invoices).
 *
 * The two sheets list the same invoices in a different column order, so both are
 * parsed here and the overlap is caught by duplicate detection rather than by
 * choosing one sheet over the other.
 */
class ReceivableParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return [' ALACAKLAR', 'ALACAKLAR', 'KESİLEN FATURALAR', 'KESILEN FATURALAR'];
    }

    /** `KESİLEN FATURALAR` puts the client first; ` ALACAKLAR` puts a note first. */
    public function __construct(private readonly bool $issuedLayout = false) {}

    public static function forSheet(string $sheetName): self
    {
        $key = CellValue::key($sheetName);

        return new self(in_array($key, ['KESİLEN FATURALAR', 'KESILEN FATURALAR'], true));
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        // KESİLEN FATURALAR: SIRA | FİRMA | TARİH | NO | TUTAR | AÇIKLAMA
        //  ALACAKLAR:        SIRA | ACIKLAMA(client) | NO | TARİH | TUTAR
        [$clientIndex, $numberIndex, $dateIndex, $amountIndex, $descriptionIndex] = $this->issuedLayout
            ? [1, 3, 2, 4, 5]
            : [1, 2, 3, 4, 6];

        $client = CellValue::string($this->cell($cells, $clientIndex));
        $amount = CellValue::number($this->cell($cells, $amountIndex));

        if ($client === null && $amount === null) {
            return [];
        }

        $row = new ParsedRow(
            target: 'receivable_invoice',
            rowNumber: $rowNumber,
            raw: array_filter([
                'sira' => CellValue::string($this->cell($cells, 0)),
                'firma' => $client,
                'fatura_no' => CellValue::string($this->cell($cells, $numberIndex)),
                'fatura_tarihi' => CellValue::string($this->cell($cells, $dateIndex)),
                'tutar' => CellValue::string($this->cell($cells, $amountIndex)),
                'aciklama' => CellValue::string($this->cell($cells, $descriptionIndex)),
            ], fn ($value) => $value !== null),
            mapped: [
                'client_name' => $client,
                'invoice_number' => CellValue::string($this->cell($cells, $numberIndex)),
                'invoice_date' => CellValue::date($this->cell($cells, $dateIndex)),
                'description' => CellValue::string($this->cell($cells, $descriptionIndex)),
                'currency' => 'EUR',
                'invoice_amount' => $amount,
            ],
        );

        return [$this->flagIncomplete($row, ['client_name', 'invoice_date', 'invoice_amount'])];
    }
}
