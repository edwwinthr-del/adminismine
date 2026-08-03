<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * Re-authentication in front of a destructive action.
 *
 * A delete is the one operation this app cannot undo from the UI — a payable, a
 * bank movement or a login is gone, and only the audit row remains. So the
 * person at the keyboard is asked to prove they are still the account holder,
 * every single time: an unattended session cannot be used to erase records.
 *
 * The check is deliberately part of the destructive request itself rather than a
 * separate "confirm" endpoint that sets a flag. A flag can be obtained once and
 * spent on something else; a password carried on the DELETE can only authorise
 * the DELETE it arrives with.
 *
 * `Hash::check` against the authenticated user is used rather than the
 * `current_password` validation rule, because that rule resolves the account
 * through `Auth::guard()` — the same guard indirection that made the roles list
 * fail (see RoleController::holderCounts).
 */
trait ConfirmsPassword
{
    /**
     * @throws ValidationException when the password is missing or wrong
     */
    protected function confirmPassword(Request $request): void
    {
        $request->validate([
            'current_password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! $user || ! Hash::check($request->input('current_password'), $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => [__('The password is incorrect.')],
            ]);
        }
    }
}
