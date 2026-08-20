<?php

namespace App\Services;

use App\Models\House;
use App\Models\RentPayment;
use App\Support\MonthPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds a month's rent obligations from the active houses' monthly rent.
 * Existing rows are left untouched, and houses without a rent amount are
 * reported as skipped rather than guessed at.
 */
class RentObligationService
{
    /**
     * @return array{month: string, created: Collection<int, RentPayment>, skipped: list<array{house_id: int, name: string, reason: string}>, existing: list<int>}
     */
    public function generate(string $month, bool $preview = false): array
    {
        $month = MonthPeriod::normalize($month);

        $houses = House::query()->active()->orderBy('name')->get();

        $existingHouseIds = RentPayment::query()->forMonth($month)->pluck('house_id')->all();

        $skipped = [];
        $toCreate = [];

        foreach ($houses as $house) {
            if (in_array($house->id, $existingHouseIds, true)) {
                continue;
            }

            if ($house->monthly_rent === null || (float) $house->monthly_rent <= 0) {
                $skipped[] = [
                    'house_id' => $house->id,
                    'name' => $house->name,
                    'reason' => 'missing_monthly_rent',
                ];

                continue;
            }

            $toCreate[] = [
                'house_id' => $house->id,
                'month' => $month,
                'currency' => $house->currency,
                'rent_amount_due' => (float) $house->monthly_rent,
                'source' => 'generated',
            ];
        }

        if ($preview) {
            $created = collect($toCreate)->map(function (array $row) use ($houses): RentPayment {
                $rent = new RentPayment($row);
                $rent->setRelation('house', $houses->firstWhere('id', $row['house_id']));
                // Priced without being saved, so the preview shows the same EUR
                // figures the rows would carry once they are created.
                $rent->syncEurAmount();
                $rent->forceFill([
                    'paid_amount' => 0,
                    'remaining_amount' => (float) $rent->amount_eur,
                    'status' => 'unpaid',
                ]);

                return $rent;
            });

            return ['month' => $month, 'created' => $created, 'skipped' => $skipped, 'existing' => $existingHouseIds];
        }

        $created = DB::transaction(fn (): Collection => collect($toCreate)->map(function (array $row): RentPayment {
            $rent = RentPayment::create($row);
            $rent->recalculate();

            return $rent->load('house');
        }));

        return ['month' => $month, 'created' => $created, 'skipped' => $skipped, 'existing' => $existingHouseIds];
    }
}
