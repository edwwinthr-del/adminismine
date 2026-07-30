<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\StoreHouseRequest;
use App\Http\Requests\Housing\UpdateHouseRequest;
use App\Http\Resources\HouseResource;
use App\Models\House;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = House::query()->withCount('currentOccupancies');

        if ($request->boolean('active_only')) {
            $query->active();
        }
        $query->search($request->input('search'));
        if ($request->boolean('with_occupants')) {
            $query->with('currentOccupancies.employee');
        }

        return response()->json([
            'data' => HouseResource::collection($query->orderBy('name')->get()),
        ]);
    }

    public function store(StoreHouseRequest $request): JsonResponse
    {
        $house = House::create($request->validated());

        activity()->performedOn($house)->causedBy($request->user())->log('house.created');

        return (new HouseResource($house))->response()->setStatusCode(201);
    }

    public function show(House $house): HouseResource
    {
        return new HouseResource(
            $house->load('currentOccupancies.employee')->loadCount('currentOccupancies')
        );
    }

    public function update(UpdateHouseRequest $request, House $house): HouseResource
    {
        $house->update($request->validated());

        activity()->performedOn($house)->causedBy($request->user())->log('house.updated');

        return new HouseResource($house->loadCount('currentOccupancies'));
    }

    /**
     * Houses carry rent, bill and occupancy history, so one that has been used is
     * deactivated rather than deleted.
     */
    public function destroy(Request $request, House $house): JsonResponse
    {
        $hasHistory = $house->occupancies()->exists()
            || $house->rentPayments()->exists()
            || $house->utilityBills()->exists();

        if ($hasHistory) {
            $house->update(['is_active' => false]);

            activity()->performedOn($house)->causedBy($request->user())->log('house.deactivated');

            return response()->json(['message' => 'House has history; it was deactivated instead.']);
        }

        $house->delete();

        activity()->performedOn($house)->causedBy($request->user())->log('house.deleted');

        return response()->json(['message' => 'House deleted.']);
    }
}
