<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * One filter pair for every record that hangs off a worksite.
 *
 * The mine and the project live on the worksite, not on the record, so a record
 * only ever stores `worksite_id` and reaches its mine/project through it. That
 * keeps a site's reassignment to another project a one-row edit instead of a
 * rewrite of every attendance and production row it ever produced — and it is
 * why these are scopes rather than columns.
 */
trait BelongsToWorkStructure
{
    /** @param  Builder<static>  $query */
    public function scopeForMine(Builder $query, int $mineId): Builder
    {
        return $query->whereHas('worksite', fn (Builder $site) => $site->where('mine_id', $mineId));
    }

    /** @param  Builder<static>  $query */
    public function scopeForProject(Builder $query, int $projectId): Builder
    {
        return $query->whereHas('worksite', fn (Builder $site) => $site->where('project_id', $projectId));
    }
}
