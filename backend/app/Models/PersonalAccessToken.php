<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token row, moved to `sistem.pristupni_tokeni`.
 *
 * Sanctum resolves the table from the model rather than from config, so the
 * rename needs a subclass registered with `Sanctum::usePersonalAccessTokenModel()`
 * in AppServiceProvider.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $table = 'pristupni_tokeni';
}
