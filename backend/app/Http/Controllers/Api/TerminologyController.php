<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateTerminologyRequest;
use App\Models\TerminologyOverride;
use App\Support\ConfigSource;
use App\Support\Terminology;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * What this company calls the things the app models.
 *
 * Labels only, and only the domain nouns on the whitelist. The route, the table
 * and the permission behind a renamed screen are untouched (rule 4) — a screen
 * that says "Sites" is still `/mines` over `rudnici` behind `worksites.manage`.
 */
class TerminologyController extends Controller
{
    /**
     * Readable by any authenticated user: the whole app renders through these,
     * so gating them would mean everyone but an admin sees the built-in wording.
     */
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => Terminology::all(),
            // The editor renders a row per key under a heading per term, and
            // supplies the built-in wording as the placeholder from its own
            // dictionary — nothing translated is stored here (rule 4).
            'groups' => Terminology::GROUPS,
            'locales' => Terminology::LOCALES,
        ]);
    }

    /**
     * Set or clear terms.
     *
     * A blank value deletes the row rather than storing an empty string: the
     * absence of a row is what "we have not renamed this" means, and two
     * representations of it would eventually disagree.
     */
    public function update(UpdateTerminologyRequest $request): JsonResponse
    {
        $terms = (array) $request->validated()['terms'];
        $changed = [];

        DB::transaction(function () use ($terms, &$changed): void {
            foreach ($terms as $key => $byLocale) {
                foreach (Terminology::LOCALES as $locale) {
                    if (! array_key_exists($locale, (array) $byLocale)) {
                        continue;
                    }

                    $value = trim((string) ($byLocale[$locale] ?? ''));

                    if ($value === '') {
                        $deleted = TerminologyOverride::query()
                            ->where('key', $key)->where('locale', $locale)->delete();

                        if ($deleted > 0) {
                            $changed[] = "{$key}.{$locale} (cleared)";
                        }

                        continue;
                    }

                    $row = TerminologyOverride::query()->firstOrNew(['key' => $key, 'locale' => $locale]);

                    if ($row->value !== $value) {
                        // Writing a term makes it the company's own. Applying an
                        // industry profile replaces the words a profile wrote
                        // and leaves these — so a term corrected here survives
                        // the next setup, which is what anyone would expect of
                        // wording they typed themselves.
                        $row->fill(['value' => $value, 'source' => ConfigSource::CUSTOMER])->save();
                        $changed[] = "{$key}.{$locale}";
                    }
                }
            }
        });

        activity()
            ->causedBy($request->user())
            ->withProperties(['changed' => $changed])
            ->log('company_terminology.updated');

        return response()->json(['data' => Terminology::all(), 'changed' => count($changed)]);
    }
}
