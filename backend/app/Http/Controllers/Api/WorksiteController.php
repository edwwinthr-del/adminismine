<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Worksite\StoreWorksiteRequest;
use App\Http\Requests\Worksite\SyncWorksiteEmployeesRequest;
use App\Http\Requests\Worksite\UpdateWorksiteRequest;
use App\Http\Resources\WorksiteResource;
use App\Models\Worksite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class WorksiteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Worksite::query()->with(['client', 'mine', 'project'])->withCount(['employees', 'masters']);

        if ($request->boolean('active_only')) {
            $query->active();
        }
        if ($request->filled('mine_id')) {
            $query->where('mine_id', $request->integer('mine_id'));
        }
        if ($request->filled('project_id')) {
            $query->where('project_id', $request->integer('project_id'));
        }
        $query->search($request->input('search'));

        return response()->json([
            'data' => WorksiteResource::collection($query->orderBy('name')->get()),
        ]);
    }

    public function store(StoreWorksiteRequest $request): JsonResponse
    {
        $worksite = Worksite::create($request->validated());

        activity()->performedOn($worksite)->causedBy($request->user())->log('worksite.created');

        return (new WorksiteResource($worksite->load(['client', 'mine', 'project'])))->response()->setStatusCode(201);
    }

    public function show(Worksite $worksite): WorksiteResource
    {
        return new WorksiteResource(
            $worksite->load(['client', 'mine', 'project', 'employees', 'masters.employee'])
        );
    }

    public function update(UpdateWorksiteRequest $request, Worksite $worksite): WorksiteResource
    {
        $worksite->update($request->validated());

        activity()->performedOn($worksite)->causedBy($request->user())->log('worksite.updated');

        return new WorksiteResource($worksite->load(['client', 'mine', 'project']));
    }

    /**
     * Worksites are referenced by attendance history, so an unused site is deleted
     * while one with records is deactivated instead.
     */
    public function destroy(Request $request, Worksite $worksite): JsonResponse
    {
        if ($worksite->attendanceRecords()->exists()) {
            $worksite->update(['is_active' => false]);

            activity()->performedOn($worksite)->causedBy($request->user())->log('worksite.deactivated');

            return response()->json(['message' => 'Worksite has attendance history; it was deactivated instead.']);
        }

        $worksite->employees()->detach();
        $worksite->masters()->detach();
        $worksite->delete();

        activity()->performedOn($worksite)->causedBy($request->user())->log('worksite.deleted');

        return response()->json(['message' => 'Worksite deleted.']);
    }

    /** Replace the site's employee roster. */
    public function syncEmployees(SyncWorksiteEmployeesRequest $request, Worksite $worksite): WorksiteResource
    {
        $pivot = collect($request->validated('employees'))
            ->mapWithKeys(fn (array $row): array => [
                $row['employee_id'] => Arr::only($row, ['assigned_from', 'assigned_to']),
            ])
            ->all();

        $worksite->employees()->sync($pivot);

        activity()->performedOn($worksite)->causedBy($request->user())
            ->withProperties(['employee_ids' => array_keys($pivot)])
            ->log('worksite.employees_synced');

        return new WorksiteResource($worksite->load(['client', 'mine', 'project', 'employees']));
    }
}
