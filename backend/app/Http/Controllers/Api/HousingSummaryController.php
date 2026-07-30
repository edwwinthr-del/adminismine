<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\House;
use App\Models\HousingDeduction;
use App\Models\RentPayment;
use App\Models\UtilityBill;
use App\Support\MonthPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Total monthly housing cost, unpaid rent and bills per house, who lives where,
 * and what is overdue — the numbers the office needs in one call.
 */
class HousingSummaryController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ]);

        $month = MonthPeriod::normalize($request->input('month', now()->toDateString()));
        [$start, $end] = MonthPeriod::range($month);

        $houses = House::query()->with(['currentOccupancies.employee'])->get();
        $rents = RentPayment::query()->with('house')->forMonth($month)->get();
        $bills = UtilityBill::query()->with('house')->forMonth($month)->get();
        $deductions = HousingDeduction::query()->forMonth($month)->get();

        $perHouse = $houses->map(function (House $house) use ($rents, $bills, $start, $end): array {
            $houseRents = $rents->where('house_id', $house->id);
            $houseBills = $bills->where('house_id', $house->id);

            return [
                'house_id' => $house->id,
                'name' => $house->name,
                'is_active' => $house->is_active,
                'currency' => $house->currency,
                'occupants' => $house->currentOccupancies->map(fn ($occupancy) => [
                    'employee_id' => $occupancy->employee_id,
                    'full_name' => $occupancy->employee?->full_name,
                    'room' => $occupancy->room,
                ])->values(),
                'occupant_count' => $house->currentOccupancies->count(),
                'rent_due' => round((float) $houseRents->sum('rent_amount_due'), 2),
                'rent_paid' => round((float) $houseRents->sum('paid_amount'), 2),
                'rent_unpaid' => round((float) $houseRents->sum('remaining_amount'), 2),
                'rent_overdue' => $houseRents->filter(fn (RentPayment $rent): bool => $rent->is_overdue)->count(),
                'bills_total' => round((float) $houseBills->sum('amount'), 2),
                'bills_unpaid' => round((float) $houseBills->sum('remaining_amount'), 2),
                'bills_overdue' => $houseBills->filter(fn (UtilityBill $bill): bool => $bill->is_overdue)->count(),
                'monthly_cost' => round(
                    (float) $houseRents->sum('rent_amount_due') + (float) $houseBills->sum('amount'),
                    2,
                ),
                // Present for context: a worker may have moved during the month.
                'occupancy_changes' => $house->occupancies()
                    ->overlappingMonth($start, $end)
                    ->where(function ($query) use ($start, $end) {
                        $query->whereBetween('moved_in_at', [$start, $end])
                            ->orWhereBetween('moved_out_at', [$start, $end]);
                    })
                    ->count(),
            ];
        })->values();

        return response()->json([
            'data' => [
                'month' => $month,
                'houses' => $perHouse,
                'totals' => [
                    'rent_due' => round((float) $rents->sum('rent_amount_due'), 2),
                    'rent_paid' => round((float) $rents->sum('paid_amount'), 2),
                    'rent_unpaid' => round((float) $rents->sum('remaining_amount'), 2),
                    'bills_total' => round((float) $bills->sum('amount'), 2),
                    'bills_unpaid' => round((float) $bills->sum('remaining_amount'), 2),
                    // What housing costs the company this month, rent + bills.
                    'monthly_cost' => round((float) $rents->sum('rent_amount_due') + (float) $bills->sum('amount'), 2),
                    'charged_to_workers' => round((float) $deductions->sum(
                        fn (HousingDeduction $deduction): float => (float) $deduction->rent_share + (float) $deduction->utility_share
                    ), 2),
                    'occupants' => $houses->sum(fn (House $house): int => $house->currentOccupancies->count()),
                    'active_houses' => $houses->where('is_active', true)->count(),
                ],
                'alerts' => [
                    'overdue_rent' => $rents->filter(fn (RentPayment $rent): bool => $rent->is_overdue)
                        ->map(fn (RentPayment $rent): array => [
                            'rent_payment_id' => $rent->id,
                            'house' => $rent->house?->name,
                            'due_date' => $rent->due_date->toDateString(),
                            'remaining' => (float) $rent->remaining_amount,
                        ])->values(),
                    'overdue_bills' => $bills->filter(fn (UtilityBill $bill): bool => $bill->is_overdue)
                        ->map(fn (UtilityBill $bill): array => [
                            'utility_bill_id' => $bill->id,
                            'house' => $bill->house?->name,
                            'bill_type' => $bill->bill_type,
                            'due_date' => optional($bill->due_date)->toDateString(),
                            'remaining' => (float) $bill->remaining_amount,
                        ])->values(),
                ],
            ],
        ]);
    }
}
