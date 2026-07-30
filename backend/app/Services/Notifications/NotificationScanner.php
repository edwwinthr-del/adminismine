<?php

namespace App\Services\Notifications;

use App\Models\AttendanceRecord;
use App\Models\BankTransaction;
use App\Models\CustomsDocument;
use App\Models\Employee;
use App\Models\House;
use App\Models\Machine;
use App\Models\NotificationRule;
use App\Models\PayableInvoice;
use App\Models\ProductionRecord;
use App\Models\RentPayment;
use App\Models\SalaryPayment;
use App\Models\UtilityBill;
use App\Models\WorkerNeed;
use App\Support\MonthPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Finds what is currently worth notifying about. Every detector reads the same
 * records the modules already expose (outstanding/overdue scopes and the like),
 * so a notification can never disagree with the page it links to.
 *
 * Detectors only find candidates. Who is told, and whether a candidate is close
 * enough in time to matter, is the dispatcher's and the rule's business.
 */
class NotificationScanner
{
    /** @return Collection<int, NotificationCandidate> */
    public function candidatesFor(NotificationRule $rule, ?Carbon $today = null): Collection
    {
        $today = ($today ?? now())->copy()->startOfDay();

        return match ($rule->type) {
            'payables.unpaid' => $this->payablesUnpaid(),
            'payables.overdue' => $this->payablesOverdue($today),
            'housing.rent_unpaid' => $this->rentUnpaid(),
            'housing.bills_overdue' => $this->billsOverdue($today),
            'housing.contract_expiring' => $this->houseContractsExpiring(),
            'salaries.unpaid' => $this->salariesUnpaid(),
            'attendance.unapproved' => $this->attendanceUnapproved(),
            'attendance.overtime_pending' => $this->overtimePending(),
            'worker_needs.urgent_open' => $this->urgentWorkerNeeds(),
            'mining.production_missing' => $this->productionMissing($today),
            'machines.document_expiring' => $this->machineDocumentsExpiring(),
            'customs.incomplete' => $this->customsIncomplete(),
            'bank.unmatched' => $this->bankUnmatched(),
            'employees.missing_documents' => $this->employeesMissingDocuments(),
            'employees.document_expiring' => $this->employeeDocumentsExpiring($rule),
            default => collect(),
        };
    }

    /** @return Collection<int, NotificationCandidate> */
    private function payablesUnpaid(): Collection
    {
        return PayableInvoice::query()->outstanding()->with('supplier')->get()
            ->map(fn (PayableInvoice $invoice) => new NotificationCandidate(
                subject: $invoice,
                data: [
                    'supplier' => $invoice->supplier?->name,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => (float) $invoice->remaining_amount,
                ],
                dueDate: $invoice->due_date,
                amount: (float) $invoice->remaining_amount,
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function payablesOverdue(Carbon $today): Collection
    {
        return PayableInvoice::query()->overdue()->with('supplier')->get()
            ->map(fn (PayableInvoice $invoice) => new NotificationCandidate(
                subject: $invoice,
                data: [
                    'supplier' => $invoice->supplier?->name,
                    'invoice_number' => $invoice->invoice_number,
                    'amount' => (float) $invoice->remaining_amount,
                    'days_overdue' => $invoice->due_date->diffInDays($today),
                ],
                dueDate: $invoice->due_date,
                amount: (float) $invoice->remaining_amount,
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function rentUnpaid(): Collection
    {
        return RentPayment::query()->outstanding()->with('house')->get()
            ->map(fn (RentPayment $rent) => new NotificationCandidate(
                subject: $rent,
                data: [
                    'house' => $rent->house?->name,
                    'month' => $rent->month->toDateString(),
                    'amount' => (float) $rent->remaining_amount,
                ],
                dueDate: $rent->due_date,
                amount: (float) $rent->remaining_amount,
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function billsOverdue(Carbon $today): Collection
    {
        return UtilityBill::query()->overdue()->with('house')->get()
            ->map(fn (UtilityBill $bill) => new NotificationCandidate(
                subject: $bill,
                data: [
                    'house' => $bill->house?->name,
                    'bill_type' => $bill->bill_type,
                    'amount' => (float) $bill->remaining_amount,
                    'days_overdue' => $bill->due_date->diffInDays($today),
                ],
                dueDate: $bill->due_date,
                amount: (float) $bill->remaining_amount,
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function houseContractsExpiring(): Collection
    {
        return House::query()->active()->whereNotNull('contract_end_date')->get()
            ->map(fn (House $house) => new NotificationCandidate(
                subject: $house,
                data: [
                    'house' => $house->name,
                    'contract_end_date' => $house->contract_end_date->toDateString(),
                ],
                dueDate: $house->contract_end_date,
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function salariesUnpaid(): Collection
    {
        return SalaryPayment::query()->outstanding()->with('employee')->get()
            ->map(fn (SalaryPayment $salary) => new NotificationCandidate(
                subject: $salary,
                data: [
                    'worker' => $salary->employee?->full_name,
                    'month' => $salary->salary_month->toDateString(),
                    'amount' => (float) $salary->remaining_amount,
                ],
                // Salary for a month is due once that month is over.
                dueDate: $salary->salary_month->copy()->endOfMonth(),
                amount: (float) $salary->remaining_amount,
            ));
    }

    /**
     * Grouped by day: one notification per day waiting for approval beats one
     * per worker per day, which would bury everything else.
     *
     * @return Collection<int, NotificationCandidate>
     */
    private function attendanceUnapproved(): Collection
    {
        return AttendanceRecord::query()
            ->where('approval_status', 'submitted')
            ->get()
            ->groupBy(fn (AttendanceRecord $record): string => $record->date->toDateString())
            ->map(fn (Collection $records, string $date) => new NotificationCandidate(
                data: ['date' => $date, 'count' => $records->count()],
                dueDate: Carbon::parse($date),
                key: "date:{$date}",
            ))
            ->values();
    }

    /** @return Collection<int, NotificationCandidate> */
    private function overtimePending(): Collection
    {
        return AttendanceRecord::query()
            ->where('overtime_hours', '>', 0)
            ->whereIn('approval_status', ['draft', 'submitted'])
            ->get()
            ->groupBy(fn (AttendanceRecord $record): string => $record->date->toDateString())
            ->map(fn (Collection $records, string $date) => new NotificationCandidate(
                data: [
                    'date' => $date,
                    'count' => $records->count(),
                    'hours' => round((float) $records->sum('overtime_hours'), 2),
                ],
                dueDate: Carbon::parse($date),
                key: "date:{$date}",
            ))
            ->values();
    }

    /** @return Collection<int, NotificationCandidate> */
    private function urgentWorkerNeeds(): Collection
    {
        return WorkerNeed::query()->open()->where('priority', 'urgent')->with('employee')->get()
            ->map(fn (WorkerNeed $need) => new NotificationCandidate(
                subject: $need,
                data: [
                    'worker' => $need->employee?->full_name,
                    'need_type' => $need->need_type,
                    'status' => $need->status,
                ],
            ));
    }

    /**
     * The month that has just ended with nothing reported. Checked once the
     * month is over, so an in-progress month is never flagged.
     *
     * @return Collection<int, NotificationCandidate>
     */
    private function productionMissing(Carbon $today): Collection
    {
        $month = MonthPeriod::normalize($today->copy()->subMonthNoOverflow()->toDateString());

        if (ProductionRecord::query()->forMonth($month)->exists()) {
            return collect();
        }

        return collect([new NotificationCandidate(
            data: ['month' => $month],
            dueDate: Carbon::parse(MonthPeriod::end($month)),
            key: "month:{$month}",
        )]);
    }

    /** @return Collection<int, NotificationCandidate> */
    private function machineDocumentsExpiring(): Collection
    {
        $fields = ['registration_expiry', 'insurance_expiry'];

        return Machine::query()
            ->where(function ($query) use ($fields): void {
                foreach ($fields as $field) {
                    $query->orWhereNotNull($field);
                }
            })
            ->get()
            ->flatMap(function (Machine $machine) use ($fields): Collection {
                $rows = collect();

                foreach ($fields as $field) {
                    if ($machine->{$field} === null) {
                        continue;
                    }

                    $rows->push(new NotificationCandidate(
                        subject: $machine,
                        data: [
                            'machine' => trim("{$machine->brand} {$machine->model}"),
                            'document' => $field,
                            'expires_on' => $machine->{$field}->toDateString(),
                        ],
                        dueDate: $machine->{$field},
                        key: "Machine:{$machine->id}:{$field}",
                    ));
                }

                return $rows;
            });
    }

    /** @return Collection<int, NotificationCandidate> */
    private function customsIncomplete(): Collection
    {
        return CustomsDocument::query()->open()->missingPaperwork()->get()
            ->map(fn (CustomsDocument $document) => new NotificationCandidate(
                subject: $document,
                data: [
                    'document_type' => $document->document_type,
                    'reference' => $document->document_number,
                    'status' => $document->status,
                ],
            ));
    }

    /**
     * Movements no payment points at — the ones nobody has tied to an invoice,
     * rent, salary or loan yet.
     *
     * @return Collection<int, NotificationCandidate>
     */
    private function bankUnmatched(): Collection
    {
        return BankTransaction::query()->doesntHave('payments')->get()
            ->map(fn (BankTransaction $transaction) => new NotificationCandidate(
                subject: $transaction,
                data: [
                    'date' => $transaction->date->toDateString(),
                    'description' => $transaction->description_1,
                    'amount' => $transaction->net_amount,
                ],
                dueDate: $transaction->date,
                amount: abs($transaction->net_amount),
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function employeesMissingDocuments(): Collection
    {
        return Employee::query()->active()->missingDocuments()->get()
            ->map(fn (Employee $employee) => new NotificationCandidate(
                subject: $employee,
                data: [
                    'worker' => $employee->full_name,
                    'missing' => $employee->missing_documents,
                ],
            ));
    }

    /** @return Collection<int, NotificationCandidate> */
    private function employeeDocumentsExpiring(NotificationRule $rule): Collection
    {
        $days = $rule->days_before ?? Employee::EXPIRY_WARNING_DAYS;

        return Employee::query()->active()->withExpiringDocuments($days)->get()
            ->flatMap(function (Employee $employee): Collection {
                $rows = collect();

                foreach (Employee::EXPIRY_FIELDS as $field) {
                    if ($employee->{$field} === null) {
                        continue;
                    }

                    $rows->push(new NotificationCandidate(
                        subject: $employee,
                        data: [
                            'worker' => $employee->full_name,
                            'document' => $field,
                            'expires_on' => $employee->{$field}->toDateString(),
                        ],
                        dueDate: $employee->{$field},
                        key: "Employee:{$employee->id}:{$field}",
                    ));
                }

                return $rows;
            });
    }
}
