<?php

namespace App\Support\Industry;

use App\Support\Modules;

/**
 * The company this app was built for: a mining subcontractor working someone
 * else's deposits.
 *
 * **This profile is the app's own defaults, restated.** It overrides nothing,
 * renames nothing and replaces no list — applying it to a fresh install produces
 * exactly the behaviour the app has always had. That is deliberate and it is
 * load-bearing: it is what `ProfileMatrixTest` compares the other profiles
 * against, and what makes "did a profile change something it should not have"
 * a question with an answer.
 */
class MiningProfile implements IndustryProfile
{
    public function key(): string
    {
        return 'mining';
    }

    public function modules(): array
    {
        // Everything. A mining subcontractor houses its workers, flies them in,
        // lends against wages, runs machines through customs and logs what comes
        // out of the ground.
        return Modules::keys();
    }

    public function workStructureLevels(): array
    {
        return ['mine', 'project', 'worksite'];
    }

    public function rules(): array
    {
        // The workbook's own answers, which are the app's defaults.
        return [
            'standard_day_hours' => 8,
            'overtime_multiplier' => 1.5,
            'working_day_rule' => 'every_non_sunday',
            'social_assistance_annual' => 1000,
        ];
    }

    public function terminology(): array
    {
        // Nothing: the built-in wording *is* this company's wording.
        return [];
    }

    public function vocabularies(): array
    {
        // Likewise — the shipped lists are this industry's lists.
        return [];
    }
}
