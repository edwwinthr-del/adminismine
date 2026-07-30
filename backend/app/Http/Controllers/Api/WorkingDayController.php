<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\OverrideWorkingDaysRequest;
use App\Models\SalaryPayment;
use App\Models\WorkingDaySetting;
use App\Services\WorkingDaysService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The working-day divisor behind daily earned pay: derived for a 6-day week and
 * overridable per month by an authorized user, with a reason.
 */
class WorkingDayController extends Controller
{
    public function __construct(private readonly WorkingDaysService $workingDays) {}

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

        activity()->performedOn($setting)->causedBy($request->user())
            ->withProperties($data)
            ->log('working_days.overridden');

        return response()->json([
            'data' => [
                'month' => $setting->month->toDateString(),
                'working_days' => $setting->working_days,
                'derived_working_days' => $this->workingDays->derivedForMonth($data['month']),
                'is_overridden' => true,
                'reason' => $setting->reason,
            ],
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $month = SalaryPayment::normalizeMonth($request->input('month', now()->toDateString()));
        $override = $this->workingDays->override($month);

        if ($override !== null) {
            $override->delete();
            $this->workingDays->forget();

            activity()->causedBy($request->user())->withProperties(['month' => $month])
                ->log('working_days.override_removed');
        }

        return response()->json([
            'data' => [
                'month' => $month,
                'working_days' => $this->workingDays->derivedForMonth($month),
                'derived_working_days' => $this->workingDays->derivedForMonth($month),
                'is_overridden' => false,
                'reason' => null,
            ],
        ]);
    }
}
