<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CompanyConfig;
use App\Support\LookupRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The single endpoint every searchable dropdown in the app reads.
 *
 * It answers with at most a page of options for what the user has typed, plus
 * `has_more` so the field can say the list is truncated instead of pretending
 * it is complete. Nothing loads the whole table.
 *
 * `include[]` is what makes an async field safe on an edit form: the record a
 * form already points at is returned even when it does not match the current
 * search or filters, so opening an old record never blanks its own selection.
 */
class LookupController extends Controller
{
    public function __invoke(Request $request, string $resource): JsonResponse
    {
        $definition = LookupRegistry::find($resource);

        abort_if($definition === null, 404, 'Unknown lookup.');

        // A lookup into a module this company does not have is not a lookup it
        // can answer — 404 like the module's own routes, not 403.
        $config = app(CompanyConfig::class);
        abort_unless($config->permissionEnabled($definition['permission']), 404, 'Unknown lookup.');

        // Same for a level of the work structure it does not use: a form with no
        // mine field has no use for a list of mines.
        $level = ['mines' => 'mine', 'projects' => 'project'][$resource] ?? null;
        abort_if($level !== null && ! $config->structureLevelEnabled($level), 404, 'Unknown lookup.');

        abort_unless($request->user()?->can($definition['permission']), 403);

        $request->validate([
            'search' => ['nullable', 'string'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LookupRegistry::MAX_LIMIT],
            'include' => ['nullable', 'array'],
            'include.*' => ['integer'],
        ]);

        $limit = min(
            max($request->integer('limit', LookupRegistry::DEFAULT_LIMIT), 1),
            LookupRegistry::MAX_LIMIT,
        );

        $query = $this->baseQuery($definition);
        ($definition['filters'] ?? fn () => null)($query, $request);
        $query->search($request->input('search'));
        ($definition['order'])($query);

        // One row past the page, so "there are more" is known without counting.
        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        $rows = $this->withPinned($definition, $request, $rows);

        return response()->json([
            'data' => $rows->map(fn (Model $row): array => array_filter([
                'value' => $row->getKey(),
                'label' => ($definition['label'])($row),
                'hint' => isset($definition['hint']) ? ($definition['hint'])($row) : null,
                'meta' => isset($definition['meta']) ? ($definition['meta'])($row) : null,
            ], fn ($value): bool => $value !== null))->values(),
            'meta' => ['has_more' => $hasMore, 'limit' => $limit],
        ]);
    }

    /** @param array<string, mixed> $definition */
    private function baseQuery(array $definition): Builder
    {
        /** @var class-string<Model> $model */
        $model = $definition['model'];

        return $model::query()->with($definition['with'] ?? []);
    }

    /**
     * Prepend the ids the caller already has selected, whatever the search says.
     *
     * @param  array<string, mixed>  $definition
     * @param  EloquentCollection<int, Model>  $rows
     * @return EloquentCollection<int, Model>
     */
    private function withPinned(array $definition, Request $request, EloquentCollection $rows): EloquentCollection
    {
        $include = array_values(array_unique(array_map('intval', (array) $request->input('include', []))));
        $missing = array_diff($include, $rows->map(fn (Model $row) => (int) $row->getKey())->all());

        if ($missing === []) {
            return $rows;
        }

        // Deliberately unfiltered: an inactive supplier a record already points
        // at still has to be nameable, or editing it would silently drop the link.
        $pinned = $this->baseQuery($definition)
            ->whereKey($missing)
            ->limit(count($missing))
            ->get();

        return $pinned->merge($rows);
    }
}
