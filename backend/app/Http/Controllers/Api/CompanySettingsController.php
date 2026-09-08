<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateCompanySettingsRequest;
use App\Http\Requests\Settings\UpdateModulesRequest;
use App\Models\CompanySettings;
use App\Services\DailyEarnedPayService;
use App\Support\CompanyConfig;
use App\Support\Modules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CompanySettingsController extends Controller
{
    public function __construct(private readonly DailyEarnedPayService $earnedPay) {}

    /**
     * Readable by any authenticated user (the app shell shows the company name).
     */
    public function show(): JsonResponse
    {
        return response()->json(['data' => CompanySettings::current()]);
    }

    /**
     * The module catalogue, with what each one currently holds.
     *
     * The record count is the point: switching something off hides everything in
     * it, and an operator should be told "this hides 412 attendance days" before
     * they do it rather than after. `requires` and `required_by` are what the
     * screen uses to explain a refusal before the API has to give one.
     */
    public function modules(): JsonResponse
    {
        $config = app(CompanyConfig::class);
        $enabled = $config->enabledModules();
        // Every module's total in one query — asked per module it was one
        // COUNT(*) per table, sixteen of them to paint this card.
        $records = Modules::recordCounts();

        $data = array_map(fn (string $module): array => [
            'key' => $module,
            'enabled' => in_array($module, $enabled, true),
            'records' => $records[$module] ?? 0,
            'requires' => Modules::requirements($module),
            'required_by' => Modules::dependents($module),
        ], Modules::keys());

        return response()->json(['data' => $data]);
    }

    /**
     * Set which modules this company has.
     *
     * Its own endpoint rather than a field on the settings save, so it can carry
     * its own permission: switching Housing off for everybody is a different act
     * from correcting the company's phone number.
     */
    public function updateModules(UpdateModulesRequest $request): JsonResponse
    {
        $settings = CompanySettings::current();
        $settings->fill(['enabled_modules' => $request->validated()['enabled_modules']])->save();

        activity()
            ->performedOn($settings)
            ->causedBy($request->user())
            ->withProperties(['enabled_modules' => $settings->enabled_modules])
            ->log('company_modules.updated');

        return response()->json(['data' => $settings->enabled_modules]);
    }

    /**
     * Save the settings, and carry a changed payroll rule through to the figures
     * derived from it.
     *
     * The standard day and the overtime multiplier are inputs to every earned
     * amount cached on an attendance record. Changing one and leaving the
     * records alone is the same failure the working-day override already
     * fixes: two workers with identical attendance paid differently depending
     * on which side of the change their day was typed. So the recompute runs in
     * the same transaction as the save — either both happened or neither did —
     * and the count goes into the audit entry, because a settings edit that
     * silently rewrote four thousand payroll figures should say so.
     */
    public function update(UpdateCompanySettingsRequest $request): JsonResponse
    {
        $settings = CompanySettings::current();
        $data = $request->validated();

        [$changes, $recomputed, $earned] = DB::transaction(function () use ($settings, $data): array {
            $settings->fill($data)->save();

            $changes = $settings->getChanges();

            $payrollChanged = array_intersect(
                array_keys($changes),
                CompanyConfig::payrollRuleColumns(),
            ) !== [];

            if (! $payrollChanged) {
                return [$changes, 0, null];
            }

            /*
             * Measured either side of the recompute, in the same transaction, so
             * the pair is the effect of this edit and nothing else.
             *
             * The count alone was never the answer to the question being asked:
             * "412 records recomputed" tells an operator that something happened
             * to the wage bill without telling them what, and it is a number
             * they cannot check against anything. The before/after totals are.
             */
            $before = $this->earnedPay->earnedTotals();

            // Saving cleared the memoised config (CompanySettings::booted), so
            // the recompute below reads the values just written, not the ones
            // they replaced.
            $count = $this->earnedPay->recomputeAll();

            return [$changes, $count, ['before' => $before, 'after' => $this->earnedPay->earnedTotals()]];
        });

        activity()
            ->performedOn($settings)
            ->causedBy($request->user())
            ->withProperties(array_filter([
                'changes' => $changes,
                'attendance_recomputed' => $recomputed,
                'earned_pay' => $earned,
            ], fn ($value): bool => $value !== null))
            ->log('company_settings.updated');

        return response()->json([
            'data' => $settings,
            // The frontend tells the operator what their edit moved.
            'attendance_recomputed' => $recomputed,
            // Per currency, because attendance totals are not stored in EUR.
            'earned_pay' => $earned,
        ]);
    }
}
