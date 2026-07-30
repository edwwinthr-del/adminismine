<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Auto-stamps created_by / updated_by from the authenticated user and exposes
 * the corresponding relations. Pair with Blueprint::auditColumns() in migrations.
 */
trait HasAuditColumns
{
    public static function bootHasAuditColumns(): void
    {
        static::creating(function ($model): void {
            $userId = Auth::id();

            if ($userId !== null) {
                if ($model->created_by === null) {
                    $model->created_by = $userId;
                }
                if ($model->updated_by === null) {
                    $model->updated_by = $userId;
                }
            }
        });

        static::updating(function ($model): void {
            $userId = Auth::id();

            if ($userId !== null) {
                $model->updated_by = $userId;
            }
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
