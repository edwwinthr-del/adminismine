<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * One definition of what "contains this text" means for every live search in
 * the app.
 *
 * Two things it fixes that a bare `where(col, 'like', "%$term%")` gets wrong:
 *
 * 1. Case. Postgres `LIKE` is case-sensitive, SQLite's folds ASCII only. The
 *    suite runs on SQLite and the real data lives on Postgres, so a search that
 *    passed a test would quietly miss `ACME` for `acme` in production. Each
 *    driver gets the comparison that is actually case-insensitive for it.
 * 2. Wildcards. `%` and `_` typed by a user are data, not operators — an
 *    unescaped `%` turns a search into a full scan that matches everything.
 */
final class SearchTerm
{
    /** Longer than this is never a real search, only a way to make the DB work. */
    public const MAX_LENGTH = 120;

    /** Trim, cap and reject empty terms. Returns null when there is nothing to search for. */
    public static function normalize(?string $term): ?string
    {
        $term = trim((string) $term);

        if ($term === '') {
            return null;
        }

        return mb_substr($term, 0, self::MAX_LENGTH);
    }

    /** A LIKE pattern with the user's own wildcards escaped to literals. */
    public static function pattern(string $term): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term).'%';
    }

    /**
     * Add a case-insensitive "contains" condition on one column.
     *
     * @param  EloquentBuilder<*>|QueryBuilder  $query
     * @param  'and'|'or'  $boolean
     */
    public static function apply(
        EloquentBuilder|QueryBuilder $query,
        string $column,
        string $term,
        string $boolean = 'and',
    ): void {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $driver = $base->getConnection()->getDriverName();

        // Qualify the column with its table so the same scope is safe once a
        // caller joins another table that happens to share a column name.
        $qualified = ! str_contains($column, '.') && is_string($base->from)
            ? $base->from.'.'.$column
            : $column;

        $wrapped = $base->getGrammar()->wrap($qualified);

        // ESCAPE is spelled out because SQLite has no default escape character:
        // without it the backslashes above would be matched literally.
        [$sql, $binding] = match ($driver) {
            'pgsql' => ["{$wrapped} ILIKE ? ESCAPE '\\'", self::pattern($term)],
            // SQLite folds ASCII only, so Turkish/Serbian letters need lower().
            'sqlite' => ["LOWER({$wrapped}) LIKE ? ESCAPE '\\'", mb_strtolower(self::pattern($term))],
            default => ["{$wrapped} LIKE ? ESCAPE '\\'", self::pattern($term)],
        };

        $query->whereRaw($sql, [$binding], $boolean);
    }
}
