<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\BankTransaction;
use App\Models\CompanySettings;
use App\Models\Employee;
use App\Models\PayableInvoice;
use App\Models\ProductionRecord;
use App\Models\ReceivableInvoice;
use App\Models\TerminologyOverride;
use App\Models\VocabularyValue;
use App\Models\Worksite;
use App\Support\CompanyConfig;
use App\Support\ConfigSource;
use App\Support\Industry\IndustryProfile;
use App\Support\RowCounts;
use App\Support\Vocabulary;
use Illuminate\Support\Facades\DB;

/**
 * Set an install up as one shape of company.
 *
 * Everything this writes is an ordinary settings row that stays separately
 * editable afterwards — a profile chooses a starting point, it does not put the
 * app into a mode. Nothing here is a switch the rest of the code reads.
 *
 * **It refuses to run on an install that already holds business records**,
 * unless the caller is explicit. Re-applying a profile rewrites terminology and
 * replaces vocabularies, and doing that under a year of production rows would
 * deactivate the values those rows hold and rename the screens around them —
 * recoverable, but not something anyone means to do by clicking a button on a
 * live system.
 */
class ApplyProfile
{
    public function __construct(private readonly DailyEarnedPayService $earnedPay) {}

    /**
     * What "this install is in use" means. Not an exhaustive list of tables —
     * one row in any of these is enough to say somebody has started working.
     *
     * @var list<array{0: class-string, 1: string}>
     */
    private const BUSINESS_RECORDS = [
        [PayableInvoice::class, 'payables'],
        [ReceivableInvoice::class, 'receivables'],
        [BankTransaction::class, 'bank movements'],
        [Employee::class, 'workers'],
        [Worksite::class, 'worksites'],
        [ProductionRecord::class, 'production records'],
        [AttendanceRecord::class, 'attendance days'],
    ];

    /**
     * @return array<string, int> what already exists, by name — empty on a fresh
     *                            install, which is what makes it safe to apply
     */
    public function existingRecords(): array
    {
        $byLabel = [];

        foreach (self::BUSINESS_RECORDS as [$model, $label]) {
            $byLabel[$label] = [$model];
        }

        // One query rather than seven: this is read on every dashboard load,
        // because the setup prompt keys on it.
        return array_filter(RowCounts::forModels($byLabel));
    }

    /**
     * Apply the profile.
     *
     * Idempotent: applying the same profile twice leaves the same state, because
     * every write is a set rather than an increment.
     *
     * @return array<string, mixed> what it did, for the audit entry
     */
    public function apply(IndustryProfile $profile): array
    {
        return DB::transaction(function () use ($profile): array {
            $settings = CompanySettings::current();

            $settings->fill([
                'profile' => $profile->key(),
                'enabled_modules' => $profile->modules(),
                'work_structure_levels' => $profile->workStructureLevels(),
                ...$profile->rules(),
            ])->save();

            /*
             * A profile that moves the working week or the standard day has
             * changed what every day of work already entered is worth, and those
             * figures are cached on the attendance records. Editing the same
             * rule in Settings recomputes them; applying a profile has to do the
             * same, or the two ways of making one change would disagree — a
             * five-day week applied over a month of days would leave every one
             * of them priced on a six-day divisor.
             *
             * Saving cleared the memoised config, so this reads the values just
             * written rather than the ones they replaced.
             */
            $payrollChanged = array_intersect(
                array_keys($settings->getChanges()),
                CompanyConfig::payrollRuleColumns(),
            ) !== [];

            return [
                'profile' => $profile->key(),
                'modules' => count($profile->modules()),
                'levels' => $profile->workStructureLevels(),
                'terminology' => $this->applyTerminology($profile),
                'vocabularies' => $this->applyVocabularies($profile),
                'attendance_recomputed' => $payrollChanged ? $this->earnedPay->recomputeAll() : 0,
            ];
        });
    }

    /**
     * Replace the terminology with this profile's.
     *
     * The previous profile's words are cleared first, so applying one profile
     * after another gives that profile's wording rather than a merge of two
     * companies' vocabularies.
     *
     * **Only the previous profile's.** This cleared the whole table once, which
     * meant a company that had spent an afternoon renaming terms to match how it
     * actually talks lost all of it the next time anybody applied a profile —
     * silently, with no undo, on an operation documented as safe to re-run.
     * `source` is what tells the two apart: a row this service wrote is
     * `profile` and is the app's to replace; a row saved through the terminology
     * editor is `manual` and is the customer's words, which nothing here may
     * touch. Editing a profile-supplied term in that editor re-stamps it
     * `manual`, so correcting one word is enough to keep it.
     */
    private function applyTerminology(IndustryProfile $profile): int
    {
        TerminologyOverride::query()->where('source', ConfigSource::PROFILE)->delete();

        $written = 0;

        foreach ($profile->terminology() as $key => $byLocale) {
            foreach ($byLocale as $locale => $value) {
                /*
                 * The profile's own words were cleared above, so any row still
                 * standing here is one the company wrote — and its word wins.
                 * Creating over the top would collide on `(key, locale)`
                 * anyway; skipping says why, and says it in the count.
                 */
                $row = TerminologyOverride::query()->firstOrCreate(
                    ['key' => $key, 'locale' => $locale],
                    ['value' => $value, 'source' => ConfigSource::PROFILE],
                );

                if ($row->wasRecentlyCreated) {
                    $written++;
                }
            }
        }

        return $written;
    }

    /**
     * Replace the vocabularies this profile has an opinion about.
     *
     * A shipped value the profile does not list is **deactivated, not deleted**:
     * model defaults name some of them and the workbook importer's label
     * normaliser maps onto others, so the rows have to stay. A vocabulary the
     * profile says nothing about is left exactly as it is.
     */
    private function applyVocabularies(IndustryProfile $profile): int
    {
        $touched = 0;

        foreach ($profile->vocabularies() as $vocabulary => $values) {
            if (! Vocabulary::exists($vocabulary)) {
                continue;
            }

            $shipped = Vocabulary::defaults($vocabulary);

            foreach (array_values($values) as $order => $value) {
                VocabularyValue::query()->updateOrCreate(
                    ['vocabulary' => $vocabulary, 'value' => $value],
                    [
                        'is_active' => true,
                        'sort_order' => $order,
                        'is_system' => in_array($value, $shipped, true),
                        'source' => ConfigSource::PROFILE,
                    ],
                );
            }

            // Everything this profile did not ask for stops being offered —
            // except a value the company added for itself. Retiring the app's
            // own `bauxite_ore` because a construction profile does not list it
            // is the point; retiring the `crushed_stone` somebody typed last
            // week is losing their work, and the two were indistinguishable
            // until `source` said which was which.
            VocabularyValue::query()
                ->where('vocabulary', $vocabulary)
                ->whereNotIn('value', $values)
                ->where(function ($query): void {
                    // A row predating the stamp is read as theirs rather than
                    // risk retiring something they meant to keep.
                    $query->whereNotNull('source')->where('source', '!=', ConfigSource::CUSTOMER);
                })
                ->update(['is_active' => false]);

            $touched++;
        }

        return $touched;
    }
}
