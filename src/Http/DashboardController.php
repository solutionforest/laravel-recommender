<?php

namespace SolutionForest\Recommender\Http;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Recommender;
use Throwable;

/**
 * The single dashboard page: overview, per-feed health and checks, the
 * trending list and an "explain this user" lookup. Read-only.
 */
final class DashboardController
{
    public function __invoke(Request $request, Recommender $recommender, Health $health, Factory $views): View
    {
        $overview = $health->overview();
        $feeds = [];
        foreach ($overview['feeds'] as $name) {
            $definition = $recommender->feed($name);
            $checks = $health->checks($definition);
            $feeds[$name] = [
                'metrics' => $health->metrics($definition),
                'checks' => $checks,
                'status' => Health::worst($checks),
                'trending' => $health->trending($definition),
            ];
        }

        $selected = $request->query('feed');
        $selected = is_string($selected) && isset($feeds[$selected]) ? $selected : array_key_first($feeds);

        $explain = null;
        $user = $request->query('user');
        if ($selected !== null && is_string($user) && $user !== '') {
            try {
                $explain = ['user' => $user, 'rows' => $recommender->explain($selected, ctype_digit($user) ? (int) $user : $user, 20), 'error' => null];
            } catch (Throwable $e) {
                $explain = ['user' => $user, 'rows' => [], 'error' => $e->getMessage()];
            }
        }

        return $views->file(dirname(__DIR__, 2).'/resources/views/dashboard.blade.php', [
            'overview' => $overview,
            'feeds' => $feeds,
            'selected' => $selected,
            'explain' => $explain,
            // Explain runs a full build: never repeat it on a timer.
            'refresh' => $explain === null ? max(0, (int) config('recommender.dashboard.refresh', 10)) : 0,
        ]);
    }
}
