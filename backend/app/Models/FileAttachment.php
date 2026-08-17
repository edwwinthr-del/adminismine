<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A file belonging to any record that carries paperwork. Files live on the
 * private `local` disk and are served only through authenticated routes.
 */
class FileAttachment extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'prilozi';

    public const KINDS = ['invoice', 'warranty', 'customs', 'cmr', 'photo', 'other'];

    /** URL segment per attachable model, used to build the download path. */
    public const ROUTE_SEGMENTS = [
        Machine::class => 'machines',
        CustomsDocument::class => 'customs-documents',
        UtilityBill::class => 'housing/bills',
        FlightTicket::class => 'travel/tickets',
        TravelExpense::class => 'travel/expenses',
    ];

    protected $fillable = [
        'kind',
        'label',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'source',
        'notes',
    ];

    protected $attributes = [
        'kind' => 'other',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Path of the authenticated download route for this file, if its parent is routable. */
    public function downloadPath(): ?string
    {
        $segment = self::ROUTE_SEGMENTS[$this->attachable_type] ?? null;

        return $segment === null
            ? null
            : "/{$segment}/{$this->attachable_id}/attachments/{$this->id}";
    }
}
