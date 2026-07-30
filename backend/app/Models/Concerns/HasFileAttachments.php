<?php

namespace App\Models\Concerns;

use App\Models\FileAttachment;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model a set of uploaded files (invoices, warranties, customs papers,
 * photos). Pair with FileAttachmentService for storing and removing the files
 * themselves, and register the model in FileAttachment::ROUTE_SEGMENTS so its
 * download URLs can be built.
 */
trait HasFileAttachments
{
    public function attachments(): MorphMany
    {
        return $this->morphMany(FileAttachment::class, 'attachable')->orderByDesc('id');
    }
}
