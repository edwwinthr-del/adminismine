<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\OverrideWorkingDaysRequest;
use App\Models\SalaryPayment;
use App\Models\WorkingDaySetting;
use App\Services\DailyEarnedPayService;
use App\Services\WorkingDaysService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The working-day divisor behind daily earned pay: derived for a 6-day week and
 * overridable per month by an authorized user, with a reason.
 */
class WorkingDayController extends Controller
{
    public function __construct(
        private readonly WorkingDaysService $workingDays,
        private readonly DailyEarnedPayService $earnedPay,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $month = SalaryPayment::normalizeMonth($request->input('month', now()->toDateString()));
        $override = $this->workingDays->override($month);

        return response()->json([
            'data' => [
                'month' => $month,
                'working_days' => $this->workingDays->forMonth($month),
                'derived_working_days' => $this->workingDays->derivedForMonth($month),
                'is_overridden' => $override !== null,
                'reason' => $override?->reason,
            ],
        ]);
    }

    public function update(OverrideWorkingDaysRequest $request): JsonResponse
    {
        $data = $request->validated();

        $setting = WorkingDaySetting::updateOrCreate(
            ['month' => $data['month']],
            ['working_days' => $data['working_days'], 'reason' => $data['reason']],
        );

        $this->workingDays->forget();
        // The divisor just moved, so every earned figure already cached for this
        // month is stale until it is rebuilt.
        $recomputed = $this->earnedPay->recomputeMonth($data['month']);

        activity()->performedOn($setting)->causedBy($request->user())
            ->withProperties($data + ['recomputed_records' => $recomputed])
            ->log('working_days.overridden');

        return response()->json([
            'data' => [
                'month' => $setting->month->toDateString(),
                'working_days' => $setting->working_days,
                'derived_working_days' => $this->workingDays->derivedForMonth($data['month']),
                'is_overridden' => true,
                'reason' => $setting->reason,
                'recomputed_records' => $recomputed,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $month = SalaryPayment::normalizeMonth($request->input('month', now()->toDateString()));
        $override = $this->workingDays->override($month);

        $recomputed = 0;

        if ($override !== null) {
            $override->delete();
            $this->workingDays->forget();
            // Removing the override moves the divisor back to the derived value,
            // which is the same staleness in the other direction.
            $recomputed = $this->earnedPay->recomputeMonth($month);

            activity()->causedBy($request->user())
                ->withProperties(['month' => $month, 'recomputed_records' => $recomputed])
                ->log('working_days.override_removed');
        }

        return response()->json([
            'data' => [
                'month' => $month,
                'working_days' => $this->workingDays->derivedForMonth($month),
                'derived_working_days' => $this->workingDays->derivedForMonth($month),
                'is_overridden' => false,
                'reason' => null,
                'recomputed_records' => $recomputed,
            ],
        ]);
    }
}
