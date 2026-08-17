<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class House extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'kuce';

    /** @var list<string> */
    protected array $searchable = ['name', 'address', 'landlord_name'];

    protected $fillable = [
        'name',
        'address',
        'landlord_name',
        'landlord_phone',
        'landlord_id_number',
        'landlord_bank_account',
        'monthly_rent',
        'deposit',
        'currency',
        'contract_start_date',
        'contract_end_date',
        'rent_due_day',
        'is_active',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'contract_start_date' => 'date:Y-m-d',
            'contract_end_date' => 'date:Y-m-d',
            'monthly_rent' => 'decimal:2',
            'deposit' => 'decimal:2',
            'rent_due_day' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(HouseOccupancy::class)->orderByDesc('moved_in_at');
    }

    /** Workers living here right now. */
    public function currentOccupancies(): HasMany
    {
        return $this->hasMany(HouseOccupancy::class)->whereNull('moved_out_at');
    }

    public function rentPayments(): HasMany
    {
        return $this->hasMany(RentPayment::class)->orderByDesc('month');
    }

    public function utilityBills(): HasMany
    {
        return $this->hasMany(UtilityBill::class)->orderByDesc('billing_period');
    }

    public function housingDeductions(): HasMany
    {
        return $this->hasMany(HousingDeduction::class)->orderByDesc('month');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
