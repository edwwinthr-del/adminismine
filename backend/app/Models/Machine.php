<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkStructure;
use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasFileAttachments;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Machine extends Model
{
    use BelongsToWorkStructure, HasAuditColumns, HasFactory, HasFileAttachments, Searchable;

    public const STATUSES = ['active', 'maintenance', 'inactive', 'sold'];

    /** @var list<string> */
    protected array $searchable = ['brand', 'model', 'serial_number', 'machine_type', 'purchase_invoice_number'];

    protected $fillable = [
        'machine_type',
        'brand',
        'model',
        'serial_number',
        'purchase_date',
        'supplier_id',
        'seller_name',
        'purchase_invoice_number',
        'purchase_amount',
        'currency',
        'payable_invoice_id',
        'bank_transaction_id',
        'current_location',
        'worksite_id',
        'status',
        // Optional; only a machine with these set is ever reminded about.
        'registration_expiry',
        'insurance_expiry',
        'source',
        'notes',
    ];

    protected $attributes = [
        'currency' => 'EUR',
        'status' => 'active',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date:Y-m-d',
            'purchase_amount' => 'decimal:2',
            'registration_expiry' => 'date:Y-m-d',
            'insurance_expiry' => 'date:Y-m-d',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function worksite(): BelongsTo
    {
        return $this->belongsTo(Worksite::class);
    }

    public function payableInvoice(): BelongsTo
    {
        return $this->belongsTo(PayableInvoice::class, 'payable_invoice_id');
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class, 'bank_transaction_id');
    }

    /** "Brand Model" when known, falling back to the equipment type. */
    protected function displayName(): Attribute
    {
        return Attribute::make(
            get: function (): string {
                $name = trim(implode(' ', array_filter([$this->brand, $this->model])));

                return $name === '' ? (string) $this->machine_type : $name;
            },
        );
    }

    /** Who it was bought from: the linked supplier, else the free-text seller. */
    protected function purchasedFrom(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->supplier?->name ?? $this->seller_name,
        );
    }

    public function scopeInService(Builder $query): Builder
    {
        return $query->whereIn('status', ['active', 'maintenance']);
    }
}
