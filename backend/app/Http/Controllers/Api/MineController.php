<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Mine\StoreMineRequest;
use App\Http\Requests\Mine\UpdateMineRequest;
use App\Http\Resources\MineResource;
use App\Models\Mine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MineController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Mine::query()->withCount('worksites');

        if ($request->boolean('active_only')) {
            $query->active();
        }
        $query->search($request->input('search'));

        return response()->json([
            'data' => MineResource::collection($query->orderBy('name')->get()),
        ]);
    }

    public function store(StoreMineRequest $request): JsonResponse
    {
        $mine = Mine::create($request->validated());

        activity()->performedOn($mine)->causedBy($request->user())->log('mine.created');

        return (new MineResource($mine))->response()->setStatusCode(201);
    }

    public function show(Mine $mine): MineResource
    {
        return new MineResource($mine->loadCount('worksites')->load('worksites'));
    }

    public function update(UpdateMineRequest $request, Mine $mine): MineResource
    {
        $mine->update($request->validated());

        activity()->performedOn($mine)->causedBy($request->user())->log('mine.updated');

        return new MineResource($mine->loadCount('worksites'));
    }

    /**
     * A mine that still has sites on it is deactivated rather than deleted —
     * dropping it would silently unlink the attendance and production history
     * hanging off those sites.
     */
    public function destroy(Request $request, Mine $mine): JsonResponse
    {
        if ($mine->worksites()->exists()) {
            $mine->update(['is_active' => false]);

            activity()->performedOn($mine)->causedBy($request->user())->log('mine.deactivated');

            return response()->json(['message' => 'Mine still has worksites; it was deactivated instead.']);
        }

        $mine->delete();

        activity()->performedOn($mine)->causedBy($request->user())->log('mine.deleted');

        return response()->json(['message' => 'Mine deleted.']);
    }
}
