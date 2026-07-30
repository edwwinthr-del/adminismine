<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\StoreUserRequest;
use App\Http\Requests\User\UpdateUserPasswordRequest;
use App\Http\Requests\User\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class UserAccessController extends Controller
{
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
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            // The `hashed` cast on the model does the hashing.
            'password' => $request->input('password'),
            'locale' => $request->input('locale', 'en'),
            'is_active' => $request->boolean('is_active', true),
        ]);

        $roles = $request->input('roles', []);
        $user->syncRoles($roles);

        // The password is never logged, here or anywhere else.
        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['email' => $user->email, 'roles' => $roles])
            ->log('user.created');

        return response()->json(['data' => $this->row($user->load(['roles', 'permissions']))], 201);
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
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $roles = $data['roles'] ?? [];

        if ($refusal = $this->refuseLockout($request, $user, keepsSuperAdmin: in_array(User::SUPER_ADMIN, $roles, true))) {
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
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        // A grant held directly (rather than through a role) can be the only
        // thing giving someone this screen, so the self rule applies here too.
        if ($refusal = $this->refuseSelfEdit($request, $user)) {
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
