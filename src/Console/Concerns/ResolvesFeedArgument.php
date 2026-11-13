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

        // All feeds, minus those switched off with 'enabled' => false.
        // Naming a feed explicitly always runs it.
        return array_values(array_filter($recommender->feeds(), fn (string $name) => (bool) (config("recommender.feeds.{$name}.enabled") ?? true)));
    }
}
