<?php

namespace App\Support;

use App\Models\TerminologyOverride;

/**
 * The domain nouns a company may rename.
 *
 * The app models a labour subcontractor working someone else's sites, and that
 * structure fits a construction firm, a facility-services company and a
 * shipyard as well as it fits a mine. What does not fit is the vocabulary: they
 * do not have *mines*, they do not have *majstori*, and what comes out of the
 * ground is not what comes off their line.
 *
 * So the words are configurable and nothing else is. This is a **whitelist, not
 * a free dictionary**: renaming "Save" or "Cancel" is not terminology, it is a
 * way to make the app unusable, and an open editor over 1,400 keys would
 * eventually be used that way. A key that is not here is refused (422).
 *
 * Overrides never touch stored values (rule 4). `/mines` stays `/mines`,
 * `rudnici` stays `rudnici`, `worksites.manage` stays `worksites.manage` — a
 * screen that says "Sites" is a screen, not a schema.
 */
final class Terminology
{
    /** The languages a term can be given in. */
    public const LOCALES = ['en', 'sr', 'tr'];

    /**
     * Every label a company may rename, grouped so the editor reads as a list of
     * *terms* rather than a wall of keys.
     *
     * A term is a noun and the handful of labels that name it: the menu entry,
     * the heading, the "new"/"edit" buttons, the search box, the empty state and
     * the cross-references from neighbouring screens. Renaming a menu entry and
     * leaving the button underneath it saying "New mine" is a half-done rename,
     * and that is what a narrower list produced when it was first tried.
     *
     * Deliberately absent: money (payables, receivables, bank, exchange rates),
     * the system section, and every generic action and status. Those are the
     * same words in every company that would buy this — and an editor that could
     * rename "Save" is a way to make the app unusable.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const GROUPS = [
        'People' => [
            'workers' => ['nav.workers', 'workers.title', 'workers.new', 'workers.edit', 'workers.search', 'workers.none'],
            'salaries' => ['nav.salaries', 'salaries.title', 'salaries.none'],
            'masters' => ['nav.masters', 'masters.title', 'masters.new', 'masters.edit', 'masters.none', 'masters.master', 'masters.worksites', 'masters.noWorksites'],
            'attendance' => ['nav.attendance', 'attendance.title', 'attendance.worker', 'attendance.selectWorksite'],
            'worker_needs' => ['nav.workerNeeds', 'needs.title', 'needs.new', 'needs.edit', 'needs.search', 'needs.none', 'needs.worker', 'needs.worksite', 'needs.noWorksite', 'needs.detailsTitle'],
        ],
        'Work structure' => [
            'mines' => ['nav.mines', 'mines.title', 'mines.subtitle', 'mines.new', 'mines.edit', 'mines.search', 'mines.none', 'mines.worksites'],
            'projects' => ['nav.projects', 'projects.title', 'projects.subtitle', 'projects.new', 'projects.edit', 'projects.search', 'projects.none', 'projects.worksites'],
            'worksites' => ['nav.worksites', 'worksites.title', 'worksites.new', 'worksites.edit', 'worksites.search', 'worksites.none', 'worksites.project', 'worksites.workers', 'worksites.masters', 'worksites.mine', 'worksites.noMine', 'worksites.noProject'],
        ],
        'Operations' => [
            'production' => ['nav.mining', 'mining.title', 'mining.new', 'mining.edit', 'mining.none', 'mining.worksite', 'mining.allWorksites', 'mining.byWorksite'],
            'machines' => ['nav.machines', 'machines.title', 'machines.new', 'machines.edit', 'machines.search', 'machines.none'],
            'customs' => ['nav.customs', 'customs.title', 'customs.new', 'customs.edit', 'customs.search', 'customs.none'],
        ],
        'Housing & welfare' => [
            'housing' => ['nav.housing', 'housing.title', 'housing.edit'],
            'travel' => ['nav.travel', 'travel.title', 'travel.edit'],
            'loans' => ['nav.loans', 'loans.title', 'loans.new', 'loans.edit', 'loans.none'],
        ],
        'Navigation groups' => [
            'groups' => ['navGroup.people', 'navGroup.operations', 'navGroup.housing'],
        ],
        'Report columns' => [
            'columns' => ['reportColumn.worker', 'reportColumn.worksite', 'reportColumn.engineer', 'reportColumn.machine', 'reportColumn.house', 'reportColumn.material', 'reportColumn.unit', 'reportColumn.quantity'],
        ],
    ];

    /**
     * The flat whitelist, derived from the groups so the two cannot disagree.
     *
     * @return list<string>
     */
    public static function overridable(): array
    {
        static $flat = null;

        if ($flat === null) {
            $flat = [];

            foreach (self::GROUPS as $terms) {
                foreach ($terms as $keys) {
                    foreach ($keys as $key) {
                        $flat[] = $key;
                    }
                }
            }
        }

        return $flat;
    }

    /**
     * Whether a key may be renamed.
     *
     * The static whitelist, plus `vocab.{vocabulary}.{value}` for any value a
     * vocabulary actually holds — those cannot be listed here because the
     * company invents them. A value that does not exist is still refused, so
     * this stays a whitelist rather than becoming an open dictionary.
     */
    public static function isOverridable(string $key): bool
    {
        return self::overridableMap([$key])[$key];
    }

    /**
     * The same question about a set of keys, in one pass.
     *
     * Both callers hold a set: the request validating submitted terms, and
     * {@see all()} filtering the stored ones — and `all()` runs on **every page
     * load of the app**, because the whole UI renders through it. Asked one key
     * at a time, every `vocab.*` override cost a query there.
     *
     * @param  list<string>  $keys
     * @return array<string, bool>
     */
    public static function overridableMap(array $keys): array
    {
        $whitelist = array_flip(self::overridable());

        // Only what the whitelist does not already answer reaches the database.
        $labels = Vocabulary::existingLabelKeys(array_values(array_filter(
            $keys,
            static fn (string $key): bool => ! isset($whitelist[$key]),
        )));

        $map = [];

        foreach ($keys as $key) {
            $map[$key] = isset($whitelist[$key]) || isset($labels[$key]);
        }

        return $map;
    }

    /**
     * Every override this company has set, keyed by term then language.
     *
     * Only whitelisted keys are returned: a key removed from the whitelist in a
     * later release should stop taking effect rather than keep renaming
     * something nobody can find in the editor.
     *
     * @return array<string, array<string, string>>
     */
    public static function all(): array
    {
        $rows = TerminologyOverride::query()->get(['key', 'locale', 'value']);

        // Resolved for the whole set before the loop: this runs on every page
        // load, and per row it was a query per renamed vocabulary value.
        $allowed = self::overridableMap($rows->pluck('key')->unique()->values()->all());

        $overrides = [];

        foreach ($rows as $row) {
            if (! ($allowed[$row->key] ?? false) || ! in_array($row->locale, self::LOCALES, true)) {
                continue;
            }

            $overrides[$row->key][$row->locale] = $row->value;
        }

        return $overrides;
    }
}
