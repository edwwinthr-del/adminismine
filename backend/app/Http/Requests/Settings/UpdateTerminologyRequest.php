<?php

namespace App\Http\Requests\Settings;

use App\Support\Terminology;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTerminologyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:company.settings.manage
    }

    public function rules(): array
    {
        $rules = [
            'terms' => ['required', 'array'],
            'terms.*' => ['array'],
        ];

        // A blank is how a term goes back to its built-in wording, so every
        // language is nullable. The length cap is what keeps a "term" a term:
        // this renames a menu entry, it is not a place to write a sentence.
        foreach (Terminology::LOCALES as $locale) {
            $rules["terms.*.{$locale}"] = ['nullable', 'string', 'max:120'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $keys = array_map(strval(...), array_keys((array) $this->input('terms', [])));

            // Resolved for the whole submission at once: a `vocab.*` key has to
            // be checked against the stored list, and one query per submitted
            // key made a large save cost a round trip per term.
            foreach (Terminology::overridableMap($keys) as $key => $allowed) {
                if (! $allowed) {
                    // Renaming "Save" is not terminology. The whitelist is the
                    // difference between a company's own vocabulary and a way to
                    // make the app unusable.
                    $validator->errors()->add('terms', "{$key} is not a term that can be renamed.");
                }
            }
        });
    }
}
