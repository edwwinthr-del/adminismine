<?php

namespace App\Reports;

use Closure;

/**
 * A report that is a filtered list: columns plus a closure that produces rows.
 * Most of the spec's reports are exactly this, so they are configured in
 * ReportRegistry rather than each getting a near-identical class. Reports with
 * real logic (aging buckets, statements, cashflow) have their own classes.
 */
class TableReport extends Report
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  Closure(array<string, mixed>): list<array<string, mixed>>  $resolver
     * @param  list<string>  $filters
     */
    public function __construct(
        private readonly string $key,
        private readonly string $permission,
        private readonly array $columns,
        private readonly Closure $resolver,
        private readonly array $filters = ['date_from', 'date_to'],
        private readonly bool $signature = false,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function permission(): string
    {
        return $this->permission;
    }

    public function columns(): array
    {
        return $this->columns;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function rows(array $filters): array
    {
        return ($this->resolver)($filters);
    }

    public function needsSignature(): bool
    {
        return $this->signature;
    }
}
