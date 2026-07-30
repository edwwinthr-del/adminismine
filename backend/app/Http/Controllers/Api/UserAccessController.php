<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
            'data' => $users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_active' => $user->is_active,
                'roles' => $user->getRoleNames()->values(),
                'direct_permissions' => $user->getDirectPermissions()->pluck('name')->values(),
            ]),
        ]);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'roles' => ['array'],
            'roles.*' => ['string', 'exists:roles,name'],
        ]);

        $user->syncRoles($data['roles'] ?? []);

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['roles' => $data['roles'] ?? []])
            ->log('user.roles_synced');

        return response()->json(['data' => $this->accessPayload($user)]);
    }

    public function syncPermissions(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        $user->syncPermissions($data['permissions'] ?? []);

        activity()
            ->performedOn($user)
            ->causedBy($request->user())
            ->withProperties(['permissions' => $data['permissions'] ?? []])
            ->log('user.permissions_synced');

        return response()->json(['data' => $this->accessPayload($user)]);
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
