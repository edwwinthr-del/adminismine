<?php

namespace App\Http\Middleware;

use App\Support\CompanyConfig;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse a level of the work structure this company does not use.
 *
 * A construction firm organises work project → site; there is nothing above the
 * project, so `/mines` is not a screen it has. 404 for the same reason a
 * disabled module gives one: it does not exist here.
 *
 * This is *display* depth, not schema depth. All three levels stay in the
 * database whatever a company uses — both parent keys are nullable — so nothing
 * about an existing record changes when a level is hidden, and switching it back
 * on finds every row where it was left.
 */
class EnsureStructureLevelEnabled
{
    public function handle(Request $request, Closure $next, string $level): Response
    {
        abort_unless(app(CompanyConfig::class)->structureLevelEnabled($level), 404);

        return $next($request);
    }
}
