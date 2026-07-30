<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Permission;

class PermissionController extends Controller
{
    public function index(): JsonResponse
    {
        $permissions = Permission::orderBy('name')->pluck('name');

        return response()->json([
            'data' => $permissions->values(),
            // Grouped by module prefix (e.g. "payables") for UI grouping.
            'grouped' => $permissions->groupBy(fn (string $name) => explode('.', $name)[0]),
        ]);
    }
}
