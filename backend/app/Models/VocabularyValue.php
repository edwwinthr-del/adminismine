<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One value in one of the company's own lists.
 *
 * `is_system` marks a value the app shipped with. Those may be **deactivated but
 * not deleted**: a model default names some of them and the workbook importer's
 * label normaliser maps onto others (`ARABA` → `car`), so removing the row would
 * leave code pointing at a value that is not there.
 */
class VocabularyValue extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'recnici';

    protected $fillable = ['vocabulary', 'value', 'sort_order', 'is_active', 'is_system', 'source', 'notes'];

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
        'is_system' => false,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'is_system' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
