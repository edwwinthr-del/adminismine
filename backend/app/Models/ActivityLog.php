<?php

namespace App\Models;

use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * The audit trail row.
 *
 * Spatie's own model hardcodes `protected $table = 'activity_log'` rather than
 * reading it from config, so pointing the package at the Serbian table takes a
 * subclass and `activitylog.activity_model`.
 *
 * Read-only by design: there is no write route on `/api/audit-logs`, because a
 * trail the app can edit is not a trail. Rows only ever arrive through the
 * activity() calls the modules already make.
 */
class ActivityLog extends SpatieActivity
{
    protected $table = 'dnevnik_aktivnosti';
}
