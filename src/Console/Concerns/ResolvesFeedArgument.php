<?php

namespace SolutionForest\Recommender\Console\Concerns;

use SolutionForest\Recommender\Recommender;

trait ResolvesFeedArgument
{
    /**
     * @return list<string>
     */
    private function feedNames(Recommender $recommender): array
    {
        $feed = $this->argument('feed');

        if (is_string($feed) && $feed !== '') {
            return [$feed];
        }

        return $recommender->feeds();
    }
}
