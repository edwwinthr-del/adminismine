<?php

namespace App\Services;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\RentPayment;
use App\Models\User;
use App\Models\UtilityBill;
use App\Support\MonthPeriod;
use Illuminate\Support\Carbon;

/**
 * The dashboard's numbers, assembled from the same records and scopes the
 * modules expose — nothing here is a stored total.
 *
 * Every section is gated on a named permission (rule 6), so the payload only
 * ever contains what the signed-in user is allowed to see; the frontend renders
 * whichever sections arrive rather than deciding for itself.
 */
class DashboardService
{
    /** Months of history behind the income/expense trend, including the current one. */
    public const TREND_MONTHS = 6;

    public const RECENT_LIMIT = 10;

    /** Clients shown on the "remaining per client" card. */
    public const TOP_CLIENTS = 5;

    /** @return array<string, mixed> */
    public function forUser(User $user, ?string $month = null): array
    {
        $month = MonthPeriod::normalize($month ?? now()->toDateString());

        $canBank = $user->can('bank_transactions.manage');
        $canPayables = $user->can('payables.view');
        $canReceivables = $user->can('receivables.manage');
        $canHousing = $user->can('housing.manage');

        $data = ['month' => $month];

        if ($canBank) {
            $data['balances'] = $this->balances();
            $data['cashflow'] = $this->cashflow($month);
            $data['recent_transactions'] = $this->recentTransactions();
        }
        if ($canPayables) {
            $data['payables'] = $this->payables();
        }
        if ($canReceivables) {
            $data['receivables'] = $this->receivables();
        }
        if ($canHousing) {
            $data['housing'] = $this->housing($month);
        }

        $data['alerts'] = $this->alerts($canBank, $canPayables, $canReceivables);

        return $data;
    }

    /** @return array{cash: float, nlb: float, lovcen: float, total: float} */
    private function balances(): array
    {
        // The same computation the bank page shows — one definition, so the two
        // screens cannot quote different balances.
        return BankTransaction::accountBalances();
    }

    /**
     * Money in and out per month. Transfers are excluded from both sides: moving
     * cash to the bank is not income, and counting it would inflate every month.
     *
     * @return array{month: array{income: float, expenses: float, net: float}, trend: list<array{month: string, income: float, expenses: float, net: float}>}
     */
    private function cashflow(string $month): array
    {
        $start = Carbon::parse($month)->subMonthsNoOverflow(self::TREND_MONTHS - 1)->startOfMonth();
        $end = Carbon::parse(MonthPeriod::end($month));

        $query = BankTransaction::query();
        $driver = $query->getConnection()->getDriverName();
        $bucket = MonthPeriod::sqlMonth($driver, 'date');
        $net = '(cash_amount + nlb_amount + lovcen_amount)';

        // Summed and bucketed by the database. The sign of a movement's net
        // decides which side it lands on, which is the same rule the module
        // uses — expressed once, in SQL, instead of by reading every row.
        $byMonth = $query
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->where(fn ($inner) => $inner->whereNull('category')->orWhere('category', '!=', 'transfer'))
            ->selectRaw("{$bucket} as bucket")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$net} >= 0 THEN {$net} ELSE 0 END), 0) as income")
            ->selectRaw("COALESCE(SUM(CASE WHEN {$net} < 0 THEN -{$net} ELSE 0 END), 0) as expenses")
            ->groupByRaw($bucket)
            ->get()
            ->keyBy('bucket');

        $trend = [];
        for ($offset = self::TREND_MONTHS - 1; $offset >= 0; $offset--) {
            $first = Carbon::parse($month)->subMonthsNoOverflow($offset)->startOfMonth()->toDateString();
            $trend[] = $this->bucketTotals($first, $byMonth->get(substr($first, 0, 7)));
        }

        $current = collect($trend)->firstWhere('month', $month) ?? $this->bucketTotals($month, null);

        return [
            'month' => [
                'income' => $current['income'],
                'expenses' => $current['expenses'],
                'net' => $current['net'],
            ],
            'trend' => $trend,
        ];
    }

    /**
     * @param  BankTransaction|null  $totals  the aggregate row for this month, if any
     * @return array{month: string, income: float, expenses: float, net: float}
     */
    private function bucketTotals(string $month, ?BankTransaction $totals): array
    {
        $income = round((float) ($totals->income ?? 0), 2);
        $expenses = round((float) ($totals->expenses ?? 0), 2);

        return [
            'month' => $month,
            'income' => $income,
            'expenses' => $expenses,
            'net' => round($income - $expenses, 2),
        ];
    }

    /** @return array{outstanding: float, overdue: float, count: int, overdue_count: int} */
    private function payables(): array
    {
        // Aggregated in the database: these tiles must stay cheap however many
        // invoices the company accumulates.
        $outstanding = PayableInvoice::query()->outstanding()
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) as total, COUNT(*) as rows')->first();
        $overdue = PayableInvoice::query()->overdue()
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) as total, COUNT(*) as rows')->first();

        return [
            'outstanding' => round((float) $outstanding->total, 2),
            'overdue' => round((float) $overdue->total, 2),
            'count' => (int) $outstanding->rows,
            'overdue_count' => (int) $overdue->rows,
        ];
    }

    /**
     * Outstanding receivables, plus the balance per client — which is how the
     * Uniprom figure the workbook tracks surfaces, without hardcoding a customer.
     *
     * @return array<string, mixed>
     */
    private function receivables(): array
    {
        $outstanding = ReceivableInvoice::query()->outstanding()
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) as total, COUNT(*) as rows')->first();
        $overdue = ReceivableInvoice::query()->overdue()
            ->selectRaw('COALESCE(SUM(remaining_amount), 0) as total, COUNT(*) as rows')->first();

        // Grouped and ordered by the database, then capped: only the five rows
        // the card shows ever leave it.
        $byClient = ReceivableInvoice::query()->outstanding()
            ->selectRaw('client_id, COALESCE(SUM(remaining_amount), 0) as outstanding, COUNT(*) as invoices')
            ->groupBy('client_id')
            ->orderByDesc('outstanding')
            ->limit(self::TOP_CLIENTS)
            ->get();

        $clients = Client::query()->whereIn('id', $byClient->pluck('client_id'))->pluck('name', 'id');

        return [
            'outstanding' => round((float) $outstanding->total, 2),
            'overdue' => round((float) $overdue->total, 2),
            'count' => (int) $outstanding->rows,
            'overdue_count' => (int) $overdue->rows,
            'by_client' => $byClient->map(fn ($row): array => [
                'client_id' => (int) $row->client_id,
                'name' => $clients->get((int) $row->client_id),
                'outstanding' => round((float) $row->outstanding, 2),
                'count' => (int) $row->invoices,
            ])->all(),
        ];
    }

    /** @return array{monthly_cost: float, unpaid_rent: float, unpaid_bills: float, overdue_bills_count: int} */
    private function housing(string $month): array
    {
        $rents = RentPayment::query()->forMonth($month)->selectRaw(
            'COALESCE(SUM(rent_amount_due), 0) as due, COALESCE(SUM(remaining_amount), 0) as remaining',
        )->first();

        $bills = UtilityBill::query()->forMonth($month)->selectRaw(
            'COALESCE(SUM(amount), 0) as due, COALESCE(SUM(remaining_amount), 0) as remaining',
        )->first();

        return [
            'monthly_cost' => round((float) $rents->due + (float) $bills->due, 2),
            'unpaid_rent' => round((float) $rents->remaining, 2),
            'unpaid_bills' => round((float) $bills->remaining, 2),
            // The model's `overdue` scope rather than its accessor, so the count
            // is a query instead of a read of the month's bills.
            'overdue_bills_count' => UtilityBill::query()->forMonth($month)->overdue()->count(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function recentTransactions(): array
    {
        $recent = BankTransaction::query()
            ->with(['supplier:id,name', 'client:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get();

        // Asked only about the ten rows shown, not the whole table.
        $duplicateIds = BankTransaction::duplicateIds($recent->pluck('id')->all())->flip();

        return $recent
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'description' => $transaction->description_1,
                'category' => $transaction->category,
                'counterparty' => $transaction->supplier?->name ?? $transaction->client?->name,
                'amount' => $transaction->net_amount,
                'currency' => $transaction->currency,
                'possible_duplicate' => isset($duplicateIds[$transaction->id]),
            ])
            ->all();
    }

    /**
     * The spec's four dashboard alerts. Each is only counted when the user may
     * see the module behind it, so a number never hints at data they cannot open.
     *
     * @return array<string, int|float>
     */
    private function alerts(bool $canBank, bool $canPayables, bool $canReceivables): array
    {
        $alerts = [];

        if ($canPayables) {
            $alerts['overdue_payables'] = PayableInvoice::query()->overdue()->count();
        }
        if ($canReceivables) {
            $alerts['overdue_receivables'] = ReceivableInvoice::query()->overdue()->count();
        }
        if ($canBank) {
            $alerts['duplicate_entries'] = BankTransaction::duplicateCount();
            $alerts['unmatched_payments'] = BankTransaction::query()->unmatched()->count();
        }

        // An invoice with no number cannot be reconciled against anything.
        $missing = 0;
        if ($canPayables) {
            $missing += PayableInvoice::query()
                ->where(fn ($query) => $query->whereNull('invoice_number')->orWhere('invoice_number', ''))
                ->count();
        }
        if ($canReceivables) {
            $missing += ReceivableInvoice::query()
                ->where(fn ($query) => $query->whereNull('invoice_number')->orWhere('invoice_number', ''))
                ->count();
        }
        if ($canPayables || $canReceivables) {
            $alerts['missing_invoice_numbers'] = $missing;
        }

        return $alerts;
    }
}
