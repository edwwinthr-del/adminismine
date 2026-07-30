<?php

namespace App\Reports;

/**
 * One report. Adding a new one means writing a class and listing it in
 * ReportRegistry — nothing else in the export or API layer changes, which is the
 * spec's "new report types should be easy to add later".
 *
 * Column labels are returned as i18n keys, never as text: the frontend and the
 * exporters render them in the user's own language.
 */
abstract class Report
{
    /** Stable identifier used in the URL and as the i18n key suffix. */
    abstract public function key(): string;

    /** Named permission a user needs to run this report. */
    abstract public function permission(): string;

    /**
     * @return list<array{key: string, label: string, type: string}>
     *                                                               type is one of: text | number | money | date | month
     */
    abstract public function columns(): array;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    abstract public function rows(array $filters): array;

    /**
     * Filters this report understands, so the UI can show the right controls.
     *
     * @return list<string> any of: month | date_from | date_to | supplier_id | client_id |
     *                      employee_id | worksite_id | year
     */
    public function filters(): array
    {
        return ['date_from', 'date_to'];
    }

    /**
     * Totals shown under the table. Keys must be column keys.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, float>
     */
    public function totals(array $rows): array
    {
        $totals = [];

        foreach ($this->columns() as $column) {
            if (! in_array($column['type'], ['money', 'number'], true)) {
                continue;
            }

            $sum = 0.0;
            foreach ($rows as $row) {
                $sum += (float) ($row[$column['key']] ?? 0);
            }

            $totals[$column['key']] = round($sum, 2);
        }

        return $totals;
    }

    /** Whether this report is worth a signature block on the PDF. */
    public function needsSignature(): bool
    {
        return false;
    }

    /** @param array<string, mixed> $filters */
    protected function dateRange(array $filters): array
    {
        return [$filters['date_from'] ?? null, $filters['date_to'] ?? null];
    }

    protected function money(mixed $value): float
    {
        return round((float) $value, 2);
    }
}
