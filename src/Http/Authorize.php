<?php

namespace SolutionForest\Recommender\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Same rule as Horizon / Telescope: open in the `local` environment,
 * otherwise the `viewRecommender` gate decides.
 *
 *     Gate::define('viewRecommender', fn ($user) => $user->is_admin);
 */
final class Authorize
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->environment('local') || Gate::allows('viewRecommender', [$request->user()])) {
            return $next($request);
        }

        abort(403);
    }
}
