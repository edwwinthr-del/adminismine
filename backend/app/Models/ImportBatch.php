<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An uploaded workbook, parsed but not yet written to the real tables. */
class ImportBatch extends Model
{
    use HasAuditColumns, HasFactory;

    public const STATUSES = ['previewed', 'imported', 'cancelled', 'failed'];

    protected $fillable = [
        'original_name',
        // Null = the whole workbook, matched sheet by sheet. Set = a filled-in
        // template for one entity, read by its header row.
        'entity',
        'file_path',
        'sheet_summary',
        'totals',
        'source',
        'notes',
    ];

    protected $attributes = [
        'status' => 'previewed',
    ];

    protected function casts(): array
    {
        return [
            'sheet_summary' => 'array',
            'totals' => 'array',
            'imported_at' => 'datetime',
        ];
    }

    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class);
    }
}
