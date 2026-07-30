<?php

namespace App\Services\Import\Parsers;

use App\Services\Import\ParsedRow;
use App\Services\Import\SheetParser;
use App\Support\Import\CellValue;
use App\Support\Import\LabelNormalizer;

/**
 * `UCAK BILETLERI` — flight tickets.
 *
 * Ime radnika | DATUM | Cijena (TRY) | Cijena (EUR) | Putni Toskovi | TURU
 *
 * Both prices are present, so the rate actually used is derived from the pair
 * rather than looked up — that is the rate the office really paid at.
 */
class FlightTicketParser extends SheetParser
{
    public static function sheetNames(): array
    {
        return ['UCAK BILETLERI', 'UÇAK BILETLERI'];
    }

    protected function parseRow(array $cells, int $rowNumber): array
    {
        $passenger = CellValue::string($this->cell($cells, 0));
        $try = CellValue::number($this->cell($cells, 2));
        $eur = CellValue::number($this->cell($cells, 3));

        // The sheet is padded with empty rows carrying a stray 0 in the EUR
        // column; they are filler, not tickets.
        if ($passenger === null && ($try ?? 0.0) === 0.0 && ($eur ?? 0.0) === 0.0) {
            return [];
        }

        $costLabel = CellValue::string($this->cell($cells, 4));
        $directionLabel = CellValue::string($this->cell($cells, 5));
        $direction = LabelNormalizer::direction($directionLabel);

        $rate = $try !== null && $eur !== null && $eur > 0 ? round($try / $eur, 10) : null;

        $row = new ParsedRow(
            target: 'flight_ticket',
            rowNumber: $rowNumber,
            raw: [
                'ime_radnika' => $passenger,
                'datum' => CellValue::string($this->cell($cells, 1)),
                'cijena_try' => CellValue::string($this->cell($cells, 2)),
                'cijena_eur' => CellValue::string($this->cell($cells, 3)),
                'putni_toskovi' => $costLabel,
                'turu' => $directionLabel,
            ],
            mapped: [
                'passenger_name' => $passenger,
                'ticket_date' => CellValue::date($this->cell($cells, 1)),
                'currency' => $try !== null ? 'TRY' : 'EUR',
                'amount' => $try ?? $eur,
                'exchange_rate' => $rate,
                'amount_eur' => $eur,
                'direction' => $direction ?? 'departure',
                'cost_status' => LabelNormalizer::costStatus($costLabel) ?? 'not_written',
            ],
        );

        if ($directionLabel !== null && $direction === null) {
            $row->withIssue(
                'unknown_label',
                "Unrecognised direction '{$directionLabel}', defaulted to departure.",
                ['field' => 'direction'],
            );
        }

        return [$this->flagIncomplete($row, ['passenger_name', 'ticket_date', 'amount_eur'])];
    }
}
