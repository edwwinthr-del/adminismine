<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use App\Models\Concerns\Searchable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasAuditColumns, HasFactory, Searchable;

    protected $table = 'klijenti';

    /** @var list<string> */
    protected array $searchable = ['name', 'tax_number', 'contact_name', 'email'];

    protected $fillable = [
        'name',
        'tax_number',
        'contact_name',
        'phone',
        'email',
        'address',
        'iban',
        'is_active',
        'source',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
