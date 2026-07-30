<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasFileAttachments;
use App\Models\Concerns\Searchable;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A worker's flight, usually bought in TRY. `amount` stays in the currency it
 * was paid in and is never rewritten; `amount_eur` plus `exchange_rate` record
 * what it cost the books.
 */
class FlightTicket extends Model
{
    use HasAuditColumns, HasFactory, HasFileAttachments, Searchable;

    /** Canonical directions; the workbook's GIDIS / DONUS / GITGEL are normalized into these. */
    public const DIRECTIONS = ['arrival', 'departure', 'round_trip'];

    /** The workbook's YAZILDI / YAZILMADI. */
    public const COST_STATUSES = ['written', 'not_written'];

    /** @var list<string> */
    protected array $searchable = ['passenger_name'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['employee' => ['first_name', 'last_name']];

    protected $fillable = [
        'employee_id',
        'passenger_name',
        'ticket_date',
        'direction',
        'route',
        'airline',
        'reference',
        'currency',
        'amount',
        'exchange_rate',
        'exchange_rate_date',
        'amount_eur',
        'cost_status',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'TRY',
        'direction' => 'departure',
        'status' => 'unpaid',
        'cost_status' => 'not_written',
    ];

    protected function casts(): array
    {
        return [
            'ticket_date' => 'date:Y-m-d',
            'exchange_rate_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
            'exchange_rate' => 'decimal:10',
            'amount_eur' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'remaining_amount' => 'decimal:2',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** Travel expense rows booked from this ticket. */
    public function travelExpenses(): HasMany
    {
        return $this->hasMany(TravelExpense::class);
    }

    /** Recompute paid/remaining/status in EUR from the linked payments. */
    public function recalculate(): void
    {
        $due = round((float) $this->amount_eur, 2);
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $remaining = round($due - $paid, 2);

        $this->forceFill([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $paid <= 0 ? 'unpaid' : ($remaining > 0.001 ? 'partial' : 'paid'),
        ])->save();
    }

    /** Who flew — the employee record if there is one, otherwise the imported name. */
    protected function travellerName(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->employee?->full_name ?? $this->passenger_name);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    /** The spec's "unwritten ticket costs": bought but not yet booked to the worker. */
    public function scopeUnwritten(Builder $query): Builder
    {
        return $query->where('cost_status', 'not_written');
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        [$start, $end] = MonthPeriod::range($month);

        return $query->whereBetween('ticket_date', [$start, $end]);
    }
}
