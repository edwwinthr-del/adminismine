<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Master\StoreMasterRequest;
use App\Http\Requests\Master\UpdateMasterRequest;
use App\Http\Resources\MasterResource;
use App\Models\AttendanceRecord;
use App\Models\Master;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MasterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Master::query()->with(['employee', 'user', 'worksites']);

        if ($request->boolean('active_only')) {
            $query->active();
        }
        if ($request->filled('worksite_id')) {
            $worksiteId = $request->integer('worksite_id');
            $query->whereHas('worksites', fn ($q) => $q->where('worksites.id', $worksiteId));
        }

        $masters = $query->get()->sortBy(fn (Master $master) => $master->employee?->full_name ?? '')->values();

        return response()->json(['data' => MasterResource::collection($masters)]);
    }

    public function store(StoreMasterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $master = DB::transaction(function () use ($data): Master {
            $master = Master::create($data);
            $master->worksites()->sync($data['worksite_ids'] ?? []);

            return $master;
        });

        activity()->performedOn($master)->causedBy($request->user())->log('master.created');

        return (new MasterResource($master->load(['employee', 'user', 'worksites'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Master $master): MasterResource
    {
        return new MasterResource($master->load(['employee', 'user', 'worksites']));
    }

    public function update(UpdateMasterRequest $request, Master $master): MasterResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($master, $data): void {
            $master->update($data);

            if (array_key_exists('worksite_ids', $data)) {
                $master->worksites()->sync($data['worksite_ids']);
            }
        });

        activity()->performedOn($master)->causedBy($request->user())->log('master.updated');

        return new MasterResource($master->fresh()->load(['employee', 'user', 'worksites']));
    }

    /**
     * Attendance keeps a `master_id`, so the row is only removed when it was never
     * used to submit anything; otherwise the master is deactivated.
     */
    public function destroy(Request $request, Master $master): JsonResponse
    {
        if (AttendanceRecord::where('master_id', $master->id)->exists()) {
            $master->update(['is_active' => false]);

            activity()->performedOn($master)->causedBy($request->user())->log('master.deactivated');

            return response()->json(['message' => 'Master has attendance submissions; deactivated instead.']);
        }

        $master->worksites()->detach();
        $master->delete();

        activity()->performedOn($master)->causedBy($request->user())->log('master.deleted');

        return response()->json(['message' => 'Master deleted.']);
    }
}
