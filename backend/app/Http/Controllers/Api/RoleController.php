<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\GuardsPrivilegeEscalation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    use GuardsPrivilegeEscalation;

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get();

        // One grouped query for every role's headcount — see holderCounts().
        $counts = $this->holderCounts();

        $payload = $roles->map(fn (Role $role) => $this->payload($role, (int) $counts->get($role->id, 0)));

        return response()->json(['data' => $payload]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        // A role is a container for permissions; minting one you could not grant
        // directly would just be the same escalation with an extra step.
        if ($refusal = $this->refuseUngrantableAccess($request->user(), permissions: $request->input('permissions', []))) {
            return $refusal;
        }

        $role = Role::create(['name' => $request->input('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->input('permissions', []));

        activity()
            ->performedOn($role)
            ->causedBy($request->user())
            ->withProperties(['permissions' => $request->input('permissions', [])])
            ->log('role.created');

        return response()->json(['data' => $this->payload($role->load('permissions'), 0)], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $this->payload($role->load('permissions'), $this->holderCount($role))]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if ($role->is_system && $request->filled('name') && $request->input('name') !== $role->name) {
            return response()->json(['message' => 'System roles cannot be renamed.'], 422);
        }

        $actor = $request->user();

        // Editing the role you are wearing is editing yourself. `is_system`
        // blocked renaming but never the permission sync, so a `roles.manage`
        // holder could simply add the other twenty-nine permissions to their own
        // role — the widest escalation in the app, and invisible in the trail
        // because this event used to be logged with no properties.
        if ($actor !== null && ! $actor->isSuperAdmin() && $actor->hasRole($role->name)) {
            return response()->json([
                'message' => 'You cannot change the permissions of a role you hold. Ask another administrator.',
            ], 403);
        }

        if ($role->name === User::SUPER_ADMIN && ! $actor?->isSuperAdmin()) {
            return response()->json(['message' => 'Only a Super Admin may edit the Super Admin role.'], 403);
        }

        if ($request->has('permissions')) {
            if ($refusal = $this->refuseUngrantableAccess($actor, permissions: $request->input('permissions', []))) {
                return $refusal;
            }
        }

        $before = $role->permissions->pluck('name')->sort()->values()->all();

        if ($request->filled('name')) {
            $role->update(['name' => $request->input('name')]);
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->input('permissions', []));
        }

        $after = $role->load('permissions')->permissions->pluck('name')->sort()->values()->all();

        // Who gained which permission is the most security-relevant change the
        // app supports; a row saying only "somebody edited role #6" cannot
        // reconstruct an escalation after the fact (rule 3).
        activity()
            ->performedOn($role)
            ->causedBy($actor)
            ->withProperties([
                'permissions' => ['before' => $before, 'after' => $after],
                'granted' => array_values(array_diff($after, $before)),
                'revoked' => array_values(array_diff($before, $after)),
            ])
            ->log('role.updated');

        return response()->json(['data' => $this->payload($role->load('permissions'), $this->holderCount($role))]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 403);
        }

        if ($this->holderCount($role) > 0) {
            return response()->json(['message' => 'Reassign users before deleting this role.'], 422);
        }

        activity()->performedOn($role)->causedBy($request->user())->log('role.deleted');
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255', 'unique:roles,name']]);

        // Same rule as store(): copying a role carries its permissions with it.
        if ($refusal = $this->refuseUngrantableAccess(
            $request->user(),
            permissions: $role->permissions->pluck('name')->all()
        )) {
            return $refusal;
        }

        $clone = Role::create(['name' => $request->input('name'), 'guard_name' => 'web']);
        $clone->syncPermissions($role->permissions);

        activity()
            ->performedOn($clone)
            ->causedBy($request->user())
            ->withProperties(['cloned_from' => $role->name])
            ->log('role.cloned');

        return response()->json(['data' => $this->payload($clone->load('permissions'), 0)], 201);
    }

    /**
     * How many users hold each role, counted straight off the pivot table.
     *
     * Deliberately *not* `withCount('users')`. Spatie resolves that relation's
     * model from the role's own `guard_name`, and on the empty instance Eloquent
     * builds a subquery from there is no such attribute — so it falls back to
     * `config('auth.defaults.guard')`, which `auth:sanctum` has already switched
     * to `sanctum` for the rest of the request. This app defines no `sanctum`
     * guard, so the lookup returned null and the whole roles list died with
     * "Class name must be a valid object or a string". Counting the pivot rows
     * asks the question directly and cannot depend on guard configuration.
     *
     * @return Collection<int, int> role id => number of users
     */
    private function holderCounts(?int $roleId = null): Collection
    {
        $pivotRole = config('permission.column_names.role_pivot_key') ?? 'role_id';

        return DB::table(config('permission.table_names.model_has_roles'))
            ->where('model_type', (new User)->getMorphClass())
            ->when($roleId !== null, fn ($query) => $query->where($pivotRole, $roleId))
            ->groupBy($pivotRole)
            ->selectRaw("{$pivotRole} as role_id, COUNT(*) as holders")
            ->pluck('holders', 'role_id');
    }

    private function holderCount(Role $role): int
    {
        return (int) $this->holderCounts($role->id)->get($role->id, 0);
    }

    /** @return array<string, mixed> */
    private function payload(Role $role, int $usersCount): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'is_system' => (bool) $role->is_system,
            'users_count' => $usersCount,
            'permissions' => $role->permissions->pluck('name')->values(),
        ];
    }
}
