<?php

namespace App\Models\Concerns;

use App\Support\SearchTerm;
use Illuminate\Database\Eloquent\Builder;

/**
 * Gives a model one `search()` scope built from a declared column list, so every
 * live search in the app hits the same (case-insensitive, wildcard-escaped)
 * comparison instead of each controller writing its own `like`.
 *
 * Declare the columns on the model:
 *
 *     protected array $searchable = ['invoice_number', 'description'];
 *     protected array $searchableRelations = ['supplier' => ['name']];
 *
 * Searching a relation costs an extra subquery, so only list the columns a user
 * would actually type — a counterparty's name, not every field it owns.
 */
trait Searchable
{
    /** @param  Builder<static>  $query */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = SearchTerm::normalize($term);

        if ($term === null) {
            return $query;
        }

        $columns = $this->searchableColumns();
        $relations = $this->searchableRelationColumns();

        if ($columns === [] && $relations === []) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($columns, $relations, $term): void {
            foreach ($columns as $column) {
                SearchTerm::apply($inner, $column, $term, 'or');
            }

            foreach ($relations as $relation => $relationColumns) {
                $inner->orWhereHas($relation, function (Builder $sub) use ($relationColumns, $term): void {
                    $sub->where(function (Builder $group) use ($relationColumns, $term): void {
                        foreach ($relationColumns as $column) {
                            SearchTerm::apply($group, $column, $term, 'or');
                        }
                    });
                });
            }
        });
    }

    /** @return list<string> */
    public function searchableColumns(): array
    {
        return $this->searchable ?? [];
    }

    /** @return array<string, list<string>> */
    public function searchableRelationColumns(): array
    {
        return $this->searchableRelations ?? [];
    }
}
