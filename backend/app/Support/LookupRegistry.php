<?php

namespace App\Support;

use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Employee;
use App\Models\House;
use App\Models\Machine;
use App\Models\Master;
use App\Models\Mine;
use App\Models\PayableInvoice;
use App\Models\Project;
use App\Models\ReceivableInvoice;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Worksite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The catalogue behind `GET /api/lookups/{resource}` — one small, searchable,
 * always-capped list per thing a form can point at.
 *
 * Why this exists: a `<select>` that loads every supplier, invoice or bank
 * movement up front is fine with fifty rows and unusable with fifty thousand.
 * These endpoints return only what the user is typing towards, so the cost of
 * opening a form no longer grows with the size of the company's history.
 *
 * Each entry declares the permission behind it, so a lookup can never surface
 * rows the user cannot already reach in its own module (rule 6).
 *
 * `label`/`hint` are built from stored, language-neutral values — a label is
 * for identifying a record, never a translated status (rule 4).
 */
final class LookupRegistry
{
    /** Rows a single lookup call may ever return. */
    public const MAX_LIMIT = 50;

    public const DEFAULT_LIMIT = 20;

    /**
     * @return array<string, array{
     *     permission: string,
     *     model: class-string<Model>,
     *     with?: list<string>,
     *     filters?: callable(Builder, Request): void,
     *     order: callable(Builder): void,
     *     label: callable(Model): string,
     *     hint?: callable(Model): (string|null),
     *     meta?: callable(Model): array<string, mixed>
     * }>
     */
    public static function all(): array
    {
        return [
            'suppliers' => [
                'permission' => 'payables.view',
                'model' => Supplier::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (Supplier $row): string => $row->name,
                'hint' => fn (Supplier $row): ?string => $row->tax_number,
            ],

            'clients' => [
                'permission' => 'receivables.manage',
                'model' => Client::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (Client $row): string => $row->name,
                'hint' => fn (Client $row): ?string => $row->tax_number,
            ],

            'employees' => [
                'permission' => 'employees.manage',
                'model' => Employee::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status')))
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('status', 'active')),
                'order' => fn (Builder $query) => $query->orderBy('last_name')->orderBy('first_name'),
                'label' => fn (Employee $row): string => $row->full_name,
                'hint' => fn (Employee $row): ?string => $row->job_role,
            ],

            'worksites' => [
                'permission' => 'worksites.manage',
                'model' => Worksite::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true))
                    ->when(
                        $request->filled('mine_id'),
                        fn (Builder $q) => $q->where('mine_id', $request->integer('mine_id')),
                    )
                    ->when(
                        $request->filled('project_id'),
                        fn (Builder $q) => $q->where('project_id', $request->integer('project_id')),
                    ),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (Worksite $row): string => $row->name,
                'hint' => fn (Worksite $row): ?string => $row->location,
                // Carried so a form filtering by mine or project can narrow its
                // worksite field without a second round trip.
                'meta' => fn (Worksite $row): array => [
                    'mine_id' => $row->mine_id,
                    'project_id' => $row->project_id,
                ],
            ],

            'mines' => [
                'permission' => 'worksites.manage',
                'model' => Mine::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (Mine $row): string => $row->name,
                'hint' => fn (Mine $row): ?string => $row->location,
            ],

            'projects' => [
                'permission' => 'worksites.manage',
                'model' => Project::class,
                'with' => ['client'],
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (Project $row): string => $row->name,
                'hint' => fn (Project $row): ?string => $row->client?->name,
            ],

            'masters' => [
                'permission' => 'masters.manage',
                'model' => Master::class,
                'with' => ['employee'],
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderByDesc('id'),
                'label' => fn (Master $row): string => $row->employee?->full_name ?? "#{$row->id}",
            ],

            'machines' => [
                'permission' => 'machines.manage',
                'model' => Machine::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->filled('status'), fn (Builder $q) => $q->where('status', $request->input('status'))),
                'order' => fn (Builder $query) => $query->orderBy('brand')->orderBy('model'),
                'label' => fn (Machine $row): string => trim("{$row->brand} {$row->model}") ?: "#{$row->id}",
                'hint' => fn (Machine $row): ?string => $row->serial_number,
                // Carried so a form that links paperwork to a machine can offer
                // the invoice that machine was bought on without a second call.
                'meta' => fn (Machine $row): array => [
                    'payable_invoice_id' => $row->payable_invoice_id,
                    'machine_type' => $row->machine_type,
                ],
            ],

            'houses' => [
                'permission' => 'housing.manage',
                'model' => House::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (House $row): string => $row->name,
                'hint' => fn (House $row): ?string => $row->address,
            ],

            'users' => [
                'permission' => 'users.manage',
                'model' => User::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('active_only'), fn (Builder $q) => $q->where('is_active', true)),
                'order' => fn (Builder $query) => $query->orderBy('name'),
                'label' => fn (User $row): string => $row->name,
                'hint' => fn (User $row): ?string => $row->email,
            ],

            // Supplier invoices, for the fields that link paperwork to a debt.
            'payable-invoices' => [
                'permission' => 'payables.view',
                'model' => PayableInvoice::class,
                'with' => ['supplier'],
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
                    ->when(
                        $request->filled('supplier_id'),
                        fn (Builder $q) => $q->where('supplier_id', $request->integer('supplier_id')),
                    ),
                'order' => fn (Builder $query) => $query->orderByDesc('invoice_date')->orderByDesc('id'),
                'label' => fn (PayableInvoice $row): string => trim(implode(' · ', array_filter([
                    $row->supplier?->name,
                    $row->invoice_number ?: "#{$row->id}",
                ]))),
                'hint' => fn (PayableInvoice $row): ?string => optional($row->invoice_date)->toDateString(),
                'meta' => fn (PayableInvoice $row): array => [
                    'amount' => (float) $row->original_amount,
                    'remaining' => (float) $row->remaining_amount,
                    'currency' => $row->currency,
                    'status' => $row->status,
                ],
            ],

            'receivable-invoices' => [
                'permission' => 'receivables.manage',
                'model' => ReceivableInvoice::class,
                'with' => ['client'],
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
                    ->when(
                        $request->filled('client_id'),
                        fn (Builder $q) => $q->where('client_id', $request->integer('client_id')),
                    ),
                'order' => fn (Builder $query) => $query->orderByDesc('invoice_date')->orderByDesc('id'),
                'label' => fn (ReceivableInvoice $row): string => trim(implode(' · ', array_filter([
                    $row->client?->name,
                    $row->invoice_number ?: "#{$row->id}",
                ]))),
                'hint' => fn (ReceivableInvoice $row): ?string => optional($row->invoice_date)->toDateString(),
                'meta' => fn (ReceivableInvoice $row): array => [
                    'amount' => (float) $row->invoice_amount,
                    'remaining' => (float) $row->remaining_amount,
                    'currency' => $row->currency,
                    'status' => $row->status,
                ],
            ],

            /*
             * Bank and cash movements are the same table; `account` picks the
             * side, because a form that asks for "the cash payment" should not
             * offer bank rows and vice versa.
             */
            'bank-transactions' => [
                'permission' => 'bank_transactions.manage',
                'model' => BankTransaction::class,
                'filters' => fn (Builder $query, Request $request) => $query
                    ->when($request->input('account') === 'cash', fn (Builder $q) => $q->where('cash_amount', '!=', 0))
                    ->when($request->input('account') === 'bank', fn (Builder $q) => $q->where(
                        fn (Builder $inner) => $inner->where('nlb_amount', '!=', 0)->orWhere('lovcen_amount', '!=', 0),
                    ))
                    ->when($request->boolean('unmatched'), fn (Builder $q) => $q->unmatched())
                    ->when(
                        $request->filled('date_from'),
                        fn (Builder $q) => $q->whereDate('date', '>=', $request->input('date_from')),
                    )
                    ->when(
                        $request->filled('date_to'),
                        fn (Builder $q) => $q->whereDate('date', '<=', $request->input('date_to')),
                    ),
                'order' => fn (Builder $query) => $query->orderByDesc('date')->orderByDesc('id'),
                'label' => fn (BankTransaction $row): string => trim(implode(' · ', array_filter([
                    optional($row->date)->toDateString(),
                    $row->description_1 ?: "#{$row->id}",
                ]))),
                'hint' => fn (BankTransaction $row): ?string => $row->description_2,
                'meta' => fn (BankTransaction $row): array => [
                    'amount' => $row->net_amount,
                    'currency' => $row->currency,
                    'category' => $row->category,
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $resource): ?array
    {
        return self::all()[$resource] ?? null;
    }
}
