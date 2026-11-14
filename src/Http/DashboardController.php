<?php

namespace SolutionForest\Recommender\Http;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use SolutionForest\Recommender\Dashboard\Health;
use SolutionForest\Recommender\Recommender;
use SolutionForest\Recommender\Support\Value;
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
                'stages' => Health::stages($checks),
                'trending' => $health->trending($definition),
                'interactions' => $this->describeInteractions($definition->get('interactions')),
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

        // One colour per source / booster across the explain bars and legend.
        $legend = [];
        foreach ($explain['rows'] ?? [] as $row) {
            foreach (array_keys($row['contributions']) as $source) {
                $legend[$source] ??= count($legend) % 6;
            }
        }

        return $views->file(dirname(__DIR__, 2).'/resources/views/dashboard.blade.php', [
            'overview' => $overview,
            'feeds' => $feeds,
            'selected' => $selected,
            'explain' => $explain,
            'legend' => $legend,
            'maxScore' => max([0.0001, ...array_column($explain['rows'] ?? [], 'score')]),
            // Explain runs a full build: never repeat it on a timer.
            'refresh' => $explain === null ? max(0, Value::int(config('recommender.dashboard.refresh', 10))) : 0,
        ]);
    }

    private function describeInteractions(mixed $binding): string
    {
        if (is_array($binding)) {
            return count($binding).' '.(count($binding) === 1 ? 'table' : 'tables');
        }

        return is_string($binding) && $binding !== '' ? class_basename($binding) : 'not configured';
    }
}
