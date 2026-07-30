<?php

namespace App\Services\Import;

use App\Support\Import\CellValue;

/**
 * Base for the workbook's per-sheet readers. Each subclass knows one sheet's
 * shape; everything shared — skipping the header, flagging incomplete rows —
 * lives here so the parsers stay small enough to check against the sheet.
 */
abstract class SheetParser
{
    /** Sheet names this parser handles, matched case- and space-insensitively. */
    abstract public static function sheetNames(): array;

    /** Rows of header/label text before the data starts. */
    protected function headerRows(): int
    {
        return 1;
    }

    /**
     * @param  list<array<int, mixed>>  $rows  zero-indexed cell arrays, in sheet order
     * @return list<ParsedRow>
     */
    public function parse(array $rows): array
    {
        $parsed = [];

        foreach ($rows as $index => $cells) {
            $rowNumber = $index + 1;

            if ($rowNumber <= $this->headerRows() || CellValue::isBlank($cells)) {
                continue;
            }

            foreach ($this->parseRow($cells, $rowNumber) as $row) {
                $parsed[] = $row;
            }
        }

        return $parsed;
    }

    /**
     * A sheet row may yield zero rows (a total line), one, or several — the
     * social-assistance sheet holds three independent blocks side by side.
     *
     * @param  array<int, mixed>  $cells
     * @return list<ParsedRow>
     */
    abstract protected function parseRow(array $cells, int $rowNumber): array;

    /**
     * Flag fields the app needs that the sheet did not supply. The row is still
     * importable; the preview just says what is missing.
     *
     * @param  list<string>  $required
     */
    protected function flagIncomplete(ParsedRow $row, array $required): ParsedRow
    {
        foreach ($required as $field) {
            if (($row->mapped[$field] ?? null) === null || $row->mapped[$field] === '') {
                $row->withIssue('incomplete', "Missing {$field}.", ['field' => $field]);
            }
        }

        return $row;
    }

    /** @param array<int, mixed> $cells */
    protected function cell(array $cells, int $index): mixed
    {
        return $cells[$index] ?? null;
    }
}
