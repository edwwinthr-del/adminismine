<?php

namespace App\Support\Industry;

use App\Support\Modules;

/**
 * A construction or civil-works subcontractor.
 *
 * The same shape as the mining company — crews at somebody else's sites, a
 * migrant workforce it houses and transports, wages by attendance, a client
 * billed per project — with three differences that are structural rather than
 * cosmetic:
 *
 * - **No deposit.** Work is organised project → site; there is nothing above the
 *   project, so the top level of the hierarchy is not asked for.
 * - **No customs.** Machines are not crossing a border under a CMR.
 * - **A five-day week**, which changes the divisor behind every daily rate.
 *
 * Production stays *on*. "Output measured at a site on a date, approved by
 * someone" is exactly how progress quantities are recorded and billed — the
 * table was never about ore, only its defaults were.
 *
 * ⚠️ **Written without a customer.** These are reasoned defaults, not observed
 * ones. The first real construction firm to use this should be expected to
 * correct it, and correcting it is one edit here rather than a code change
 * anywhere else.
 */
class ConstructionProfile implements IndustryProfile
{
    public function key(): string
    {
        return 'construction';
    }

    public function modules(): array
    {
        return array_values(array_diff(Modules::keys(), ['customs']));
    }

    public function workStructureLevels(): array
    {
        return ['project', 'worksite'];
    }

    public function rules(): array
    {
        return [
            'standard_day_hours' => 8,
            'overtime_multiplier' => 1.5,
            'working_day_rule' => 'mon_fri',
            'social_assistance_annual' => 1000,
        ];
    }

    public function terminology(): array
    {
        return [
            'nav.mining' => ['en' => 'Output log', 'sr' => 'Evidencija radova', 'tr' => 'İş kaydı'],
            'mining.title' => ['en' => 'Output log', 'sr' => 'Evidencija radova', 'tr' => 'İş kaydı'],
            'nav.worksites' => ['en' => 'Sites', 'sr' => 'Gradilišta', 'tr' => 'Şantiyeler'],
            'worksites.title' => ['en' => 'Sites', 'sr' => 'Gradilišta', 'tr' => 'Şantiyeler'],
            'worksites.search' => ['en' => 'Search sites…', 'sr' => 'Pretraga gradilišta…', 'tr' => 'Şantiye ara…'],
            'worksites.none' => ['en' => 'No sites yet.', 'sr' => 'Još nema gradilišta.', 'tr' => 'Henüz şantiye yok.'],
            'nav.masters' => ['en' => 'Foremen', 'sr' => 'Poslovođe', 'tr' => 'Ustabaşılar'],
            'masters.title' => ['en' => 'Foremen', 'sr' => 'Poslovođe', 'tr' => 'Ustabaşılar'],
            'reportColumn.worksite' => ['en' => 'Site', 'sr' => 'Gradilište', 'tr' => 'Şantiye'],
        ];
    }

    public function vocabularies(): array
    {
        return [
            'material_type' => ['concrete', 'asphalt', 'aggregate', 'steel', 'earthworks', 'other'],
            'production_unit' => ['m3', 'tons', 'm2', 'units'],
        ];
    }
}
