<?php

namespace App\Reports;

use App\Models\BankTransaction;
use App\Support\MonthPeriod;
use Illuminate\Support\Carbon;

/**
 * Money in and out per month over a year. Transfers between accounts are left
 * out of both sides, matching the dashboard — the two must never disagree.
 */
class MonthlyCashflowReport extends Report
{
    public function key(): string
    {
        return 'monthly_cashflow';
    }

    public function permission(): string
    {
        return 'bank_transactions.manage';
    }

    public function filters(): array
    {
        return ['year'];
    }

    public function needsSignature(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'month', 'label' => 'month', 'type' => 'month'],
            ['key' => 'income', 'label' => 'income', 'type' => 'money'],
            ['key' => 'expenses', 'label' => 'expenses', 'type' => 'money'],
            ['key' => 'net', 'label' => 'net', 'type' => 'money'],
        ];
    }

    public function rows(array $filters): array
    {
        $year = (int) ($filters['year'] ?? now()->year);
        [$start, $end] = MonthPeriod::yearRange($year);

        $movements = BankTransaction::query()
            ->whereBetween('date', [$start, $end])
            ->where(fn ($query) => $query->whereNull('category')->orWhere('category', '!=', 'transfer'))
            ->get(['date', 'amount']);

        // Bucketed in PHP: date truncation differs between SQLite and Postgres.
        $byMonth = $movements->groupBy(
            fn (BankTransaction $movement): string => $movement->date->copy()->startOfMonth()->toDateString(),
        );

        $rows = [];
        for ($month = 1; $month <= 12; $month++) {
            $bucket = Carbon::create($year, $month, 1)->toDateString();
            $income = 0.0;
            $expenses = 0.0;

            foreach ($byMonth->get($bucket, collect()) as $movement) {
                $net = $movement->net_amount;
                $net >= 0 ? $income += $net : $expenses += abs($net);
            }

            $rows[] = [
                'month' => $bucket,
                'income' => round($income, 2),
                'expenses' => round($expenses, 2),
                'net' => round($income - $expenses, 2),
            ];
        }

        return $rows;
    }
}
