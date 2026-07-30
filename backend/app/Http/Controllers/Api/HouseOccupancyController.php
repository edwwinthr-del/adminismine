<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Housing\StoreOccupancyRequest;
use App\Http\Requests\Housing\UpdateOccupancyRequest;
use App\Http\Resources\HouseOccupancyResource;
use App\Models\HouseOccupancy;
use App\Support\MonthPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HouseOccupancyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = HouseOccupancy::query()->with(['house', 'employee']);

        if ($request->filled('house_id')) {
            $query->where('house_id', $request->integer('house_id'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->boolean('current_only')) {
            $query->current();
        }
        // Who lived in the house during a given month, movers included.
        if ($request->filled('month')) {
            [$start, $end] = MonthPeriod::range($request->input('month'));
            $query->overlappingMonth($start, $end);
        }

        $query->orderByDesc('moved_in_at')->orderByDesc('id');

        return response()->json([
            'data' => HouseOccupancyResource::collection($query->get()),
        ]);
    }

    /**
     * Move a worker in. When they already live somewhere, that stay is closed on
     * the move-in date instead of being edited, so the history stays dated.
     */
    public function store(StoreOccupancyRequest $request): JsonResponse
    {
        $data = $request->validated();
        $closePrevious = $data['close_previous'] ?? true;
        unset($data['close_previous']);

        $closed = null;

        $occupancy = DB::transaction(function () use ($data, $closePrevious, &$closed): HouseOccupancy {
            if ($closePrevious) {
                $previous = HouseOccupancy::query()
                    ->current()
                    ->where('employee_id', $data['employee_id'])
                    ->get();

                foreach ($previous as $stay) {
                    $stay->update(['moved_out_at' => $data['moved_in_at']]);
                    $closed = $stay->id;
                }
            }

            return HouseOccupancy::create($data);
        });

        activity()->performedOn($occupancy)->causedBy($request->user())
            ->withProperties(['closed_previous_occupancy_id' => $closed])
            ->log('house_occupancy.created');

        return (new HouseOccupancyResource($occupancy->load(['house', 'employee'])))
            ->response()
            ->setStatusCode(201);
    }

    public function update(UpdateOccupancyRequest $request, HouseOccupancy $occupancy): HouseOccupancyResource
    {
        $occupancy->update($request->validated());

        activity()->performedOn($occupancy)->causedBy($request->user())->log('house_occupancy.updated');

        return new HouseOccupancyResource($occupancy->load(['house', 'employee']));
    }

    public function destroy(Request $request, HouseOccupancy $occupancy): JsonResponse
    {
        $occupancy->delete();

        activity()->performedOn($occupancy)->causedBy($request->user())->log('house_occupancy.deleted');

        return response()->json(['message' => 'Occupancy record deleted.']);
    }
}
