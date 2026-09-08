<?php

namespace App\Support\Industry;

use App\Support\Modules;

/**
 * A firm that supplies people rather than output: industrial cleaning, facility
 * services, warehouse and logistics labour.
 *
 * The narrowest of the three, and the clearest illustration of what the module
 * toggles are for. What it keeps is the part that makes this app unlike a
 * generic ERP: crews at a client's premises, housing, travel, advances against
 * wages, attendance-driven pay. What it drops is everything about *things*:
 *
 * - **No production.** Nothing is measured and approved at a site; the service
 *   is the hours.
 * - **No machines and no customs.** No fleet, no border.
 * - **No deposit.** Work is organised contract → site.
 *
 * ⚠️ **Written without a customer.** Reasoned defaults, not observed ones; the
 * first real firm of this shape should be expected to correct them.
 */
class LabourServicesProfile implements IndustryProfile
{
    public function key(): string
    {
        return 'labour_services';
    }

    public function modules(): array
    {
        return array_values(array_diff(Modules::keys(), ['production', 'machines', 'customs']));
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
            'nav.projects' => ['en' => 'Contracts', 'sr' => 'Ugovori', 'tr' => 'Sözleşmeler'],
            'projects.title' => ['en' => 'Contracts', 'sr' => 'Ugovori', 'tr' => 'Sözleşmeler'],
            'projects.search' => ['en' => 'Search contracts…', 'sr' => 'Pretraga ugovora…', 'tr' => 'Sözleşme ara…'],
            'projects.none' => ['en' => 'No contracts yet.', 'sr' => 'Još nema ugovora.', 'tr' => 'Henüz sözleşme yok.'],
            'nav.worksites' => ['en' => 'Sites', 'sr' => 'Lokacije', 'tr' => 'Lokasyonlar'],
            'worksites.title' => ['en' => 'Sites', 'sr' => 'Lokacije', 'tr' => 'Lokasyonlar'],
            'worksites.search' => ['en' => 'Search sites…', 'sr' => 'Pretraga lokacija…', 'tr' => 'Lokasyon ara…'],
            'worksites.none' => ['en' => 'No sites yet.', 'sr' => 'Još nema lokacija.', 'tr' => 'Henüz lokasyon yok.'],
            'nav.masters' => ['en' => 'Supervisors', 'sr' => 'Nadzornici', 'tr' => 'Süpervizörler'],
            'masters.title' => ['en' => 'Supervisors', 'sr' => 'Nadzornici', 'tr' => 'Süpervizörler'],
            'reportColumn.worksite' => ['en' => 'Site', 'sr' => 'Lokacija', 'tr' => 'Lokasyon'],
        ];
    }

    public function vocabularies(): array
    {
        // Production is off, so the material and unit lists are moot. What this
        // shape of company does differ on is what its crews ask the office for.
        return [
            'worker_need_type' => [
                'equipment', 'uniform', 'document', 'salary_advance', 'travel', 'housing', 'medical', 'other',
            ],
        ];
    }
}
