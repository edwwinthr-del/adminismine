<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceivableDeduction extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'odbici_izlaznih_faktura';

    protected $fillable = [
        'receivable_invoice_id',
        'amount',
        'deduction_date',
        'reason',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'deduction_date' => 'date:Y-m-d',
            'amount' => 'decimal:2',
        ];
    }

    public function receivableInvoice(): BelongsTo
    {
        return $this->belongsTo(ReceivableInvoice::class);
    }
}
