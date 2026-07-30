<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Role\StoreRoleRequest;
use App\Http\Requests\Role\UpdateRoleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with('permissions:id,name')
            ->withCount('users')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => $this->payload($role));

        return response()->json(['data' => $roles]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = Role::create(['name' => $request->input('name'), 'guard_name' => 'web']);
        $role->syncPermissions($request->input('permissions', []));

        activity()
            ->performedOn($role)
            ->causedBy($request->user())
            ->withProperties(['permissions' => $request->input('permissions', [])])
            ->log('role.created');

        return response()->json(['data' => $this->payload($role->load('permissions'))], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json(['data' => $this->payload($role->load('permissions')->loadCount('users'))]);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        if ($role->is_system && $request->filled('name') && $request->input('name') !== $role->name) {
            return response()->json(['message' => 'System roles cannot be renamed.'], 422);
        }

        if ($request->filled('name')) {
            $role->update(['name' => $request->input('name')]);
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->input('permissions', []));
        }

        activity()->performedOn($role)->causedBy($request->user())->log('role.updated');

        return response()->json(['data' => $this->payload($role->load('permissions'))]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        if ($role->is_system) {
            return response()->json(['message' => 'System roles cannot be deleted.'], 403);
        }

        if ($role->users()->count() > 0) {
            return response()->json(['message' => 'Reassign users before deleting this role.'], 422);
        }

        activity()->performedOn($role)->causedBy($request->user())->log('role.deleted');
        $role->delete();

        return response()->json(['message' => 'Role deleted.']);
    }

    public function clone(Request $request, Role $role): JsonResponse
    {
        $request->validate(['name' => ['required', 'string', 'max:255', 'unique:roles,name']]);

        $clone = Role::create(['name' => $request->input('name'), 'guard_name' => 'web']);
        $clone->syncPermissions($role->permissions);

        activity()
            ->performedOn($clone)
            ->causedBy($request->user())
            ->withProperties(['cloned_from' => $role->name])
            ->log('role.cloned');

        return response()->json(['data' => $this->payload($clone->load('permissions'))], 201);
    }

    private function payload(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'is_system' => (bool) $role->is_system,
            'users_count' => $role->users_count ?? $role->users()->count(),
            'permissions' => $role->permissions->pluck('name')->values(),
        ];
    }
}
