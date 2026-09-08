<?php

use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureStructureLevelEnabled;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // `module:housing` on a route group refuses it with a 404 where the
        // company does not have that module. It rides alongside `can:`, never
        // instead of it.
        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
            // `structure:mine` on the top level of the work hierarchy, for the
            // companies that organise work project → site and have nothing above.
            'structure' => EnsureStructureLevelEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
