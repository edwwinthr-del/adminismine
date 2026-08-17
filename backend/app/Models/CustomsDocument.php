<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\HasFileAttachments;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Customs and transport papers for goods and shipments. The CMR consignment note
 * is the main one: it proves the carriage contract and travels with the goods,
 * which is why its number and date are first-class fields.
 */
class CustomsDocument extends Model
{
    use HasAuditColumns, HasFactory, HasFileAttachments, Searchable;

    protected $table = 'carinski_dokumenti';

    public const TYPES = [
        'cmr',
        'customs_declaration',
        'import_export',
        'delivery_note',
        'packing_list',
        'certificate_of_origin',
        'goods_invoice',
        'other',
    ];

    public const STATUSES = ['draft', 'received', 'checked', 'missing', 'completed', 'archived'];

    /** Statuses that still need someone to act on the paperwork. */
    public const OPEN_STATUSES = ['draft', 'received', 'missing'];

    /** @var list<string> */
    protected array $searchable = [
        'document_number', 'cmr_number', 'carrier_name', 'sender', 'receiver',
        'vehicle_plate', 'driver_name', 'goods_description', 'customs_company_name',
        'customs_invoice_number',
    ];

    /** @var array<string, list<string>> */
    protected array $searchableRelations = [
        'customsCompany' => ['name'],
        'machine' => ['serial_number', 'brand', 'model'],
    ];

    protected $fillable = [
        'document_type',
        'document_number',
        'cmr_number',
        'issue_date',
        'cmr_date',
        'shipment_date',
        'customs_company_id',
        'customs_company_name',
        'customs_invoice_number',
        'sender',
        'receiver',
        'carrier_name',
        'vehicle_plate',
        'driver_name',
        'goods_description',
        'quantity',
        'unit',
        'origin_place',
        'destination_place',
        'machine_id',
        'payable_invoice_id',
        'receivable_invoice_id',
        'client_id',
        'supplier_id',
        'production_record_id',
        'status',
        'source',
        'notes',
    ];

    protected $attributes = [
        'document_type' => 'cmr',
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date:Y-m-d',
            'cmr_date' => 'date:Y-m-d',
            'shipment_date' => 'date:Y-m-d',
            'quantity' => 'decimal:3',
        ];
    }

    public function customsCompany(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'customs_company_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function payableInvoice(): BelongsTo
    {
        return $this->belongsTo(PayableInvoice::class, 'payable_invoice_id');
    }

    public function receivableInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivableInvoice::class, 'receivable_invoice_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function productionRecord(): BelongsTo
    {
        return $this->belongsTo(ProductionRecord::class);
    }

    /** The customs agency: the linked supplier when known, else the free-text name. */
    protected function customsCompanyLabel(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->customsCompany?->name ?? $this->customs_company_name,
        );
    }

    /**
     * A document is only complete once the scan is on file — "flag missing
     * documents" means both the explicit `missing` status and papers that were
     * never uploaded.
     */
    protected function hasScan(): Attribute
    {
        return Attribute::make(
            get: function (): bool {
                // The list eager-loads the count, so answer from that rather
                // than running an `exists()` for every row on the page.
                if ($this->attachments_count !== null) {
                    return $this->attachments_count > 0;
                }

                if ($this->relationLoaded('attachments')) {
                    return $this->attachments->isNotEmpty();
                }

                return $this->attachments()->exists();
            },
        );
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    /** Flagged missing, or checked/completed on paper but with no scan attached. */
    public function scopeMissingPaperwork(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->where('status', 'missing')
                ->orWhereDoesntHave('attachments');
        });
    }

    public function scopeForMachine(Builder $query, int $machineId): Builder
    {
        return $query->where('machine_id', $machineId);
    }
}
