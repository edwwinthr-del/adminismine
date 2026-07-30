<?php

namespace App\Reports;

use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use Illuminate\Support\Carbon;

/**
 * Payables and receivables aging: what is outstanding, bucketed by how long it
 * has been overdue. One class serves both sides because the buckets and the
 * arithmetic are identical — only the model and the party differ.
 */
class AgingReport extends Report
{
    /** Upper bound of each bucket in days; the last is everything older. */
    public const BUCKETS = [0, 30, 60, 90];

    public function __construct(private readonly bool $payable) {}

    public static function payables(): self
    {
        return new self(true);
    }

    public static function receivables(): self
    {
        return new self(false);
    }

    public function key(): string
    {
        return $this->payable ? 'payables_aging' : 'receivables_aging';
    }

    public function permission(): string
    {
        return $this->payable ? 'payables.view' : 'receivables.manage';
    }

    public function filters(): array
    {
        return $this->payable ? ['supplier_id'] : ['client_id'];
    }

    public function columns(): array
    {
        return [
            ['key' => 'party', 'label' => $this->payable ? 'supplier' : 'client', 'type' => 'text'],
            ['key' => 'invoice_number', 'label' => 'invoiceNumber', 'type' => 'text'],
            ['key' => 'invoice_date', 'label' => 'invoiceDate', 'type' => 'date'],
            ['key' => 'due_date', 'label' => 'dueDate', 'type' => 'date'],
            ['key' => 'days_overdue', 'label' => 'daysOverdue', 'type' => 'number'],
            ['key' => 'bucket', 'label' => 'bucket', 'type' => 'text'],
            ['key' => 'outstanding', 'label' => 'outstanding', 'type' => 'money'],
        ];
    }

    public function rows(array $filters): array
    {
        $today = Carbon::today();

        $query = $this->payable
            ? PayableInvoice::query()->outstanding()->with('supplier')
            : ReceivableInvoice::query()->outstanding()->with('client');

        if ($this->payable && ! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! $this->payable && ! empty($filters['client_id'])) {
            $query->where('client_id', (int) $filters['client_id']);
        }

        return $query->orderBy('due_date')->get()
            ->map(function ($invoice) use ($today): array {
                $due = $invoice->due_date;
                $days = $due === null ? 0 : max(0, $due->diffInDays($today, false));

                return [
                    'party' => $this->payable ? $invoice->supplier?->name : $invoice->client?->name,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_date' => optional($invoice->invoice_date)->toDateString(),
                    'due_date' => optional($due)->toDateString(),
                    'days_overdue' => (int) $days,
                    'bucket' => $this->bucketFor((int) $days),
                    'outstanding' => $this->money($invoice->remaining_amount),
                ];
            })
            ->all();
    }

    /** `days_overdue` is a count, not money — summing it would be meaningless. */
    public function totals(array $rows): array
    {
        $total = 0.0;
        foreach ($rows as $row) {
            $total += (float) ($row['outstanding'] ?? 0);
        }

        return ['outstanding' => round($total, 2)];
    }

    private function bucketFor(int $days): string
    {
        if ($days <= 0) {
            return 'current';
        }
        if ($days <= 30) {
            return '1_30';
        }
        if ($days <= 60) {
            return '31_60';
        }
        if ($days <= 90) {
            return '61_90';
        }

        return 'over_90';
    }
}
