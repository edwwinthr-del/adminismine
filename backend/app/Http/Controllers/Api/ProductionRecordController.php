<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Production\StoreProductionRecordRequest;
use App\Http\Requests\Production\UpdateProductionRecordRequest;
use App\Http\Resources\ProductionRecordResource;
use App\Models\ProductionRecord;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class ProductionRecordController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = ProductionRecord::query()->with(['worksite', 'engineer']);

        $this->applyFilters($query, $request);

        $query->orderByDesc('period_month')->orderByDesc('date')->orderByDesc('id');

        return ProductionRecordResource::collection($query->paginate($request->integer('per_page', 100)));
    }

    public function store(StoreProductionRecordRequest $request): JsonResponse
    {
        $record = ProductionRecord::create($request->validated());

        activity()->performedOn($record)->causedBy($request->user())->log('production.created');

        return (new ProductionRecordResource($record->load(['worksite', 'engineer'])))
            ->response()
            ->setStatusCode(201);
    }

    public function show(ProductionRecord $production): ProductionRecordResource
    {
        return new ProductionRecordResource($production->load(['worksite', 'engineer']));
    }

    /** Editable until approved; after that only a manager may correct it. */
    public function update(UpdateProductionRecordRequest $request, ProductionRecord $production): JsonResponse
    {
        if ($production->isApproved() && ! $request->user()->can('mining_production.approve')) {
            return response()->json([
                'message' => 'This record is approved and locked; ask a manager to reopen it.',
            ], 422);
        }

        $before = $production->only(['quantity', 'unit', 'material_type', 'date', 'approval_status']);

        $production->update($request->validated());

        // An approved record can still be edited by an approver, so what the
        // figures moved from and to is the only way the trail can show it.
        activity()->performedOn($production)->causedBy($request->user())
            ->withProperties(['before' => $before, 'after' => $production->only(array_keys($before))])
            ->log('production.updated');

        return response()->json([
            'data' => new ProductionRecordResource($production->fresh()->load(['worksite', 'engineer'])),
        ]);
    }

    public function destroy(Request $request, ProductionRecord $production): JsonResponse
    {
        if ($production->isApproved() && ! $request->user()->can('mining_production.approve')) {
            return response()->json(['message' => 'This record is approved and locked.'], 422);
        }

        $production->delete();

        activity()->performedOn($production)->causedBy($request->user())
            ->withProperties(['removed' => $production->only(['worksite_id', 'period_type', 'date', 'material_type', 'quantity', 'unit', 'approval_status'])])
            ->log('production.deleted');

        return response()->json(['message' => 'Production record deleted.']);
    }

    public function approve(Request $request, ProductionRecord $production): JsonResponse
    {
        $production->forceFill([
            'approval_status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $request->user()->id,
            'rejection_reason' => null,
        ])->save();

        activity()->performedOn($production)->causedBy($request->user())->log('production.approved');

        return response()->json([
            'data' => new ProductionRecordResource($production->load(['worksite', 'engineer'])),
        ]);
    }

    public function reject(Request $request, ProductionRecord $production): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $production->forceFill([
            'approval_status' => 'rejected',
            'approved_at' => null,
            'approved_by' => null,
            'rejection_reason' => $data['reason'],
        ])->save();

        activity()->performedOn($production)->causedBy($request->user())
            ->withProperties($data)
            ->log('production.rejected');

        return response()->json([
            'data' => new ProductionRecordResource($production->load(['worksite', 'engineer'])),
        ]);
    }

    /**
     * Daily rows for the month, the month total and the year-to-date total,
     * plus the per-worksite and per-material split.
     */
    public function totals(Request $request): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'string', MonthPeriod::rule()],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'worksite_id' => ['nullable', 'integer', 'exists:worksites,id'],
            'material_type' => ['nullable', 'string'],
            'approved_only' => ['sometimes', 'boolean'],
        ]);

        $month = MonthPeriod::normalize($request->input('month', now()->toDateString()));
        $year = $request->integer('year', (int) Carbon::parse($month)->year);

        $monthRecords = $this->scopedQuery($request)->forMonth($month)->with('worksite')->get();
        $yearRecords = $this->scopedQuery($request)->forYear($year)->get();

        $daily = $monthRecords->where('period_type', 'daily')
            ->groupBy(fn (ProductionRecord $record): string => $record->date->toDateString())
            ->map(fn ($records, $date): array => [
                'date' => $date,
                'quantity' => round((float) $records->sum('quantity'), 3),
            ])
            ->sortBy('date')
            ->values();

        return response()->json([
            'data' => [
                'month' => $month,
                'year' => $year,
                'unit' => $monthRecords->first()->unit ?? 'tons',
                'daily' => $daily,
                'daily_total' => round((float) $monthRecords->where('period_type', 'daily')->sum('quantity'), 3),
                'monthly_entries_total' => round((float) $monthRecords->where('period_type', 'monthly')->sum('quantity'), 3),
                'month_total' => round((float) $monthRecords->sum('quantity'), 3),
                'year_to_date_total' => round((float) $yearRecords->sum('quantity'), 3),
                'by_worksite' => $monthRecords->groupBy('worksite_id')
                    ->map(fn ($records): array => [
                        'worksite_id' => $records->first()->worksite_id,
                        'name' => $records->first()->worksite?->name,
                        'quantity' => round((float) $records->sum('quantity'), 3),
                    ])->sortByDesc('quantity')->values(),
                'by_material' => $monthRecords->groupBy('material_type')
                    ->map(fn ($records, $material): array => [
                        'material_type' => $material,
                        'quantity' => round((float) $records->sum('quantity'), 3),
                    ])->sortByDesc('quantity')->values(),
                'pending_approval' => $monthRecords->where('approval_status', 'draft')->count(),
            ],
        ]);
    }

    /** Filtered query used by the totals endpoint (month/year applied by the caller). */
    private function scopedQuery(Request $request): Builder
    {
        $query = ProductionRecord::query();

        if ($request->filled('worksite_id')) {
            $query->where('worksite_id', $request->integer('worksite_id'));
        }
        if ($request->filled('material_type')) {
            $query->where('material_type', $request->input('material_type'));
        }
        if ($request->boolean('approved_only')) {
            $query->approved();
        }

        return $query;
    }

    private function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('month')) {
            $query->forMonth($request->input('month'));
        }
        if ($request->filled('year')) {
            $query->forYear($request->integer('year'));
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
        if ($request->filled('engineer_id')) {
            $query->where('engineer_id', $request->integer('engineer_id'));
        }
        if ($request->filled('material_type')) {
            $query->where('material_type', $request->input('material_type'));
        }
        if ($request->filled('period_type')) {
            $query->where('period_type', $request->input('period_type'));
        }
        if ($request->filled('approval_status')) {
            $query->where('approval_status', $request->input('approval_status'));
        }
        if ($request->boolean('approved_only')) {
            $query->approved();
        }
    }
}
