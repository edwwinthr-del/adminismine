<?php

namespace App\Support;

use App\Services\ApplyProfile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "How much is in here", asked about several tables at once.
 *
 * Three places needed the same answer and each grew its own loop for it:
 * {@see Modules::recordCounts()} before switching a module off,
 * {@see ApplyProfile::existingRecords()} before reshaping an
 * install, and the settings screen that shows both. Written three times it was
 * also *counted* three times — one `COUNT(*)` per table per request, sixteen
 * round trips to paint one screen.
 *
 * The counts are advisory: they fill a confirmation ("this hides 412 attendance
 * days"), so they are wanted together or not at all, which is exactly the shape
 * a single `UNION ALL` serves. One round trip, the same numbers.
 *
 * Bucket names are ours — module keys and the labels in `BUSINESS_RECORDS`,
 * never anything a user typed — but they are quoted anyway, because the day
 * someone passes a bucket name through from a request should not be the day this
 * becomes an injection.
 */
final class RowCounts
{
    /**
     * Count whole tables, grouped into named buckets, in one query.
     *
     * A bucket may name several models (a "worksites" module is mines, projects
     * and worksites) and its total is their sum. A bucket naming none — the
     * assistant module holds no records of its own — is reported as 0 rather
     * than omitted, so the caller can index every bucket it asked about.
     *
     * @param  array<string, list<class-string<Model>>>  $models
     * @return array<string, int>
     */
    public static function forModels(array $models): array
    {
        $counts = array_fill_keys(array_keys($models), 0);
        $query = null;

        foreach ($models as $bucket => $classes) {
            foreach ($classes as $class) {
                $part = DB::table((new $class)->getTable())
                    ->selectRaw(self::quote((string) $bucket).' as bucket, count(*) as total');

                // The first arm is the query; the rest are unioned onto it.
                $query = $query instanceof Builder ? $query->unionAll($part) : $part;
            }
        }

        if ($query === null) {
            return $counts;
        }

        foreach ($query->get() as $row) {
            $counts[$row->bucket] = ($counts[$row->bucket] ?? 0) + (int) $row->total;
        }

        return $counts;
    }

    /** A SQL string literal — both drivers escape a quote by doubling it. */
    private static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }
}
