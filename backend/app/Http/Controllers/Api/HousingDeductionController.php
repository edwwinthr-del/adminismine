<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\StoreHousingDeductionRequest;
use App\Http\Resources\HousingDeductionResource;
use App\Models\HousingDeduction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Housing costs charged to workers. Every row is an exception to the default
 * (the company pays), which is why creating one always requires a reason.
 */
class HousingDeductionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = HousingDeduction::query()->with(['employee', 'house']);

        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('house_id')) {
            $query->where('house_id', $request->integer('house_id'));
        }
        if ($request->boolean('outstanding')) {
            $query->outstanding();
        }

        $query->orderByDesc('month')->orderBy('employee_id');

        return response()->json([
            'data' => HousingDeductionResource::collection($query->get()),
        ]);
    }

    public function store(StoreHousingDeductionRequest $request): JsonResponse
    {
        $deduction = HousingDeduction::create($request->validated());
        $deduction->recalculate();

        activity()->performedOn($deduction)->causedBy($request->user())
            ->withProperties(['reason' => $deduction->reason])
            ->log('housing_deduction.created');

        return (new HousingDeductionResource($deduction->load('employee', 'house')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreHousingDeductionRequest $request, HousingDeduction $deduction): HousingDeductionResource
    {
        $deduction->update($request->validated());
        $deduction->recalculate();

        activity()->performedOn($deduction)->causedBy($request->user())->log('housing_deduction.updated');

        return new HousingDeductionResource($deduction->load('employee', 'house'));
    }

    public function destroy(Request $request, HousingDeduction $deduction): JsonResponse
    {
        $deduction->delete();

        activity()->performedOn($deduction)->causedBy($request->user())->log('housing_deduction.deleted');

        return response()->json(['message' => 'Housing deduction deleted.']);
    }
}
