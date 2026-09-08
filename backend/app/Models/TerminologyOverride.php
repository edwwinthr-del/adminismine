<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One term, in one language, as this company says it.
 *
 * The absence of a row is the built-in wording — removing an override deletes
 * the row rather than storing a blank, so there is one representation of "we
 * have not renamed this" instead of two.
 */
class TerminologyOverride extends Model
{
    use HasAuditColumns, HasFactory;

    protected $table = 'prevodi_pojmova';

    protected $fillable = ['key', 'locale', 'value', 'source', 'notes'];
}
