<?php

namespace App\Models;

use App\Contracts\BooksBankMovement;
use App\Models\Concerns\BooksMovements;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasFileAttachments;
use App\Models\Concerns\Searchable;
use App\Support\MonthPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Road, car and flight travel costs carried for a worker. Booked into a month
 * (`period_month`) because the workbook reports written travel cost per month.
 */
class TravelExpense extends Model implements BooksBankMovement
{
    use BooksMovements;
    use HasAuditColumns, HasFactory, HasFileAttachments, Searchable;

    protected $table = 'putni_troskovi';

    /** Canonical types; the workbook's ARABA / UCAK are normalized into car / flight. */
    public const TYPES = ['car', 'flight', 'bus', 'taxi', 'fuel', 'accommodation', 'meal', 'other'];

    public const COST_STATUSES = ['written', 'not_written'];

    /** @var list<string> */
    protected array $searchable = ['person_name'];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = ['employee' => ['first_name', 'last_name']];

    protected $fillable = [
        'employee_id',
        'person_name',
        'expense_date',
        'period_month',
        'expense_type',
        'flight_ticket_id',
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
        'currency' => 'EUR',
        'expense_type' => 'other',
        'status' => 'unpaid',
        'cost_status' => 'not_written',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date:Y-m-d',
            'period_month' => 'date:Y-m-d',
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

    public function flightTicket(): BelongsTo
    {
        return $this->belongsTo(FlightTicket::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /** Recompute paid/remaining/status in EUR from the linked payments. */
    public function recalculate(): void
    {
        $due = round((float) $this->amount_eur, 2);
        $paid = round((float) $this->payments()->sum('amount_eur'), 2);
        $remaining = round($due - $paid, 2);

        $this->forceFill([
            'paid_amount' => $paid,
            'remaining_amount' => $remaining,
            'status' => $paid <= 0 ? 'unpaid' : ($remaining > 0.001 ? 'partial' : 'paid'),
        ])->save();
    }

    protected function travellerName(): Attribute
    {
        return Attribute::make(get: fn (): ?string => $this->employee?->full_name ?? $this->person_name);
    }

    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', ['unpaid', 'partial']);
    }

    public function scopeUnwritten(Builder $query): Builder
    {
        return $query->where('cost_status', 'not_written');
    }

    public function scopeForMonth(Builder $query, string $month): Builder
    {
        return $query->whereDate('period_month', MonthPeriod::normalize($month));
    }

    /** A travel cost carried for a worker: money out, under the travel category. */
    public function movementCategory(): string
    {
        return 'travel';
    }

    public function movementDirection(): int
    {
        return -1;
    }

    public function movementDescription(): ?string
    {
        return $this->traveller_name;
    }
}
