<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\WorkerNeed\StoreWorkerNeedRequest;
use App\Http\Requests\WorkerNeed\UpdateWorkerNeedRequest;
use App\Http\Resources\WorkerNeedResource;
use App\Models\WorkerNeed;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class WorkerNeedController extends Controller
{
    /** Sortable columns, allow-listed so a sort parameter can never reach SQL raw. */
    private const SORTABLE = ['date', 'resolved_at', 'priority', 'status', 'need_type', 'created_at'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['nullable', Rule::in(WorkerNeed::STATUSES)],
            'priority' => ['nullable', Rule::in(WorkerNeed::PRIORITIES)],
            'need_type' => ['nullable', Rule::in(WorkerNeed::TYPES)],
            'view' => ['nullable', Rule::in(['active', 'archive'])],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = WorkerNeed::query()->with([
            'employee:id,first_name,last_name',
            'worksite:id,name',
            'assignedUser:id,name',
        ]);

        // The working list and the history are two views of one table: `archive`
        // is every settled need, `active` everything still to be dealt with.
        match ($request->input('view')) {
            'archive' => $query->settled(),
            'active' => $query->open(),
            default => $request->boolean('open_only') ? $query->open() : $query,
        };

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->input('priority'));
        }
        if ($request->filled('need_type')) {
            $query->where('need_type', $request->input('need_type'));
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->integer('employee_id'));
        }
        if ($request->filled('worksite_id')) {
            $query->where('worksite_id', $request->integer('worksite_id'));
        }
        if ($request->filled('date_from')) {
            $query->whereDate('date', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('date', '<=', $request->date('date_to'));
        }
        // When the need was settled, which is the axis the history is read on.
        if ($request->filled('resolved_from')) {
            $query->whereDate('resolved_at', '>=', $request->date('resolved_from'));
        }
        if ($request->filled('resolved_to')) {
            $query->whereDate('resolved_at', '<=', $request->date('resolved_to'));
        }

        $query->search($request->input('search'));

        $this->applySort($query, $request);

        return WorkerNeedResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    /**
     * Default order differs by view: the working list leads with what is urgent,
     * the history with what was settled most recently.
     *
     * @param  Builder<WorkerNeed>  $query
     */
    private function applySort(Builder $query, Request $request): void
    {
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $sort = $request->input('sort');

        if ($sort === null) {
            if ($request->input('view') === 'archive') {
                $query->orderByDesc('resolved_at')->orderByDesc('id');

                return;
            }

            $query->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END")
                ->orderByDesc('date')
                ->orderByDesc('id');

            return;
        }

        if ($sort === 'priority') {
            // Ranked by how pressing it is, not alphabetically.
            $query->orderByRaw(
                "CASE priority WHEN 'urgent' THEN 0 WHEN 'normal' THEN 1 ELSE 2 END {$direction}",
            );
        } else {
            $query->orderBy($sort, $direction);
        }

        $query->orderByDesc('id');
    }

    public function store(StoreWorkerNeedRequest $request): JsonResponse
    {
        $need = WorkerNeed::create($request->validated());

        activity()->performedOn($need)->causedBy($request->user())->log('worker_need.created');

        return (new WorkerNeedResource($need->load(['employee', 'worksite', 'assignedUser'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(WorkerNeed $workerNeed): WorkerNeedResource
    {
        return new WorkerNeedResource($workerNeed->load(['employee', 'worksite', 'assignedUser']));
    }

    public function update(UpdateWorkerNeedRequest $request, WorkerNeed $workerNeed): WorkerNeedResource
    {
        $data = $request->validated();
        $workerNeed->update($data);

        // Stamp/clear the resolution time when the status crosses that line.
        if (array_key_exists('status', $data)) {
            $resolved = in_array($data['status'], ['resolved', 'rejected'], true);
            $workerNeed->forceFill(['resolved_at' => $resolved ? ($workerNeed->resolved_at ?? now()) : null])->save();
        }

        activity()->performedOn($workerNeed)->causedBy($request->user())->log('worker_need.updated');

        return new WorkerNeedResource($workerNeed->fresh()->load(['employee', 'worksite', 'assignedUser']));
    }

    public function destroy(Request $request, WorkerNeed $workerNeed): JsonResponse
    {
        $workerNeed->delete();

        activity()->performedOn($workerNeed)->causedBy($request->user())
            ->withProperties(['removed' => $workerNeed->only(['employee_id', 'worksite_id', 'date', 'need_type', 'priority', 'status'])])
            ->log('worker_need.deleted');

        return response()->json(['message' => 'Worker need deleted.']);
    }
}
