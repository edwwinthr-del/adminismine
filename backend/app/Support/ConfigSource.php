<?php

namespace App\Support;

use App\Services\ApplyProfile;

/**
 * Who put a piece of configuration there.
 *
 * Terminology overrides and vocabulary values are written by three different
 * things — the app ships some, a profile writes some, the company types the
 * rest — and until the row said which, they were indistinguishable. That is
 * what made applying a profile destructive: "replace the terminology" was
 * implemented as `delete everything, then write mine`, which was correct when
 * profiles were the only author and quietly stopped being correct the moment
 * the terminology editor shipped.
 *
 * The rule this encodes: **the app may retire what the app wrote. What the
 * customer wrote is theirs.**
 *
 * These are stored in the `source` audit column that every table already
 * carries (rule 3), so nothing needed a migration to start telling them apart.
 */
final class ConfigSource
{
    /** Seeded with the app. Deactivatable by a profile, never deletable. */
    public const SHIPPED = 'migration';

    /** Written by {@see ApplyProfile}. The app's to replace. */
    public const PROFILE = 'profile';

    /**
     * Saved through the terminology or vocabulary editor.
     *
     * The customer's own words. Applying a profile leaves these alone, and
     * editing a profile-supplied row re-stamps it this way — so correcting a
     * single word is enough to keep it through the next apply.
     */
    public const CUSTOMER = 'manual';
}
