<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AdjustAttendanceRequest;
use App\Http\Requests\Attendance\RejectAttendanceRequest;
use App\Http\Requests\Attendance\ReviewAttendanceRequest;
use App\Http\Requests\Attendance\StoreAttendanceDayRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRecordRequest;
use App\Http\Resources\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\Worksite;
use App\Services\DailyEarnedPayService;
use App\Services\MasterAccessService;
use App\Services\WorkingDaysService;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly DailyEarnedPayService $earnedPay,
        private readonly MasterAccessService $masterAccess,
        private readonly WorkingDaysService $workingDays,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = AttendanceRecord::query()->with(['employee', 'worksite']);

        if ($request->filled('date')) {
            $query->forDay($request->date('date')->toDateString());
        }
        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('worksite_id')) {
            $query->where('worksite_id', $request->integer('worksite_id'));
        }
        // Mine and project live on the worksite, so these reach through it. The
        // master's own site restriction is still applied below either way.
        if ($request->filled('mine_id')) {
            $query->forMine($request->integer('mine_id'));
        }
        if ($request->filled('project_id')) {
            $query->forProject($request->integer('project_id'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->input('approval_status'));
        }

        $this->scopeToAccessibleWorksites($query, $request);

        $query->orderByDesc('date')->orderBy('employee_id');

        return AttendanceRecordResource::collection($query->paginate($request->integer('per_page', 200)));
    }

    /**
     * The fast daily entry screen: every employee assigned to the worksite with
     * their record for that date when one exists.
     */
    public function roster(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'worksite_id' => ['required', 'integer', 'exists:gradilista,id'],
            'date' => ['required', 'date'],
        ]);

        $this->masterAccess->assertCanActOn($request->user(), (int) $validated['worksite_id']);

        $date = $request->date('date')->toDateString();
        $worksite = Worksite::findOrFail($validated['worksite_id']);
        $workingDays = $this->workingDays->forDate($date);

        $employees = $worksite->employees()->where('radnici.status', 'active')->get()
            ->sortBy(fn (Employee $employee) => $employee->full_name)->values();

        $records = AttendanceRecord::query()
            ->forDay($date)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->get()
            ->keyBy('employee_id');

        $rows = $employees->map(fn (Employee $employee): array => [
            'employee_id' => $employee->id,
            'full_name' => $employee->full_name,
            'job_role' => $employee->job_role,
            'daily_rate' => $employee->dailyRate($workingDays),
            'currency' => $employee->salary_currency,
            'record' => $records->has($employee->id)
                ? new AttendanceRecordResource($records[$employee->id])
                : null,
        ]);

        return response()->json([
            'data' => $rows,
            'meta' => [
                'date' => $date,
                'worksite' => ['id' => $worksite->id, 'name' => $worksite->name],
                'working_days_basis' => $workingDays,
                'working_days_overridden' => $this->workingDays->isOverridden($date),
            ],
        ]);
    }

    /** Create or update a whole worksite-day in one call. */
    public function storeDay(StoreAttendanceDayRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $worksiteId = (int) $data['worksite_id'];
        $date = $request->date('date')->toDateString();

        $this->masterAccess->assertCanActOn($user, $worksiteId);

        $masterId = $this->masterAccess->masterFor($user)?->id;
        $mayEditApproved = $user->can('attendance.approve');

        $locked = [];
        $saved = [];

        DB::transaction(function () use ($data, $date, $worksiteId, $masterId, $mayEditApproved, &$locked, &$saved): void {
            foreach ($data['records'] as $row) {
                $record = AttendanceRecord::query()
                    ->where('employee_id', $row['employee_id'])
                    ->whereDate('date', $date)
                    ->first();

                if ($record !== null && $record->isApproved() && ! $mayEditApproved) {
                    $locked[] = (int) $row['employee_id'];

                    continue;
                }

                $attributes = [
                    'date' => $date,
                    'employee_id' => $row['employee_id'],
                    'worksite_id' => $worksiteId,
                    'status' => $row['status'],
                    'regular_hours' => $row['regular_hours'] ?? null,
                    'overtime_hours' => $row['overtime_hours'] ?? 0,
                    'overtime_reason' => $row['overtime_reason'] ?? null,
                    'note' => $row['note'] ?? null,
                ];

                if ($record === null) {
                    $record = new AttendanceRecord($attributes);
                    $record->master_id = $masterId;
                } else {
                    $record->fill($attributes);
                    $record->master_id ??= $masterId;
                }

                $record->save();
                $this->earnedPay->apply($record);

                $saved[] = $record->load(['employee', 'worksite']);
            }
        });

        activity()->causedBy($user)
            ->withProperties([
                'date' => $date,
                'worksite_id' => $worksiteId,
                'saved' => count($saved),
                'locked' => $locked,
            ])
            ->log('attendance.day_saved');

        return response()->json([
            'data' => AttendanceRecordResource::collection(collect($saved)),
            'meta' => ['locked_employee_ids' => $locked],
        ], 201);
    }

    public function update(UpdateAttendanceRecordRequest $request, AttendanceRecord $attendance): JsonResponse
    {
        $user = $request->user();
        $this->masterAccess->assertCanActOn($user, $attendance->worksite_id);

        if ($attendance->isApproved() && ! $user->can('attendance.approve')) {
            return response()->json([
                'message' => 'This day is approved and locked; ask the office to reopen it.',
            ], 422);
        }

        $attendance->update($request->validated());
        $this->earnedPay->apply($attendance);

        activity()->performedOn($attendance)->causedBy($user)->log('attendance.updated');

        return response()->json([
            'data' => new AttendanceRecordResource($attendance->fresh()->load(['employee', 'worksite'])),
        ]);
    }

    public function destroy(Request $request, AttendanceRecord $attendance): JsonResponse
    {
        $user = $request->user();
        $this->masterAccess->assertCanActOn($user, $attendance->worksite_id);

        if ($attendance->isApproved() && ! $user->can('attendance.approve')) {
            return response()->json(['message' => 'This day is approved and locked.'], 422);
        }

        $attendance->delete();

        activity()->performedOn($attendance)->causedBy($user)->log('attendance.deleted');

        return response()->json(['message' => 'Attendance record deleted.']);
    }

    /** Hand the day to the office for review. */
    public function submit(ReviewAttendanceRequest $request): JsonResponse
    {
        $user = $request->user();
        $records = $this->targets($request)
            ->filter(fn (AttendanceRecord $record): bool => in_array($record->approval_status, ['draft', 'rejected'], true));

        foreach ($records as $record) {
            $record->forceFill([
                'approval_status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'rejection_reason' => null,
            ])->save();
        }

        activity()->causedBy($user)->withProperties($this->reviewContext($request, $records->count()))
            ->log('attendance.submitted');

        return response()->json(['message' => 'Day submitted for review.', 'meta' => ['count' => $records->count()]]);
    }

    /** Approval is what makes a day usable for salary calculations. */
    public function approve(ReviewAttendanceRequest $request): JsonResponse
    {
        $user = $request->user();
        $records = $this->targets($request);

        foreach ($records as $record) {
            $record->forceFill([
                'approval_status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $user->id,
                'approved_for_payroll' => true,
                'rejection_reason' => null,
            ])->save();

            // Re-run the calculation so the approved figures match current settings.
            $this->earnedPay->apply($record);
        }

        activity()->causedBy($user)->withProperties($this->reviewContext($request, $records->count()))
            ->log('attendance.approved');

        return response()->json(['message' => 'Attendance approved.', 'meta' => ['count' => $records->count()]]);
    }

    public function reject(RejectAttendanceRequest $request): JsonResponse
    {
        $user = $request->user();
        $records = $this->targets($request);
        $reason = $request->input('reason');

        foreach ($records as $record) {
            $record->forceFill([
                'approval_status' => 'rejected',
                'approved_at' => null,
                'approved_by' => null,
                'approved_for_payroll' => false,
                'rejection_reason' => $reason,
            ])->save();
        }

        activity()->causedBy($user)
            ->withProperties($this->reviewContext($request, $records->count()) + ['reason' => $reason])
            ->log('attendance.rejected');

        return response()->json(['message' => 'Attendance rejected.', 'meta' => ['count' => $records->count()]]);
    }

    /** Authorized manual correction of a computed amount; the reason is stored. */
    public function adjust(AdjustAttendanceRequest $request, AttendanceRecord $attendance): JsonResponse
    {
        $attendance->forceFill([
            'adjustment_amount' => $request->input('adjustment_amount'),
            'adjustment_reason' => $request->input('adjustment_reason'),
        ])->save();

        $this->earnedPay->apply($attendance);

        activity()->performedOn($attendance)->causedBy($request->user())
            ->withProperties($request->validated())
            ->log('attendance.adjusted');

        return response()->json([
            'data' => new AttendanceRecordResource($attendance->fresh()->load(['employee', 'worksite'])),
        ]);
    }

    /** Per-worker totals for a month: days, overtime hours and earned pay. */
    public function summary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'string', MonthPeriod::rule()],
            'worksite_id' => ['nullable', 'integer', 'exists:gradilista,id'],
            'approved_only' => ['sometimes', 'boolean'],
        ]);

        $month = SalaryPayment::normalizeMonth($validated['month']);

        $query = AttendanceRecord::query()->with('employee')->forMonth($month);

        if ($request->filled('worksite_id')) {
            $query->where('worksite_id', $request->integer('worksite_id'));
        }
        if ($request->boolean('approved_only')) {
            $query->approved();
        }

        $this->scopeToAccessibleWorksites($query, $request);

        $rows = $query->get()
            ->groupBy('employee_id')
            ->map(function (EloquentCollection $records): array {
                /** @var AttendanceRecord $first */
                $first = $records->first();

                return [
                    'employee_id' => $first->employee_id,
                    'full_name' => $first->employee?->full_name,
                    'currency' => $first->currency,
                    'days_present' => $records->where('status', 'present')->count(),
                    'days_absent' => $records->where('status', 'absent')->count(),
                    'days_holiday' => $records->where('status', 'holiday')->count(),
                    'days_sick_leave' => $records->where('status', 'sick_leave')->count(),
                    'days_unpaid_leave' => $records->where('status', 'unpaid_leave')->count(),
                    'overtime_hours' => round((float) $records->sum('overtime_hours'), 2),
                    'regular_amount' => round((float) $records->sum('regular_amount'), 2),
                    'overtime_amount' => round((float) $records->sum('overtime_amount'), 2),
                    'adjustment_amount' => round((float) $records->sum('adjustment_amount'), 2),
                    'total_earned' => round((float) $records->sum('total_amount'), 2),
                    'approved_days' => $records->where('approval_status', 'approved')->count(),
                    'pending_days' => $records->whereIn('approval_status', ['draft', 'submitted'])->count(),
                ];
            })
            ->sortBy('full_name')
            ->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month' => $month,
                'working_days_basis' => $this->workingDays->forMonth($month),
                'working_days_overridden' => $this->workingDays->isOverridden($month),
                'total_earned' => round((float) $rows->sum('total_earned'), 2),
            ],
        ]);
    }

    /**
     * Monthly payroll preparation: approved earned pay next to the salary
     * obligation, so the office can see where the two disagree before paying.
     */
    public function payrollPreparation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'month' => ['required', 'string', MonthPeriod::rule()],
        ]);

        $month = SalaryPayment::normalizeMonth($validated['month']);

        $earned = AttendanceRecord::query()
            ->approved()
            ->forMonth($month)
            ->get()
            ->groupBy('employee_id');

        $obligations = SalaryPayment::query()->forMonth($month)->with('employee')->get()->keyBy('employee_id');

        $employeeIds = $earned->keys()->merge($obligations->keys())->unique();
        $employees = Employee::query()->whereIn('id', $employeeIds)->get()->keyBy('id');

        $rows = $employeeIds->map(function ($employeeId) use ($earned, $obligations, $employees): array {
            $records = $earned->get($employeeId);
            $obligation = $obligations->get($employeeId);
            $earnedTotal = round((float) ($records?->sum('total_amount') ?? 0), 2);
            $netDue = $obligation === null ? null : (float) $obligation->net_salary_due;

            return [
                'employee_id' => $employeeId,
                'full_name' => $employees->get($employeeId)?->full_name,
                'approved_days' => $records?->count() ?? 0,
                'earned_from_attendance' => $earnedTotal,
                'salary_payment_id' => $obligation?->id,
                'net_salary_due' => $netDue,
                'paid_amount' => $obligation === null ? null : (float) $obligation->paid_amount,
                'remaining_amount' => $obligation === null ? null : (float) $obligation->remaining_amount,
                'difference' => $netDue === null ? null : round($earnedTotal - $netDue, 2),
                'status' => $obligation?->status,
            ];
        })->sortBy('full_name')->values();

        return response()->json([
            'data' => $rows,
            'meta' => [
                'month' => $month,
                'working_days_basis' => $this->workingDays->forMonth($month),
                'earned_total' => round((float) $rows->sum('earned_from_attendance'), 2),
                'obligation_total' => round((float) $obligations->sum('net_salary_due'), 2),
            ],
        ]);
    }

    /** Resolve the records a review action applies to. */
    private function targets(ReviewAttendanceRequest $request): EloquentCollection
    {
        $user = $request->user();

        if ($request->filled('ids')) {
            $records = AttendanceRecord::query()->whereIn('id', $request->input('ids'))->get();

            foreach ($records->pluck('worksite_id')->unique() as $worksiteId) {
                $this->masterAccess->assertCanActOn($user, (int) $worksiteId);
            }

            return $records;
        }

        $worksiteId = (int) $request->input('worksite_id');
        $this->masterAccess->assertCanActOn($user, $worksiteId);

        return AttendanceRecord::query()
            ->forDay($request->date('date')->toDateString())
            ->where('worksite_id', $worksiteId)
            ->get();
    }

    /** @return array<string, mixed> */
    private function reviewContext(ReviewAttendanceRequest $request, int $count): array
    {
        return [
            'date' => $request->input('date'),
            'worksite_id' => $request->input('worksite_id'),
            'ids' => $request->input('ids'),
            'count' => $count,
        ];
    }

    /** Masters only see their own worksites (see MasterAccessService). */
    private function scopeToAccessibleWorksites($query, Request $request): void
    {
        $allowed = $this->masterAccess->accessibleWorksiteIds($request->user());

        if ($allowed !== null) {
            $query->whereIn('worksite_id', $allowed);
        }
    }
}
