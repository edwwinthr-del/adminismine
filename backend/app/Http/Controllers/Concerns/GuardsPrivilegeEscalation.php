<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

/**
 * Nobody may hand out access they do not themselves hold.
 *
 * `users.manage` and `roles.manage` are permissions to administer accounts, not
 * permissions to become anyone. Without this, holding either one was equivalent
 * to holding all thirty: a `roles.manage` holder could add every permission to
 * the role they were already wearing, and a `users.manage` holder could mint a
 * Super Admin (or reset the existing one's password) and sign in as it. The
 * self-edit and last-Super-Admin refusals in UserAccessController are real, but
 * they only guard three routes — these checks close the ways around them.
 *
 * The rule is a subset test against the actor's *effective* permissions, so a
 * role's contents are compared, never its name. The Super Admin role is the one
 * exception that a permission list cannot express: it carries the Gate::before
 * bypass (see AppServiceProvider), which is not a permission and therefore never
 * appears in anyone's set. Only a Super Admin may pass it on.
 */
trait GuardsPrivilegeEscalation
{
    /**
     * Refuse roles or permissions the actor does not hold.
     *
     * @param  array<int, string>  $roles
     * @param  array<int, string>  $permissions
     */
    protected function refuseUngrantableAccess(?User $actor, array $roles = [], array $permissions = []): ?JsonResponse
    {
        if ($actor === null) {
            return null;
        }

        // The bypass cannot be reasoned about as a permission set, so it is
        // gated on identity: only a Super Admin creates another one.
        if (in_array(User::SUPER_ADMIN, $roles, true) && ! $actor->isSuperAdmin()) {
            return $this->refuseEscalation('Only a Super Admin may grant the Super Admin role.');
        }

        // A Super Admin holds everything there is; the subset test below would
        // pass for them anyway, but Gate::before makes it a pointless round trip.
        if ($actor->isSuperAdmin()) {
            return null;
        }

        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                return $this->refuseEscalation("You cannot grant a permission you do not hold: {$permission}.");
            }
        }

        foreach ($this->permissionsCarriedBy($roles) as $roleName => $carried) {
            foreach ($carried as $permission) {
                if (! $actor->can($permission)) {
                    return $this->refuseEscalation(
                        "You cannot grant the {$roleName} role: it carries {$permission}, which you do not hold."
                    );
                }
            }
        }

        return null;
    }

    /**
     * Refuse acting on an account that holds access the actor does not.
     *
     * Used by the administrative password reset: resetting a password is taking
     * the account over, so it may only ever point downwards.
     */
    protected function refuseManagingMorePrivilegedUser(?User $actor, User $target): ?JsonResponse
    {
        if ($actor === null || $actor->isSuperAdmin()) {
            return null;
        }

        if ($target->isSuperAdmin()) {
            return $this->refuseEscalation('Only a Super Admin may do this to a Super Admin account.');
        }

        foreach ($target->getAllPermissions()->pluck('name') as $permission) {
            if (! $actor->can($permission)) {
                return $this->refuseEscalation(
                    'You cannot administer an account that holds access you do not.'
                );
            }
        }

        return null;
    }

    /**
     * Permission names each named role carries, keyed by role name.
     *
     * @param  array<int, string>  $roles
     * @return array<string, array<int, string>>
     */
    private function permissionsCarriedBy(array $roles): array
    {
        if ($roles === []) {
            return [];
        }

        return Role::query()
            ->with('permissions:id,name')
            ->whereIn('name', $roles)
            ->where('guard_name', 'web')
            ->get()
            ->mapWithKeys(fn (Role $role): array => [
                $role->name => $role->permissions->pluck('name')->all(),
            ])
            ->all();
    }

    private function refuseEscalation(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 403);
    }
}
