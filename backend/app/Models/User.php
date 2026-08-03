<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, Searchable;

    /** UI languages a login may be set to (rule 4). */
    public const LOCALES = ['en', 'sr', 'tr'];

    /** Protected core role: the app must always keep one active holder. */
    public const SUPER_ADMIN = 'Super Admin';

    /** @var list<string> */
    protected array $searchable = ['name', 'email'];

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'is_active',
        'created_by',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** The administrator who granted this login, if the account records one. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(self::class, 'created_by');
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(self::SUPER_ADMIN);
    }
}
