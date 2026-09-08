<?php

namespace App\Http\Requests\Settings;

use App\Support\Vocabulary;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateVocabularyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:company.settings.manage
    }

    public function rules(): array
    {
        return [
            // The whole list, in the order it should be offered — a set, not a
            // diff, so ordering is stated rather than accumulated.
            'values' => ['required', 'array', 'min:1'],
            /*
             * Canonical snake_case, whatever the value is called on screen
             * (rule 4). A stored value is what reports group by, what the
             * importer normalises onto and what the API contract carries; the
             * wording lives in the terminology layer, where it can be different
             * in three languages without the data moving.
             */
            'values.*.value' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/'],
            'values.*.is_active' => ['sometimes', 'boolean'],
            'values.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function messages(): array
    {
        return [
            'values.*.value.regex' => 'A stored value must be lowercase with underscores, such as `crushed_stone`.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $vocabulary = (string) $this->route('vocabulary');
            $submitted = array_column((array) $this->input('values', []), 'value');

            if (count($submitted) !== count(array_unique($submitted))) {
                $validator->errors()->add('values', 'The same value is listed twice.');
            }

            foreach (Vocabulary::values($vocabulary, includeInactive: true) as $existing) {
                if (in_array($existing, $submitted, true)) {
                    continue;
                }

                // Removing a value the app ships with would leave a model
                // default and the workbook importer's label normaliser pointing
                // at something that is not there.
                if (in_array($existing, Vocabulary::defaults($vocabulary), true)) {
                    $validator->errors()->add(
                        'values',
                        "`{$existing}` ships with the app and can be switched off, but not removed.",
                    );

                    continue;
                }

                // And removing one that records hold would leave those rows
                // naming something nothing can label.
                $inUse = Vocabulary::usageCount($vocabulary, $existing);

                if ($inUse > 0) {
                    $validator->errors()->add(
                        'values',
                        "`{$existing}` is used by {$inUse} records. Switch it off instead of removing it.",
                    );
                }
            }
        });
    }
}
