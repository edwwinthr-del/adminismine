<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Model;

class CompanySettings extends Model
{
    use HasAuditColumns;

    protected $table = 'podesavanja_kompanije';

    /**
     * Model-level defaults mirror the migration's column defaults so a freshly
     * created settings row is populated in memory too (not just in the DB).
     */
    protected $attributes = [
        'company_name' => 'AdminisMine DOO',
        'base_currency' => 'EUR',
        'default_locale' => 'en',
        'timezone' => 'Europe/Podgorica',
    ];

    protected $fillable = [
        'company_name',
        'base_currency',
        'default_locale',
        'timezone',
        'tax_number',
        'address',
        'phone',
        'email',
        'logo_path',
        'source',
        'notes',
    ];

    /**
     * The app is single-company: there is always exactly one settings row.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([]);
    }
}
