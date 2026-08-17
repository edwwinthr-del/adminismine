<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An authorized override of a month's working-day count. Rows only exist for
 * months that were overridden; every other month is derived (see WorkingDaysService).
 */
class WorkingDaySetting extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'podesavanja_radnih_dana';

    protected $fillable = [
        'month',
        'working_days',
        'reason',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'month' => 'date:Y-m-d',
            'working_days' => 'integer',
        ];
    }
}
