<?php

namespace App\Support;

use App\Models\CustomsDocument;
use App\Models\FileAttachment;
use App\Models\Mine;
use App\Models\ProductionRecord;
use App\Models\TravelExpense;
use App\Models\UtilityBill;
use App\Models\VocabularyValue;
use App\Models\WorkerNeed;

/**
 * The open-ended lists a company fills in for itself.
 *
 * **The line, and it is the whole design:** a value the code branches on is an
 * enum and stays in PHP; a value only humans read is a vocabulary.
 *
 * `present` decides whether a day earns; `approved` decides whether it reaches
 * payroll; `income` and `expense` decide the sign of a movement; `maintenance`
 * decides whether a machine counts as in service (`Machine::scopeActive`). Those
 * are not vocabulary — they are the program, and letting an admin edit them
 * would let an admin edit what the app *does*.
 *
 * `bauxite_ore` decides nothing. Nothing in the codebase asks whether a
 * production row is bauxite; the value is a default and a label, which is
 * exactly what a construction firm needs to replace with `concrete` and a
 * shipyard with `steel_plate`.
 *
 * Values stay canonical snake_case whatever they are called on screen (rule 4);
 * the wording comes from the terminology layer under `vocab.{vocabulary}.{value}`.
 */
final class Vocabulary
{
    /**
     * Every vocabulary: the values it ships with, and where those values are
     * stored so a value in use cannot be deleted out from under its rows.
     *
     * `defaults` are seeded as `is_system` — deactivatable, never deletable,
     * because the workbook importer's label normaliser maps onto some of them
     * (`ARABA` → `car`) and a model default names others.
     *
     * @var array<string, array{defaults: list<string>, usedBy: list<array{0: class-string, 1: string}>}>
     */
    public const CATALOGUE = [
        'material_type' => [
            'defaults' => ProductionRecord::MATERIAL_TYPES,
            'usedBy' => [[ProductionRecord::class, 'material_type'], [Mine::class, 'material_type']],
        ],
        'production_unit' => [
            'defaults' => ProductionRecord::UNITS,
            'usedBy' => [[ProductionRecord::class, 'unit']],
        ],
        'worker_need_type' => [
            'defaults' => WorkerNeed::TYPES,
            'usedBy' => [[WorkerNeed::class, 'need_type']],
        ],
        'utility_bill_type' => [
            'defaults' => UtilityBill::TYPES,
            'usedBy' => [[UtilityBill::class, 'bill_type']],
        ],
        'travel_expense_type' => [
            'defaults' => TravelExpense::TYPES,
            'usedBy' => [[TravelExpense::class, 'expense_type']],
        ],
        'attachment_kind' => [
            'defaults' => FileAttachment::KINDS,
            'usedBy' => [[FileAttachment::class, 'kind']],
        ],
        'customs_document_type' => [
            'defaults' => CustomsDocument::TYPES,
            'usedBy' => [[CustomsDocument::class, 'document_type']],
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::CATALOGUE);
    }

    public static function exists(string $vocabulary): bool
    {
        return array_key_exists($vocabulary, self::CATALOGUE);
    }

    /**
     * The values a form may offer and validation may accept.
     *
     * Falls back to the shipped defaults where the table holds nothing for this
     * vocabulary: an install migrated but never seeded, or a vocabulary added in
     * a later release, behaves exactly as the constant did rather than
     * validating every value away.
     *
     * Deliberately **not memoised.** A first cut cached the list in a static
     * property, which then survived everything that should have reset it — a
     * request boundary, a queue worker's next job, a test's fresh database — and
     * a stale list here means valid values rejected and retired ones accepted.
     * The read is one indexed query against a table of a few dozen rows, called
     * once or twice per request from a FormRequest's rules; there was nothing to
     * save.
     *
     * @return list<string>
     */
    public static function values(string $vocabulary, bool $includeInactive = false): array
    {
        if (! self::exists($vocabulary)) {
            return [];
        }

        return self::read($vocabulary, $includeInactive);
    }

    /** @return list<string> */
    private static function read(string $vocabulary, bool $includeInactive): array
    {
        $rows = VocabularyValue::query()
            ->where('vocabulary', $vocabulary)
            ->when(! $includeInactive, fn ($query) => $query->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('value')
            ->all();

        if ($rows === [] && ! VocabularyValue::query()->where('vocabulary', $vocabulary)->exists()) {
            return self::CATALOGUE[$vocabulary]['defaults'];
        }

        return $rows;
    }

    /** The values this vocabulary ships with. */
    public static function defaults(string $vocabulary): array
    {
        return self::CATALOGUE[$vocabulary]['defaults'] ?? [];
    }

    /**
     * How many records currently hold this value.
     *
     * A value in use cannot be deleted — the rows holding it would be left
     * naming something that no longer exists, and no report could label them.
     * Deactivating it is the answer: it stops being offered, and every row that
     * already has it keeps rendering.
     *
     * One value, so an indexed `where` is the cheap way to ask. The editor,
     * which needs the figure for every value at once, uses {@see usageCounts()}
     * instead — asking this in a loop is what made that screen cost 67 queries.
     */
    public static function usageCount(string $vocabulary, string $value): int
    {
        $total = 0;

        foreach (self::CATALOGUE[$vocabulary]['usedBy'] ?? [] as [$model, $column]) {
            $total += $model::query()->where($column, $value)->count();
        }

        return $total;
    }

    /**
     * How many records hold each of this vocabulary's values.
     *
     * One grouped query per model that stores the vocabulary — two for
     * `material_type`, one for the rest — rather than one per value per model.
     * Values nothing holds are absent rather than zero; callers read through
     * `?? 0`, which is also what a value stored nowhere yet should report.
     *
     * @return array<string, int>
     */
    public static function usageCounts(string $vocabulary): array
    {
        $counts = [];

        foreach (self::CATALOGUE[$vocabulary]['usedBy'] ?? [] as [$model, $column]) {
            $rows = $model::query()
                ->select($column)
                ->selectRaw('count(*) as rows_holding')
                ->whereNotNull($column)
                ->groupBy($column)
                ->pluck('rows_holding', $column);

            foreach ($rows as $value => $held) {
                $counts[(string) $value] = ($counts[(string) $value] ?? 0) + (int) $held;
            }
        }

        return $counts;
    }

    /** Whether a key is a terminology key naming a vocabulary value that exists. */
    public static function isLabelKey(string $key): bool
    {
        return self::existingLabelKeys([$key]) !== [];
    }

    /**
     * Which of these keys are `vocab.{vocabulary}.{value}` naming a value that
     * exists.
     *
     * Asked in bulk because the callers ask in bulk: validating a submitted set
     * of terms, and rendering the whole override table on every page load. Per
     * key it was a query each — so a company that had renamed thirty list values
     * paid thirty round trips every time any screen in the app opened. Grouped
     * by vocabulary it is one read per vocabulary named, at most seven.
     *
     * @param  list<string>  $keys
     * @return array<string, true> the keys that exist, as a set
     */
    public static function existingLabelKeys(array $keys): array
    {
        $wanted = [];

        foreach ($keys as $key) {
            if (! str_starts_with($key, 'vocab.')) {
                continue;
            }

            [, $vocabulary, $value] = array_pad(explode('.', $key, 3), 3, null);

            if ($vocabulary === null || $value === null || ! self::exists($vocabulary)) {
                continue;
            }

            $wanted[$vocabulary][$value] = true;
        }

        $found = [];

        foreach ($wanted as $vocabulary => $values) {
            $known = self::values($vocabulary, includeInactive: true);

            foreach (array_keys($values) as $value) {
                if (in_array($value, $known, true)) {
                    $found["vocab.{$vocabulary}.{$value}"] = true;
                }
            }
        }

        return $found;
    }
}
