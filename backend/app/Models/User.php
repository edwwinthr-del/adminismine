<?php

namespace App\Models;

use App\Models\Concerns\Searchable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
