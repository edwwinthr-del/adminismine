<?php

namespace App\Http\Middleware;

use App\Support\CompanyConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a route belonging to a module this company does not have.
 *
 * **404, not 403.** A disabled module does not exist for this company, and 403
 * would answer a different question — it says "this exists and you may not have
 * it", which tells a customer what they are not buying and reads to their own
 * staff as a permission they should ask for. Being absent is the honest answer.
 *
 * It sits *alongside* the permission check, never instead of it (rule 6):
 * enabling a module grants nobody anything, and every route keeps the `can:`
 * middleware it always had.
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(app(CompanyConfig::class)->moduleEnabled($module), 404);

        return $next($request);
    }
}
