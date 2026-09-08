<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateVocabularyRequest;
use App\Models\VocabularyValue;
use App\Support\ConfigSource;
use App\Support\Vocabulary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The open-ended lists a company fills in for itself.
 *
 * Only the lists nothing branches on: `bauxite_ore` decides nothing, so a
 * construction firm may replace it with `concrete`. `present`, `approved`,
 * `income` and `expense` are not here and never will be — those decide what the
 * app *does*, and an editor over them would be an editor over the program.
 */
class VocabularyController extends Controller
{
    /**
     * Every vocabulary with its values.
     *
     * Inactive values are included: a form editing an old record has to be able
     * to show the value that record already holds, and the editor has to be able
     * to switch one back on.
     */
    public function index(): JsonResponse
    {
        $rows = VocabularyValue::query()->ordered()->get()->groupBy('vocabulary');

        $data = array_map(fn (string $vocabulary): array => [
            'key' => $vocabulary,
            'values' => $this->valuesFor($vocabulary, $rows->get($vocabulary)),
        ], Vocabulary::keys());

        return response()->json(['data' => $data]);
    }

    /**
     * Replace one vocabulary's list.
     *
     * Whole-list, so the order the values are offered in is stated rather than
     * accumulated from a series of edits.
     */
    public function update(UpdateVocabularyRequest $request, string $vocabulary): JsonResponse
    {
        abort_unless(Vocabulary::exists($vocabulary), 404);

        $submitted = (array) $request->validated()['values'];

        DB::transaction(function () use ($vocabulary, $submitted): void {
            $keep = [];

            foreach (array_values($submitted) as $index => $value) {
                $row = VocabularyValue::query()->firstOrNew([
                    'vocabulary' => $vocabulary,
                    'value' => $value['value'],
                ]);

                if (! $row->exists) {
                    // A value the company added for itself, which applying an
                    // industry profile must not retire — it retires the app's
                    // own values a profile does not list, and until the row
                    // said who added it the two looked the same. Reordering or
                    // switching off a shipped value is not authorship, so only
                    // a new row is stamped.
                    $row->source = ConfigSource::CUSTOMER;
                }

                $row->fill([
                    'is_active' => (bool) ($value['is_active'] ?? true),
                    'sort_order' => (int) ($value['sort_order'] ?? $index),
                ])->save();

                $keep[] = $row->id;
            }

            // Whatever is not in the list goes. The request refused to let that
            // include a shipped value or one records still hold, so anything
            // reaching here is a value nothing depends on.
            VocabularyValue::query()
                ->where('vocabulary', $vocabulary)
                ->whereNotIn('id', $keep)
                ->delete();
        });

        activity()
            ->causedBy($request->user())
            ->withProperties([
                'vocabulary' => $vocabulary,
                'values' => Vocabulary::values($vocabulary, includeInactive: true),
            ])
            ->log('vocabulary.updated');

        $rows = VocabularyValue::query()->where('vocabulary', $vocabulary)->ordered()->get();

        return response()->json(['data' => [
            'key' => $vocabulary,
            'values' => $this->valuesFor($vocabulary, $rows),
        ]]);
    }

    /**
     * One vocabulary's values, with what each is worth knowing before editing:
     * whether it shipped with the app, and how many records hold it.
     *
     * @param  Collection<int, VocabularyValue>|null  $rows
     * @return list<array<string, mixed>>
     */
    private function valuesFor(string $vocabulary, $rows): array
    {
        // Every value's count in one grouped read per model behind the
        // vocabulary. Asked per value it was a query each, and this method runs
        // once per vocabulary — which is how listing seven of them cost 67.
        $held = Vocabulary::usageCounts($vocabulary);

        if ($rows === null || $rows->isEmpty()) {
            // Nothing stored: the vocabulary is still answering with what it
            // ships with, so the editor shows exactly that.
            return array_map(fn (string $value, int $index): array => [
                'value' => $value,
                'is_active' => true,
                'is_system' => true,
                'sort_order' => $index,
                'records' => $held[$value] ?? 0,
            ], Vocabulary::defaults($vocabulary), array_keys(Vocabulary::defaults($vocabulary)));
        }

        return $rows->map(fn (VocabularyValue $row): array => [
            'value' => $row->value,
            'is_active' => $row->is_active,
            'is_system' => $row->is_system,
            'sort_order' => $row->sort_order,
            'records' => $held[$row->value] ?? 0,
        ])->values()->all();
    }
}
