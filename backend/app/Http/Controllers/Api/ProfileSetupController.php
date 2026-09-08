<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ApplyProfile;
use App\Support\CompanyConfig;
use App\Support\Industry\IndustryProfile;
use App\Support\Industry\ProfileRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Setting an install up as one shape of company.
 *
 * A profile is a starting point, not a mode: it writes ordinary settings rows —
 * modules, hierarchy depth, payroll rules, terminology, vocabularies — and every
 * one of them stays separately editable afterwards.
 */
class ProfileSetupController extends Controller
{
    public function __construct(
        private readonly ProfileRegistry $profiles,
        private readonly ApplyProfile $applier,
    ) {}

    /**
     * The profiles on offer, what each would do, and whether this install is
     * still safe to set up.
     *
     * Readable by anyone signed in: the dashboard's setup prompt keys on
     * `current` being null, and every user sees that prompt.
     */
    public function index(): JsonResponse
    {
        $existing = $this->applier->existingRecords();

        return response()->json([
            'data' => array_map(
                fn (IndustryProfile $profile): array => $this->describe($profile),
                array_values($this->profiles->all()),
            ),
            'current' => app(CompanyConfig::class)->profile(),
            // Empty on a fresh install. Applying over records is possible, but
            // it rewrites terminology and retires vocabulary values those
            // records hold, so the caller has to say so explicitly.
            'existing_records' => $existing,
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'profile' => ['required', 'string', 'in:'.implode(',', $this->profiles->keys())],
            'force' => ['sometimes', 'boolean'],
        ]);

        $existing = $this->applier->existingRecords();

        if ($existing !== [] && ! ($data['force'] ?? false)) {
            return response()->json([
                'message' => 'This install already has records. Applying a profile would rewrite its wording and retire list values those records use.',
                'errors' => ['profile' => ['This install already has records.']],
                'existing_records' => $existing,
            ], 422);
        }

        $profile = $this->profiles->find($data['profile']);
        $result = $this->applier->apply($profile);

        activity()
            ->causedBy($request->user())
            ->withProperties($result + ['forced' => (bool) ($data['force'] ?? false), 'over_records' => $existing])
            ->log('company_profile.applied');

        return response()->json(['data' => $result]);
    }

    /** @return array<string, mixed> */
    private function describe(IndustryProfile $profile): array
    {
        return [
            'key' => $profile->key(),
            // Whether this profile describes a company somebody runs or is a
            // reasoned starting point. The screen labels the difference.
            'validated' => ProfileRegistry::isValidated($profile->key()),
            'modules' => $profile->modules(),
            'work_structure_levels' => $profile->workStructureLevels(),
            'rules' => $profile->rules(),
            // Counts rather than the contents: the wizard says what a profile
            // would change, and the detail is visible afterwards in the editors
            // that own it.
            'terminology_terms' => count($profile->terminology()),
            'vocabularies' => array_keys($profile->vocabularies()),
        ];
    }
}
