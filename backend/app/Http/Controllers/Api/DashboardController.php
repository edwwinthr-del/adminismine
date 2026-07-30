<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One call for the whole landing screen. Sections the user has no permission
 * for are simply absent from the payload rather than zeroed.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, DashboardService $dashboard): JsonResponse
    {
        $request->validate([
            'month' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}(-\d{2})?$/'],
        ]);

        return response()->json([
            'data' => $dashboard->forUser($request->user(), $request->input('month')),
        ]);
    }
}
