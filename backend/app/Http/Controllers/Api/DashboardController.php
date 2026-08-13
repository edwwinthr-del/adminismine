<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use App\Support\MonthPeriod;
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
            'month' => ['nullable', 'string', MonthPeriod::rule()],
        ]);

        return response()->json([
            'data' => $dashboard->forUser($request->user(), $request->input('month')),
        ]);
    }
}
