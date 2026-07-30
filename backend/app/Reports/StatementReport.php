<?php

namespace App\Reports;

use App\Models\Client;
use App\Models\PayableInvoice;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;

/**
 * A party's account statement: invoices as debit, payments and deductions as
 * credit, with a running balance.
 *
 * The spec lists "client statement" and "Uniprom statement" separately; they are
 * the same report — this one, with Uniprom chosen as the client. The app has
 * generalized the Uniprom ledger from the start rather than hardcoding a
 * customer, and this keeps that consistent.
 */
class StatementReport extends Report
{
    public function __construct(private readonly bool $supplier) {}

    public static function supplier(): self
    {
        return new self(true);
    }

    public static function client(): self
    {
        return new self(false);
    }

    public function key(): string
    {
        return $this->supplier ? 'supplier_statement' : 'client_statement';
    }

    public function permission(): string
    {
        return $this->supplier ? 'payables.view' : 'receivables.manage';
    }

    public function filters(): array
    {
        return [$this->supplier ? 'supplier_id' : 'client_id', 'date_from', 'date_to'];
    }

    public function needsSignature(): bool
    {
        return true;
    }

    public function columns(): array
    {
        return [
            ['key' => 'date', 'label' => 'date', 'type' => 'date'],
            ['key' => 'type', 'label' => 'entryType', 'type' => 'text'],
            ['key' => 'reference', 'label' => 'reference', 'type' => 'text'],
            ['key' => 'debit', 'label' => 'debit', 'type' => 'money'],
            ['key' => 'credit', 'label' => 'credit', 'type' => 'money'],
            ['key' => 'balance', 'label' => 'balance', 'type' => 'money'],
        ];
    }

    public function rows(array $filters): array
    {
        $partyId = (int) ($filters[$this->supplier ? 'supplier_id' : 'client_id'] ?? 0);

        if ($partyId === 0) {
            return [];
        }

        [$from, $to] = $this->dateRange($filters);

        $invoices = $this->supplier
            ? PayableInvoice::with('payments')->where('supplier_id', $partyId)->get()
            : ReceivableInvoice::with(['payments', 'deductions'])->where('client_id', $partyId)->get();

        $entries = [];

        foreach ($invoices as $invoice) {
            $amount = $this->supplier ? $invoice->original_amount : $invoice->invoice_amount;

            $entries[] = [
                'date' => optional($invoice->invoice_date)->toDateString(),
                'type' => 'invoice',
                'reference' => $invoice->invoice_number,
                'debit' => $this->money($amount),
                'credit' => 0.0,
            ];

            foreach ($invoice->payments as $payment) {
                $entries[] = [
                    'date' => optional($payment->payment_date)->toDateString(),
                    'type' => 'payment',
                    'reference' => $payment->reference,
                    'debit' => 0.0,
                    'credit' => $this->money($payment->amount),
                ];
            }

            foreach ($invoice->deductions ?? [] as $deduction) {
                $entries[] = [
                    'date' => optional($deduction->deduction_date)->toDateString(),
                    'type' => 'deduction',
                    'reference' => $deduction->reason,
                    'debit' => 0.0,
                    'credit' => $this->money($deduction->amount),
                ];
            }
        }

        $entries = array_values(array_filter($entries, function (array $entry) use ($from, $to): bool {
            if ($entry['date'] === null) {
                return true;
            }

            return (! $from || $entry['date'] >= $from) && (! $to || $entry['date'] <= $to);
        }));

        usort($entries, fn (array $a, array $b): int => ($a['date'] ?? '') <=> ($b['date'] ?? ''));

        $balance = 0.0;
        foreach ($entries as $index => $entry) {
            $balance += $entry['debit'] - $entry['credit'];
            $entries[$index]['balance'] = round($balance, 2);
        }

        return $entries;
    }

    /** The closing balance is the last running balance, not a column sum. */
    public function totals(array $rows): array
    {
        $debit = 0.0;
        $credit = 0.0;

        foreach ($rows as $row) {
            $debit += (float) ($row['debit'] ?? 0);
            $credit += (float) ($row['credit'] ?? 0);
        }

        return [
            'debit' => round($debit, 2),
            'credit' => round($credit, 2),
            'balance' => $rows === [] ? 0.0 : (float) end($rows)['balance'],
        ];
    }

    /** @return array<int, string> id => name, for the filter dropdown */
    public function parties(): array
    {
        return $this->supplier
            ? Supplier::query()->orderBy('name')->pluck('name', 'id')->all()
            : Client::query()->orderBy('name')->pluck('name', 'id')->all();
    }
}
