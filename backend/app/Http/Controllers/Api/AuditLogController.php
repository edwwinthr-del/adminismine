<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only window onto the activity log (rule 3: everything important is
 * auditable).
 *
 * There is no write route on purpose. An audit trail that the app can edit is
 * not an audit trail, so rows only ever arrive here through `activity()` calls
 * made by the module that did the work; this controller can list and filter
 * them and nothing else.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'causer_id' => ['nullable', 'integer', 'exists:users,id'],
            'subject_type' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = Activity::query()->with('causer');

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date('date_from'));
        }
        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date('date_to'));
        }
        if ($request->filled('causer_id')) {
            $query->where('causer_id', $request->integer('causer_id'));
        }
        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->input('subject_type'));
        }

        // `description` is the event name the module logged ("payable.created"),
        // which is what a reader actually searches for.
        if (($term = SearchTerm::normalize($request->input('search'))) !== null) {
            $query->where(function (Builder $inner) use ($term): void {
                SearchTerm::apply($inner, 'description', $term, 'or');
                SearchTerm::apply($inner, 'log_name', $term, 'or');
            });
        }

        $query->orderByDesc('created_at')->orderByDesc('id');

        return AuditLogResource::collection($query->paginate($request->integer('per_page', 50)));
    }

    /**
     * The record types and event names actually present, so the filter UI offers
     * only what exists rather than a hardcoded list that drifts.
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'data' => [
                'subject_types' => Activity::query()
                    ->whereNotNull('subject_type')
                    ->distinct()
                    ->orderBy('subject_type')
                    ->pluck('subject_type')
                    ->map(fn (string $type): array => [
                        'value' => $type,
                        'label' => class_basename($type),
                    ])
                    ->values(),
                'events' => Activity::query()
                    ->whereNotNull('description')
                    ->distinct()
                    ->orderBy('description')
                    ->pluck('description')
                    ->values(),
            ],
        ]);
    }
}
