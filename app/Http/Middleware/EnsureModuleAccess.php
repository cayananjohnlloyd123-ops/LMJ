<?php

namespace App\Http\Middleware;

use App\Models\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces module-level 'view' access on dynamically-registered module
 * routes (see routes/web.php — Modules::query()->...->each(fn ($module) =>
 * Route::view(...)->name($module->route))).
 *
 * Route::view() routes have no controller to put an authorization check in,
 * so this middleware fills that gap: it resolves the current route's name
 * back to a Modules row (route name === $module->route, per how the routes
 * were registered) and checks the requesting user's 'view' permission on it.
 *
 * Register in bootstrap/app.php's withMiddleware(), and attach via the
 * 'module.access' alias on the dynamic module routes (see web.php diff).
 * Stacks with 'auth' + 'verified', which dynamic module routes already have
 * — this middleware assumes a user is already authenticated by the time it
 * runs, and does not itself handle the unauthenticated case.
 */
class EnsureModuleAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeName = $request->route()?->getName();

        // No route name to match against a module — nothing to enforce,
        // let it through (e.g. '/', 'profile', and anything under
        // auth.php aren't dynamic module routes and never carry this
        // middleware in the first place, but this guards defensively).
        if (! $routeName) {
            return $next($request);
        }

        $module = Modules::where('route', $routeName)->first();

        // Route name doesn't correspond to a known module row — let it
        // through rather than block; this middleware's job is to enforce
        // *module* permissions, not to validate that modules exist.
        if (! $module) {
            return $next($request);
        }

        $user = $request->user();

        abort_unless(
            $user && $user->canAccess($module->slug, 'view'),
            403,
            "You don't have access to this page."
        );

        return $next($request);
    }
}