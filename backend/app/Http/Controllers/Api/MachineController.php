<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Machine\StoreMachineRequest;
use App\Http\Requests\Machine\UpdateMachineRequest;
use App\Http\Resources\MachineResource;
use App\Models\Machine;
use App\Services\FileAttachmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

class MachineController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Machine::query()
            ->with(['supplier', 'worksite'])
            ->withCount('attachments');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        if ($request->boolean('in_service')) {
            $query->inService();
        }
        if ($request->filled('machine_type')) {
            $query->where('machine_type', $request->input('machine_type'));
        }
        if ($request->filled('worksite_id')) {
            $query->where('worksite_id', $request->integer('worksite_id'));
        }
        // Mine and project live on the worksite, so these reach through it.
        if ($request->filled('mine_id')) {
            $query->forMine($request->integer('mine_id'));
        }
        if ($request->filled('project_id')) {
            $query->forProject($request->integer('project_id'));
        }
        $query->search($request->input('search'));

        $query->orderBy('machine_type')->orderBy('brand')->orderBy('model');

        return MachineResource::collection($query->paginate($request->integer('per_page', 100)));
    }

    public function store(StoreMachineRequest $request): JsonResponse
    {
        $machine = Machine::create($request->validated());

        activity()->performedOn($machine)->causedBy($request->user())->log('machine.created');

        return (new MachineResource($machine->load(['supplier', 'worksite', 'payableInvoice'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Machine $machine): MachineResource
    {
        return new MachineResource(
            $machine->load(['supplier', 'worksite', 'payableInvoice', 'attachments'])
        );
    }

    public function update(UpdateMachineRequest $request, Machine $machine): MachineResource
    {
        $before = $machine->only(['status', 'worksite_id', 'current_location']);

        $machine->update($request->validated());

        activity()->performedOn($machine)->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $machine->only(array_keys($before))])
            ->log('machine.updated');

        return new MachineResource($machine->load(['supplier', 'worksite', 'payableInvoice']));
    }

    /** Deleting a machine takes its attachment files with it. */
    public function destroy(Request $request, Machine $machine, FileAttachmentService $files): JsonResponse
    {
        DB::transaction(function () use ($machine, $files) {
            $files->deleteAllFor($machine);
            $machine->delete();
        });

        activity()->performedOn($machine)->causedBy($request->user())->log('machine.deleted');

        return response()->json(['message' => 'Machine deleted.']);
    }

    /** The machine register at a glance: counts per status and invested value. */
    public function register(Request $request): JsonResponse
    {
        $machines = Machine::query()->with('worksite')->get();

        $byStatus = collect(Machine::STATUSES)
            ->mapWithKeys(fn (string $status): array => [
                $status => $machines->where('status', $status)->count(),
            ]);

        $purchaseValue = $machines->groupBy('currency')
            ->map(fn ($rows, $currency): array => [
                'currency' => $currency,
                'total' => round((float) $rows->sum('purchase_amount'), 2),
            ])->values();

        return response()->json([
            'data' => [
                'total' => $machines->count(),
                'by_status' => $byStatus,
                'purchase_value' => $purchaseValue,
                'by_worksite' => $machines->groupBy('worksite_id')
                    ->map(fn ($rows): array => [
                        'worksite_id' => $rows->first()->worksite_id,
                        'name' => $rows->first()->worksite?->name,
                        'count' => $rows->count(),
                    ])->sortByDesc('count')->values(),
                'by_type' => $machines->groupBy('machine_type')
                    ->map(fn ($rows, $type): array => [
                        'machine_type' => $type,
                        'count' => $rows->count(),
                    ])->sortByDesc('count')->values(),
                'unlinked_purchases' => $machines
                    ->whereNull('payable_invoice_id')
                    ->whereNull('bank_transaction_id')
                    ->count(),
            ],
        ]);
    }
}
