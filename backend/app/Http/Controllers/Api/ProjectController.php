<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Project::query()->with('client')->withCount('worksites');

        if ($request->boolean('active_only')) {
            $query->active();
        }
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->integer('client_id'));
        }
        $query->search($request->input('search'));

        return response()->json([
            'data' => ProjectResource::collection($query->orderBy('name')->get()),
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::create($request->validated());

        activity()->performedOn($project)->causedBy($request->user())->log('project.created');

        return (new ProjectResource($project->load('client')))->response()->setStatusCode(201);
    }

    public function show(Project $project): ProjectResource
    {
        return new ProjectResource($project->loadCount('worksites')->load(['client', 'worksites']));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        $project->update($request->validated());

        activity()->performedOn($project)->causedBy($request->user())->log('project.updated');

        return new ProjectResource($project->load('client')->loadCount('worksites'));
    }

    /** Same rule as mines: a project with sites on it is closed, not removed. */
    public function destroy(Request $request, Project $project): JsonResponse
    {
        if ($project->worksites()->exists()) {
            $project->update(['is_active' => false]);

            activity()->performedOn($project)->causedBy($request->user())->log('project.deactivated');

            return response()->json(['message' => 'Project still has worksites; it was deactivated instead.']);
        }

        $project->delete();

        activity()->performedOn($project)->causedBy($request->user())->log('project.deleted');

        return response()->json(['message' => 'Project deleted.']);
    }
}
