<?php

namespace SolutionForest\Recommender\Insights;

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Scoring\Math;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Support\FeedDefinition;

/**
 * "How good is this item?" from the counters update-scores keeps.
 */
final class ItemStats
{
    public function __construct(private readonly ScoreStore $scores)
    {
    }

    /**
     * @param  list<int|string>  $ids
     * @return array<int|string, array{positive: int, exposure: int, impressions: int, rate: ?float, quality: float, popularity: float, last_event_at: ?string}>
     */
    public function for(FeedDefinition $feed, array $ids, ?int $days = null): array
    {
        $found = $this->scores->rows($feed->name, $ids);
        $window = $days === null ? null : $this->scores->window($feed->name, $ids, $days, CarbonImmutable::now());
        $basis = $feed->get('impressions') ? 'impressions' : 'exposure';

        $out = [];
        foreach ($ids as $id) {
            $row = $found['rows'][$id] ?? ['hot' => null, 'positive' => 0, 'exposure' => 0, 'impressions' => 0, 'last_event_at' => null];
            $counts = $window === null ? $row : ($window[$id] ?? ['positive' => 0, 'exposure' => 0, 'impressions' => 0]);
            $out[$id] = [
                'positive' => $counts['positive'],
                'exposure' => $counts['exposure'],
                'impressions' => $counts['impressions'],
                // Same denominator as the Wilson score: never above 100% even
                // when likes outnumber recorded views.
                'rate' => $counts[$basis] > 0 ? round($counts['positive'] / max($counts[$basis], $counts['positive']), 4) : null,
                'quality' => round(Math::wilson($counts['positive'], $counts[$basis]), 4),
                'popularity' => $row['hot'] === null || $found['max_hot'] === null ? 0.0 : round(exp($row['hot'] - $found['max_hot']), 4),
                'last_event_at' => $row['last_event_at'],
            ];
        }

        return $out;
    }
}
