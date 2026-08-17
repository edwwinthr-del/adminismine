<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One dated stay of a worker in a house. Moving a worker never edits history:
 * the previous stay is closed with a move-out date and a new row is opened.
 */
class HouseOccupancy extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'useljenja';

    protected $fillable = [
        'house_id',
        'employee_id',
        'room',
        'moved_in_at',
        'moved_out_at',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'moved_in_at' => 'date:Y-m-d',
            'moved_out_at' => 'date:Y-m-d',
        ];
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    protected function isCurrent(): Attribute
    {
        return Attribute::make(get: fn (): bool => $this->moved_out_at === null);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('moved_out_at');
    }

    /** Stays that overlap the given month — who lived here during it. */
    public function scopeOverlappingMonth(Builder $query, string $monthStart, string $monthEnd): Builder
    {
        return $query->whereDate('moved_in_at', '<=', $monthEnd)
            ->where(function (Builder $q) use ($monthStart): void {
                $q->whereNull('moved_out_at')->orWhereDate('moved_out_at', '>=', $monthStart);
            });
    }

    /** True when the stay covers the given date. */
    public function coversDate(string $date): bool
    {
        $day = Carbon::parse($date);

        return $this->moved_in_at->lte($day)
            && ($this->moved_out_at === null || $this->moved_out_at->gte($day));
    }
}
