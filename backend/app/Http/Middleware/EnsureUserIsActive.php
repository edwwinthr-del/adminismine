<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Deactivating an account has to end access *now*, not at the next sign-in.
 * Login checks `is_active`, but a token issued earlier would otherwise keep
 * working for as long as it exists — so every authenticated request re-checks.
 *
 * The status is 401 rather than 403 on purpose: the frontend clears its stored
 * token on a 401, which returns the person to the login screen instead of
 * leaving them clicking around an app that refuses every call.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            $user->tokens()->delete();

            return response()->json(['message' => 'This account is inactive.'], 401);
        }

        return $next($request);
    }
}
