<?php

namespace App\Reports;

use App\Models\AttendanceRecord;
use App\Models\CustomsDocument;
use App\Models\Employee;
use App\Models\FlightTicket;
use App\Models\House;
use App\Models\Loan;
use App\Models\Machine;
use App\Models\ProductionRecord;
use App\Models\RentPayment;
use App\Models\SalaryPayment;
use App\Models\TravelExpense;
use App\Models\User;
use App\Models\UtilityBill;
use App\Models\WorkerNeed;
use App\Support\MonthPeriod;
use Illuminate\Support\Collection;

/**
 * Every report the app can produce. Adding one means adding an entry here — the
 * API, the Excel writer and the PDF writer all work off this list, so nothing
 * else has to change.
 */
class ReportRegistry
{
    /** @var array<string, Report>|null */
    private ?array $reports = null;

    /** @return array<string, Report> */
    public function all(): array
    {
        return $this->reports ??= collect($this->build())
            ->keyBy(fn (Report $report): string => $report->key())
            ->all();
    }

    public function find(string $key): ?Report
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Reports the user may run, by named permission (rule 6).
     *
     * @return array<string, Report>
     */
    public function availableTo(User $user): array
    {
        return array_filter($this->all(), fn (Report $report): bool => $user->can($report->permission()));
    }

    /** @return list<Report> */
    private function build(): array
    {
        return [
            new MonthlyCashflowReport,
            AgingReport::payables(),
            AgingReport::receivables(),
            StatementReport::supplier(),
            StatementReport::client(),
            $this->workerPayments(),
            $this->salaryPayments(),
            $this->attendance(),
            $this->overtime(),
            $this->dailyEarnedPay(),
            $this->workerNeeds(),
            $this->minedGoods(),
            $this->engineerProduction(),
            $this->machineRegister(),
            $this->customsRegister(),
            $this->housingCost(),
            $this->unpaidRentAndBills(),
            $this->travelAndTickets(),
            $this->loanBalances(),
        ];
    }

    /** Bank account state and what each worker has been paid. */
    private function workerPayments(): TableReport
    {
        return new TableReport(
            key: 'worker_payment_report',
            permission: 'employees.manage',
            columns: [
                ['key' => 'worker', 'label' => 'worker', 'type' => 'text'],
                ['key' => 'job_role', 'label' => 'jobRole', 'type' => 'text'],
                ['key' => 'bank_account_status', 'label' => 'bankAccount', 'type' => 'text'],
                ['key' => 'paid', 'label' => 'paid', 'type' => 'money'],
                ['key' => 'outstanding', 'label' => 'outstanding', 'type' => 'money'],
            ],
            resolver: function (array $filters): array {
                $salaries = SalaryPayment::query()
                    ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                    ->get()
                    ->groupBy('employee_id');

                return Employee::query()->orderBy('last_name')->get()
                    ->map(fn (Employee $employee): array => [
                        'worker' => $employee->full_name,
                        'job_role' => $employee->job_role,
                        'bank_account_status' => $employee->bank_account_status,
                        'paid' => round((float) ($salaries->get($employee->id)?->sum('paid_amount') ?? 0), 2),
                        'outstanding' => round((float) ($salaries->get($employee->id)?->sum('remaining_amount') ?? 0), 2),
                    ])->all();
            },
            filters: ['month'],
            signature: true,
        );
    }

    private function salaryPayments(): TableReport
    {
        return new TableReport(
            key: 'employee_salary_payment_report',
            permission: 'salary_payments.manage',
            columns: [
                ['key' => 'worker', 'label' => 'worker', 'type' => 'text'],
                ['key' => 'salary_month', 'label' => 'month', 'type' => 'month'],
                ['key' => 'net_salary_due', 'label' => 'netSalary', 'type' => 'money'],
                ['key' => 'paid_amount', 'label' => 'paid', 'type' => 'money'],
                ['key' => 'remaining_amount', 'label' => 'remaining', 'type' => 'money'],
                ['key' => 'status', 'label' => 'status', 'type' => 'text'],
            ],
            resolver: fn (array $filters): array => SalaryPayment::query()
                ->with('employee')
                ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
                ->orderByDesc('salary_month')
                ->get()
                ->map(fn (SalaryPayment $salary): array => [
                    'worker' => $salary->employee?->full_name,
                    'salary_month' => optional($salary->salary_month)->toDateString(),
                    // EUR throughout, like every report: paid and remaining are
                    // the accounting currency, so the net due is quoted the same
                    // way rather than in whatever the wage was agreed in.
                    'net_salary_due' => round((float) $salary->amount_eur, 2),
                    'paid_amount' => round((float) $salary->paid_amount, 2),
                    'remaining_amount' => round((float) $salary->remaining_amount, 2),
                    'status' => $salary->status,
                ])->all(),
            filters: ['month', 'employee_id'],
            signature: true,
        );
    }

    private function attendance(): TableReport
    {
        return new TableReport(
            key: 'attendance_report',
            permission: 'attendance.submit',
            columns: [
                ['key' => 'date', 'label' => 'date', 'type' => 'date'],
                ['key' => 'worker', 'label' => 'worker', 'type' => 'text'],
                ['key' => 'worksite', 'label' => 'worksite', 'type' => 'text'],
                ['key' => 'status', 'label' => 'status', 'type' => 'text'],
                ['key' => 'regular_hours', 'label' => 'hours', 'type' => 'number'],
                ['key' => 'overtime_hours', 'label' => 'overtimeHours', 'type' => 'number'],
                ['key' => 'approval_status', 'label' => 'approval', 'type' => 'text'],
            ],
            resolver: fn (array $filters): array => AttendanceRecord::query()
                ->with(['employee', 'worksite'])
                ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                ->when(! empty($filters['worksite_id']), fn ($q) => $q->where('worksite_id', (int) $filters['worksite_id']))
                ->when(! empty($filters['mine_id']), fn ($q) => $q->forMine((int) $filters['mine_id']))
                ->when(! empty($filters['project_id']), fn ($q) => $q->forProject((int) $filters['project_id']))
                ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
                ->orderBy('date')
                ->get()
                ->map(fn (AttendanceRecord $record): array => [
                    'date' => optional($record->date)->toDateString(),
                    'worker' => $record->employee?->full_name,
                    'worksite' => $record->worksite?->name,
                    'status' => $record->status,
                    'regular_hours' => (float) $record->regular_hours,
                    'overtime_hours' => (float) $record->overtime_hours,
                    'approval_status' => $record->approval_status,
                ])->all(),
            filters: ['month', 'worksite_id', 'mine_id', 'project_id', 'employee_id'],
        );
    }

    private function overtime(): TableReport
    {
        return new TableReport(
            key: 'overtime_report',
            permission: 'overtime.approve',
            columns: [
                ['key' => 'date', 'label' => 'date', 'type' => 'date'],
                ['key' => 'worker', 'label' => 'worker', 'type' => 'text'],
                ['key' => 'overtime_hours', 'label' => 'overtimeHours', 'type' => 'number'],
                ['key' => 'overtime_amount', 'label' => 'overtimePay', 'type' => 'money'],
                ['key' => 'reason', 'label' => 'reason', 'type' => 'text'],
                ['key' => 'approval_status', 'label' => 'approval', 'type' => 'text'],
            ],
            resolver: fn (array $filters): array => AttendanceRecord::query()
                ->with('employee')
                ->where('overtime_hours', '>', 0)
                ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                ->when(! empty($filters['employee_id']), fn ($q) => $q->where('employee_id', (int) $filters['employee_id']))
                ->orderBy('date')
                ->get()
                ->map(fn (AttendanceRecord $record): array => [
                    'date' => optional($record->date)->toDateString(),
                    'worker' => $record->employee?->full_name,
                    'overtime_hours' => (float) $record->overtime_hours,
                    'overtime_amount' => round((float) $record->overtime_amount, 2),
                    'reason' => $record->overtime_reason,
                    'approval_status' => $record->approval_status,
                ])->all(),
            filters: ['month', 'employee_id'],
            signature: true,
        );
    }

    /**
     * Earned pay is computed onto each attendance record when it is saved, so
     * the report sums those rather than recomputing — the report and payroll
     * can never disagree about what a worker earned.
     */
    private function dailyEarnedPay(): TableReport
    {
        return new TableReport(
            key: 'daily_earned_pay_report',
            permission: 'attendance.submit',
            columns: [
                ['key' => 'worker', 'label' => 'worker', 'type' => 'text'],
                ['key' => 'days_worked', 'label' => 'daysWorked', 'type' => 'number'],
                ['key' => 'approved_days', 'label' => 'approvedDays', 'type' => 'number'],
                ['key' => 'regular_amount', 'label' => 'baseEarned', 'type' => 'money'],
                ['key' => 'overtime_amount', 'label' => 'overtimeEarned', 'type' => 'money'],
                ['key' => 'total_amount', 'label' => 'totalEarned', 'type' => 'money'],
            ],
            resolver: function (array $filters): array {
                $month = MonthPeriod::normalize($filters['month'] ?? now()->toDateString());

                return AttendanceRecord::query()
                    ->with('employee')
                    ->forMonth($month)
                    ->when(
                        ! empty($filters['worksite_id']),
                        fn ($q) => $q->where('worksite_id', (int) $filters['worksite_id']),
                    )
                    ->when(! empty($filters['mine_id']), fn ($q) => $q->forMine((int) $filters['mine_id']))
                    ->when(! empty($filters['project_id']), fn ($q) => $q->forProject((int) $filters['project_id']))
                    ->get()
                    ->groupBy('employee_id')
                    ->map(fn (Collection $records): array => [
                        'worker' => $records->first()->employee?->full_name,
                        'days_worked' => $records->whereIn('status', AttendanceRecord::PAID_STATUSES)->count(),
                        // Only approved days reach payroll.
                        'approved_days' => $records->where('approval_status', 'approved')->count(),
                        'regular_amount' => round((float) $records->sum('regular_amount'), 2),
                        'overtime_amount' => round((float) $records->sum('overtime_amount'), 2),
                        'total_amount' => round((float) $records->sum('total_amount'), 2),
                    ])
                    ->sortBy('worker')
                    ->values()
                    ->all();
            },
            filters: ['month', 'worksite_id', 'mine_id', 'project_id'],
            signature: true,
        );
    }

    private function workerNeeds(): TableReport
    {
        return new TableReport(
            key: 'worker_needs_report',
            permission: 'worker_needs.manage',
            columns: [
                ['key' => 'date', 'label' => 'date', 'type' => 'date'],
                ['key' => 'worker', 'label' => 'worker', 'type' => 'text'],
                ['key' => 'need_type', 'label' => 'needType', 'type' => 'text'],
                ['key' => 'priority', 'label' => 'priority', 'type' => 'text'],
                ['key' => 'status', 'label' => 'status', 'type' => 'text'],
                ['key' => 'description', 'label' => 'description', 'type' => 'text'],
            ],
            resolver: fn (array $filters): array => WorkerNeed::query()
                ->with('employee')
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('date', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('date', '<=', $filters['date_to']))
                ->orderByDesc('date')
                ->get()
                ->map(fn (WorkerNeed $need): array => [
                    'date' => optional($need->date)->toDateString(),
                    'worker' => $need->employee?->full_name,
                    'need_type' => $need->need_type,
                    'priority' => $need->priority,
                    'status' => $need->status,
                    'description' => $need->description,
                ])->all(),
        );
    }

    private function minedGoods(): TableReport
    {
        return new TableReport(
            key: 'monthly_mined_goods_report',
            permission: 'mining_production.submit',
            columns: [
                ['key' => 'material_type', 'label' => 'material', 'type' => 'text'],
                ['key' => 'unit', 'label' => 'unit', 'type' => 'text'],
                ['key' => 'quantity', 'label' => 'quantity', 'type' => 'number'],
                ['key' => 'entries', 'label' => 'entries', 'type' => 'number'],
            ],
            resolver: fn (array $filters): array => ProductionRecord::query()
                ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                ->when(! empty($filters['worksite_id']), fn ($q) => $q->where('worksite_id', (int) $filters['worksite_id']))
                ->when(! empty($filters['mine_id']), fn ($q) => $q->forMine((int) $filters['mine_id']))
                ->when(! empty($filters['project_id']), fn ($q) => $q->forProject((int) $filters['project_id']))
                ->get()
                ->groupBy(fn (ProductionRecord $r): string => $r->material_type.'|'.$r->unit)
                ->map(fn (Collection $group, string $key): array => [
                    'material_type' => explode('|', $key)[0],
                    'unit' => explode('|', $key)[1],
                    'quantity' => round((float) $group->sum('quantity'), 3),
                    'entries' => $group->count(),
                ])->values()->all(),
            filters: ['month', 'worksite_id', 'mine_id', 'project_id'],
            signature: true,
        );
    }

    private function engineerProduction(): TableReport
    {
        return new TableReport(
            key: 'mining_engineer_production_report',
            permission: 'mining_production.submit',
            columns: [
                ['key' => 'engineer', 'label' => 'engineer', 'type' => 'text'],
                ['key' => 'entries', 'label' => 'entries', 'type' => 'number'],
                ['key' => 'quantity', 'label' => 'quantity', 'type' => 'number'],
                ['key' => 'approved', 'label' => 'approved', 'type' => 'number'],
            ],
            resolver: fn (array $filters): array => ProductionRecord::query()
                ->with('engineer')
                ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                ->get()
                ->groupBy('engineer_id')
                ->map(fn (Collection $group): array => [
                    'engineer' => $group->first()->engineer?->name ?? '—',
                    'entries' => $group->count(),
                    'quantity' => round((float) $group->sum('quantity'), 3),
                    'approved' => $group->where('approval_status', 'approved')->count(),
                ])->values()->all(),
            filters: ['month'],
        );
    }

    private function machineRegister(): TableReport
    {
        return new TableReport(
            key: 'machine_equipment_register',
            permission: 'machines.manage',
            columns: [
                ['key' => 'machine', 'label' => 'machine', 'type' => 'text'],
                ['key' => 'machine_type', 'label' => 'type', 'type' => 'text'],
                ['key' => 'serial_number', 'label' => 'serial', 'type' => 'text'],
                ['key' => 'purchase_date', 'label' => 'purchaseDate', 'type' => 'date'],
                ['key' => 'purchase_amount', 'label' => 'purchaseAmount', 'type' => 'money'],
                ['key' => 'status', 'label' => 'status', 'type' => 'text'],
                ['key' => 'registration_expiry', 'label' => 'registration', 'type' => 'date'],
                ['key' => 'insurance_expiry', 'label' => 'insurance', 'type' => 'date'],
            ],
            resolver: fn (array $filters): array => Machine::query()
                ->when(! empty($filters['worksite_id']), fn ($q) => $q->where('worksite_id', (int) $filters['worksite_id']))
                ->when(! empty($filters['mine_id']), fn ($q) => $q->forMine((int) $filters['mine_id']))
                ->when(! empty($filters['project_id']), fn ($q) => $q->forProject((int) $filters['project_id']))
                ->orderBy('brand')->get()
                ->map(fn (Machine $machine): array => [
                    'machine' => trim("{$machine->brand} {$machine->model}"),
                    'machine_type' => $machine->machine_type,
                    'serial_number' => $machine->serial_number,
                    'purchase_date' => optional($machine->purchase_date)->toDateString(),
                    'purchase_amount' => round((float) $machine->purchase_amount, 2),
                    'status' => $machine->status,
                    'registration_expiry' => optional($machine->registration_expiry)->toDateString(),
                    'insurance_expiry' => optional($machine->insurance_expiry)->toDateString(),
                ])->all(),
            filters: ['worksite_id', 'mine_id', 'project_id'],
            signature: true,
        );
    }

    private function customsRegister(): TableReport
    {
        return new TableReport(
            key: 'customs_transport_document_register',
            permission: 'customs_documents.manage',
            columns: [
                ['key' => 'document_type', 'label' => 'documentType', 'type' => 'text'],
                ['key' => 'document_number', 'label' => 'documentNumber', 'type' => 'text'],
                ['key' => 'cmr_number', 'label' => 'cmrNumber', 'type' => 'text'],
                ['key' => 'issue_date', 'label' => 'issueDate', 'type' => 'date'],
                ['key' => 'sender', 'label' => 'sender', 'type' => 'text'],
                ['key' => 'receiver', 'label' => 'receiver', 'type' => 'text'],
                ['key' => 'status', 'label' => 'status', 'type' => 'text'],
            ],
            resolver: fn (array $filters): array => CustomsDocument::query()
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('issue_date', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('issue_date', '<=', $filters['date_to']))
                ->orderByDesc('issue_date')
                ->get()
                ->map(fn (CustomsDocument $document): array => [
                    'document_type' => $document->document_type,
                    'document_number' => $document->document_number,
                    'cmr_number' => $document->cmr_number,
                    'issue_date' => optional($document->issue_date)->toDateString(),
                    'sender' => $document->sender,
                    'receiver' => $document->receiver,
                    'status' => $document->status,
                ])->all(),
            signature: true,
        );
    }

    private function housingCost(): TableReport
    {
        return new TableReport(
            key: 'worker_housing_cost_report',
            permission: 'housing.manage',
            columns: [
                ['key' => 'house', 'label' => 'house', 'type' => 'text'],
                ['key' => 'occupants', 'label' => 'occupants', 'type' => 'number'],
                ['key' => 'rent_due', 'label' => 'rentDue', 'type' => 'money'],
                ['key' => 'bills', 'label' => 'bills', 'type' => 'money'],
                ['key' => 'total', 'label' => 'total', 'type' => 'money'],
            ],
            resolver: function (array $filters): array {
                $month = MonthPeriod::normalize($filters['month'] ?? now()->toDateString());
                $rents = RentPayment::query()->with('house')->forMonth($month)->get();
                $bills = UtilityBill::query()->with('house')->forMonth($month)->get();

                return House::query()->with('currentOccupancies')->orderBy('name')->get()
                    ->map(function ($house) use ($rents, $bills): array {
                        // EUR, so houses on leases in different currencies can be
                        // totalled in one column (rule 5).
                        $rent = round((float) $rents->where('house_id', $house->id)->sum('amount_eur'), 2);
                        $bill = round((float) $bills->where('house_id', $house->id)->sum('amount_eur'), 2);

                        return [
                            'house' => $house->name,
                            'occupants' => $house->currentOccupancies->count(),
                            'rent_due' => $rent,
                            'bills' => $bill,
                            'total' => round($rent + $bill, 2),
                        ];
                    })->all();
            },
            filters: ['month'],
            signature: true,
        );
    }

    private function unpaidRentAndBills(): TableReport
    {
        return new TableReport(
            key: 'unpaid_rent_and_bills_report',
            permission: 'housing.manage',
            columns: [
                ['key' => 'house', 'label' => 'house', 'type' => 'text'],
                ['key' => 'kind', 'label' => 'kind', 'type' => 'text'],
                ['key' => 'period', 'label' => 'period', 'type' => 'date'],
                ['key' => 'due', 'label' => 'due', 'type' => 'money'],
                ['key' => 'paid', 'label' => 'paid', 'type' => 'money'],
                ['key' => 'remaining', 'label' => 'remaining', 'type' => 'money'],
            ],
            resolver: function (array $filters): array {
                $rents = RentPayment::query()->with('house')->outstanding()
                    ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                    ->get()
                    ->map(fn (RentPayment $rent): array => [
                        'house' => $rent->house?->name,
                        'kind' => 'rent',
                        'period' => optional($rent->month)->toDateString(),
                        'due' => round((float) $rent->amount_eur, 2),
                        'paid' => round((float) $rent->paid_amount, 2),
                        'remaining' => round((float) $rent->remaining_amount, 2),
                    ]);

                $bills = UtilityBill::query()->with('house')->outstanding()
                    ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                    ->get()
                    ->map(fn (UtilityBill $bill): array => [
                        'house' => $bill->house?->name,
                        'kind' => $bill->bill_type,
                        'period' => optional($bill->billing_period)->toDateString(),
                        'due' => round((float) $bill->amount_eur, 2),
                        'paid' => round((float) $bill->paid_amount, 2),
                        'remaining' => round((float) $bill->remaining_amount, 2),
                    ]);

                return $rents->concat($bills)->sortBy('period')->values()->all();
            },
            filters: ['month'],
        );
    }

    private function travelAndTickets(): TableReport
    {
        return new TableReport(
            key: 'travel_and_ticket_cost_report',
            permission: 'travel.manage',
            columns: [
                ['key' => 'traveller', 'label' => 'traveller', 'type' => 'text'],
                ['key' => 'kind', 'label' => 'kind', 'type' => 'text'],
                ['key' => 'date', 'label' => 'date', 'type' => 'date'],
                ['key' => 'amount_eur', 'label' => 'amountEur', 'type' => 'money'],
                ['key' => 'remaining', 'label' => 'remaining', 'type' => 'money'],
                ['key' => 'cost_status', 'label' => 'costWritten', 'type' => 'text'],
            ],
            resolver: function (array $filters): array {
                $tickets = FlightTicket::query()->with('employee')
                    ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                    ->get()
                    ->map(fn (FlightTicket $ticket): array => [
                        'traveller' => $ticket->traveller_name,
                        'kind' => 'flight_ticket',
                        'date' => optional($ticket->ticket_date)->toDateString(),
                        'amount_eur' => round((float) $ticket->amount_eur, 2),
                        'remaining' => round((float) $ticket->remaining_amount, 2),
                        'cost_status' => $ticket->cost_status,
                    ]);

                $expenses = TravelExpense::query()->with('employee')
                    ->when(! empty($filters['month']), fn ($q) => $q->forMonth($filters['month']))
                    ->get()
                    ->map(fn (TravelExpense $expense): array => [
                        'traveller' => $expense->traveller_name,
                        'kind' => $expense->expense_type,
                        'date' => optional($expense->expense_date)->toDateString(),
                        'amount_eur' => round((float) $expense->amount_eur, 2),
                        'remaining' => round((float) $expense->remaining_amount, 2),
                        'cost_status' => $expense->cost_status,
                    ]);

                return $tickets->concat($expenses)->sortBy('date')->values()->all();
            },
            filters: ['month'],
        );
    }

    private function loanBalances(): TableReport
    {
        return new TableReport(
            key: 'loan_advance_balance_report',
            permission: 'loans.manage',
            columns: [
                ['key' => 'counterparty', 'label' => 'counterparty', 'type' => 'text'],
                ['key' => 'reference_number', 'label' => 'reference', 'type' => 'text'],
                ['key' => 'loan_date', 'label' => 'date', 'type' => 'date'],
                ['key' => 'amount_eur', 'label' => 'amountEur', 'type' => 'money'],
                ['key' => 'repaid_amount', 'label' => 'repaid', 'type' => 'money'],
                ['key' => 'remaining_amount', 'label' => 'remaining', 'type' => 'money'],
                ['key' => 'status', 'label' => 'status', 'type' => 'text'],
            ],
            resolver: fn (array $filters): array => Loan::query()
                ->when(! empty($filters['date_from']), fn ($q) => $q->whereDate('loan_date', '>=', $filters['date_from']))
                ->when(! empty($filters['date_to']), fn ($q) => $q->whereDate('loan_date', '<=', $filters['date_to']))
                ->orderByDesc('loan_date')
                ->get()
                ->map(fn (Loan $loan): array => [
                    'counterparty' => $loan->counterparty,
                    'reference_number' => $loan->reference_number,
                    'loan_date' => optional($loan->loan_date)->toDateString(),
                    'amount_eur' => round((float) $loan->amount_eur, 2),
                    'repaid_amount' => round((float) $loan->repaid_amount, 2),
                    'remaining_amount' => round((float) $loan->remaining_amount, 2),
                    'status' => $loan->status,
                ])->all(),
            signature: true,
        );
    }
}
