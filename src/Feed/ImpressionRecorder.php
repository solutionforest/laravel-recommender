<?php

namespace SolutionForest\Recommender\Feed;

use Carbon\CarbonImmutable;
use SolutionForest\Recommender\Storage\ScoreStore;
use SolutionForest\Recommender\Support\FeedDefinition;
use SolutionForest\Recommender\Support\Report;
use Throwable;

/**
 * Counts served items as impressions ('impressions' => true). In HTTP the
 * write runs after the response; counting never breaks serving.
 */
final class ImpressionRecorder
{
    public function __construct(private readonly ScoreStore $scores)
    {
    }

    /**
     * @param  list<int|string>  $ids
     */
    public function record(FeedDefinition $feed, array $ids): void
    {
        if ($ids === [] || ! $feed->get('impressions')) {
            return;
        }

        $write = function () use ($feed, $ids): void {
            try {
                $this->scores->addImpressions($feed->name, $ids, CarbonImmutable::now());
            } catch (Throwable $e) {
                Report::failure($feed, 'impressions', $e);
            }
        };

        if (! app()->runningInConsole() && $feed->get('impressions_after_response', true)) {
            app()->terminating($write);

            return;
        }

        $write();
    }
}
