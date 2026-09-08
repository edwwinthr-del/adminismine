<?php

namespace App\Http\Requests\Settings;

use App\Support\Modules;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Which parts of the app this company has.
 *
 * Its own request, and its own permission, because it is its own power: renaming
 * the company is not the same act as switching Housing off for everybody, and
 * one permission covering both is how somebody ends up able to do the second
 * because they needed the first.
 */
class UpdateModulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by can:company.modules.manage
    }

    public function rules(): array
    {
        return [
            // Sent whole — a set, not a diff — so there is one statement of what
            // the app is rather than a sequence of toggles whose order matters.
            'enabled_modules' => ['required', 'array'],
            'enabled_modules.*' => ['string', Rule::in(Modules::keys())],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['enabled_modules'])) {
                return;
            }

            $enabled = array_map('strval', (array) $this->input('enabled_modules', []));

            // Attendance without workers is a table of rows about nobody, and
            // production without worksites has nowhere for output to come from —
            // the foreign keys are not nullable. Refused rather than allowed to
            // half-work, and the message names the module that is missing so the
            // fix is obvious.
            foreach ($enabled as $module) {
                foreach (Modules::requirements($module) as $required) {
                    if (! in_array($required, $enabled, true)) {
                        $validator->errors()->add(
                            'enabled_modules',
                            "The {$module} module needs {$required}, which is switched off.",
                        );
                    }
                }
            }
        });
    }
}
