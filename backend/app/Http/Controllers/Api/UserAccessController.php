<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\ConfirmsPassword;
use App\Http\Controllers\Concerns\GuardsPrivilegeEscalation;
use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserPasswordRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

class UserAccessController extends Controller
{
    use ConfirmsPassword;
    use GuardsPrivilegeEscalation;

    /** Logins with their roles — used by pickers (e.g. linking a master to a login). */
    public function index(Request $request): JsonResponse
    {
        // `permissions` is the direct grants relation: the access screen has to
        // open on what a user already holds, or saving would silently revoke the
        // extra permissions it never showed.
        $query = User::query()->with(['roles', 'permissions']);

        $query->search($request->input('search'));
        if ($request->boolean('active_only')) {
            $query->where('is_active', true);
        }

        $users = $query->orderBy('name')->limit(200)->get();

        return response()->json([
            'data' => $users->map(fn (User $user): array => $this->row($user)),
        ]);
    }

    /**
     * Accounts are created here, never by self-registration: this is a
     * single-company internal app, and every financial row is stamped with its
     * author, so a login has to be granted rather than claimed.
     */
    public function store(StoreUserRequest $request): JsonResponse
    {
        $roles = $request->input('roles', []);
        // The spec's "one role plus additional permission parameters": a role
        // carries the defaults, and anything this particular person needs on top
        // is granted directly. Both are settled while the account is created, so
        // a new login does not have to be opened a second time to be usable.
        $extraPermissions = $request->input('permissions', []);

        // Creating an account is the widest of the grant routes: it sets roles,
        // grants and the password in one call, and the self-edit refusal does
        // not apply because the target is not yet anybody. Without this check it
        // was the way around every other guard in this file.
        if ($refusal = $this->refuseUngrantableAccess($request->user(), $roles, $extraPermissions)) {
            return $refusal;
        }

        $user = DB::transaction(function () use ($request, $roles, $extraPermissions): User {
            $user = User::create([
                'name' => $request->input('name'),
                'email' => $request->input('email'),
                // The `hashed` cast on the model does the hashing.
                'password' => $request->input('password'),
                'locale' => $request->input('locale', 'en'),
                'is_active' => $request->boolean('is_active', true),
                // Who granted the login — what makes "accounts you created"
                // answerable from the record rather than from memory.
                'created_by' => $request->user()?->getKey(),
            ]);

            $user->syncRoles($roles);
            $user->syncPermissions($extraPermissions);

            return $user;
        });

        // The password is never logged, here or anywhere else.
        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['email' => $user->email, 'roles' => $roles, 'permissions' => $extraPermissions])
            ->log('user.created');

        return response()->json(['data' => $this->row($user->load(['roles', 'permissions']))], 201);
    }

    /**
     * Delete a login a Super Admin granted.
     *
     * The app's default is still deactivation — a disabled account keeps the
     * created_by/updated_by stamps and the audit rows that point at it, which is
     * why `update` exists and why this route refuses far more than it accepts.
     * Deletion is allowed only for the narrow case it was asked for: a Super
     * Admin removing an account they created themselves, typically one made in
     * error. Everything is checked here rather than in the UI:
     *
     * - only a Super Admin may call it at all;
     * - only accounts stamped with that Super Admin's own id (an account created
     *   before the stamp existed, or by someone else, cannot be deleted);
     * - never your own account, and never the last active Super Admin;
     * - and only behind a re-entered password.
     *
     * The audit row keeps the deleted account's name and email, because the
     * user rows that referenced it will be nulled by the FK and would otherwise
     * leave the trail pointing at nobody.
     */
    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->confirmPassword($request);

        $actor = $request->user();

        if (! $actor?->isSuperAdmin()) {
            return response()->json(['message' => 'Only a Super Admin may delete a login.'], 403);
        }

        if ($actor->is($user)) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }

        if ((int) $user->created_by !== (int) $actor->getKey()) {
            return response()->json([
                'message' => 'You can only delete accounts you created. Deactivate this one instead.',
            ], 403);
        }

        if ($this->isLastActiveSuperAdmin($user)) {
            return response()->json(['message' => 'At least one active Super Admin must remain.'], 422);
        }

        $identity = ['id' => $user->id, 'name' => $user->name, 'email' => $user->email];

        activity()->performedOn($user)->causedBy($actor)
            ->withProperties(['deleted' => $identity])
            ->log('user.deleted');

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->syncRoles([]);
            $user->syncPermissions([]);
            $user->delete();
        });

        return response()->json(['message' => 'User deleted.']);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        if ($request->has('is_active') && ! $request->boolean('is_active')) {
            if ($refusal = $this->refuseLockout($request, $user)) {
                return $refusal;
            }
        }

        $user->fill($request->only(['name', 'email', 'locale']));
        if ($request->has('is_active')) {
            $user->is_active = $request->boolean('is_active');
        }
        $user->save();

        // Cut live sessions immediately. EnsureUserIsActive would catch them on
        // the next request anyway; deleting the tokens here means there is no
        // next request to catch.
        if ($user->wasChanged('is_active') && ! $user->is_active) {
            $user->tokens()->delete();
        }

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['changes' => $user->getChanges()])
            ->log('user.updated');

        return response()->json(['data' => $this->row($user->load(['roles', 'permissions']))]);
    }

    /**
     * Administrative password reset — for the person who has lost access and
     * cannot ask for a link, because there is no mail transport.
     */
    public function updatePassword(UpdateUserPasswordRequest $request, User $user): JsonResponse
    {
        // Setting someone's password is taking their account over, so it may
        // only ever point downwards. Otherwise `users.manage` alone was enough
        // to reset the Super Admin's password and sign in as them, which walks
        // straight past the self-edit and last-Super-Admin refusals below.
        if ($refusal = $this->refuseManagingMorePrivilegedUser($request->user(), $user)) {
            return $refusal;
        }

        $user->forceFill(['password' => $request->input('password')])->save();

        // Tokens issued under the old password must not outlive it. The actor's
        // own token survives, so resetting your own password is not a logout.
        $current = $request->user()?->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;
        $user->tokens()->when($currentId, fn ($query) => $query->whereKeyNot($currentId))->delete();

        activity()->performedOn($user)->causedBy($request->user())->log('user.password_reset');

        return response()->json(['message' => 'Password updated.']);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:uloge,name'],
        ]);

        $roles = $data['roles'] ?? [];

        if ($refusal = $this->refuseLockout($request, $user, keepsSuperAdmin: in_array(User::SUPER_ADMIN, $roles, true))) {
            return $refusal;
        }

        if ($refusal = $this->refuseUngrantableAccess($request->user(), $roles)) {
            return $refusal;
        }

        $user->syncRoles($roles);

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['roles' => $roles])
            ->log('user.roles_synced');

        return response()->json(['data' => $this->accessPayload($user)]);
    }

    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:dozvole,name'],
        ]);

        // A grant held directly (rather than through a role) can be the only
        // thing giving someone this screen, so the self rule applies here too.
        if ($refusal = $this->refuseSelfEdit($request, $user)) {
            return $refusal;
        }

        if ($refusal = $this->refuseUngrantableAccess($request->user(), permissions: $data['permissions'] ?? [])) {
            return $refusal;
        }

        $user->syncPermissions($data['permissions'] ?? []);

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['permissions' => $data['permissions'] ?? []])
            ->log('user.permissions_synced');

        return response()->json(['data' => $this->accessPayload($user)]);
    }

    /**
     * The two ways to lock everyone out of the app: cut your own access (once
     * the screen is gone you cannot restore it), or take Super Admin from the
     * last person still holding it. Both are refused rather than warned about.
     *
     * @param  bool  $keepsSuperAdmin  whether the change leaves the target holding the role
     */
    private function refuseLockout(Request $request, User $target, bool $keepsSuperAdmin = false): ?JsonResponse
    {
        if ($refusal = $this->refuseSelfEdit($request, $target)) {
            return $refusal;
        }

        if (! $keepsSuperAdmin && $this->isLastActiveSuperAdmin($target)) {
            return response()->json([
                'message' => 'At least one active Super Admin must remain.',
            ], 422);
        }

        return null;
    }

    /** Changing your own roles, grants or active flag is always someone else's job. */
    private function refuseSelfEdit(Request $request, User $target): ?JsonResponse
    {
        if ($request->user()?->is($target)) {
            return response()->json([
                'message' => 'You cannot change your own access. Ask another administrator.',
            ], 422);
        }

        return null;
    }

    private function isLastActiveSuperAdmin(User $user): bool
    {
        if (! $user->hasRole(User::SUPER_ADMIN)) {
            return false;
        }

        return User::role(User::SUPER_ADMIN)
            ->where('is_active', true)
            ->whereKeyNot($user->getKey())
            ->doesntExist();
    }

    private function row(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'is_active' => $user->is_active,
            // Who granted the login. The Users screen only offers Delete for
            // accounts the signed-in Super Admin created; the API enforces the
            // same rule in destroy(), so hiding the button is a convenience and
            // never the check itself.
            'created_by' => $user->created_by,
            'roles' => $user->getRoleNames()->values(),
            'direct_permissions' => $user->getDirectPermissions()->pluck('name')->values(),
        ];
    }

    private function accessPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'roles' => $user->getRoleNames()->values(),
            'direct_permissions' => $user->getDirectPermissions()->pluck('name')->values(),
            'permissions' => $user->getAllPermissions()->pluck('name')->values(),
        ];
    }
}
