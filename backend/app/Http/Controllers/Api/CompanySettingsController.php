<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanySettingsRequest;
use App\Models\CompanySettings;
use Illuminate\Http\JsonResponse;

class CompanySettingsController extends Controller
{
    /**
     * Readable by any authenticated user (the app shell shows the company name).
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => CompanySettings::current()]);
    }

    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $settings = CompanySettings::current();
        $settings->fill($request->validated())->save();

        activity()
            ->performedOn($settings)
            ->causedBy($request->user())
            ->withProperties(['changes' => $settings->getChanges()])
            ->log('company_settings.updated');

        return response()->json(['data' => $settings]);
    }
}
