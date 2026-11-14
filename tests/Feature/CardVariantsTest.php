<?php

use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Support\FeedDefinition;

it('keeps at most card_variants_max variants per card, dropping the oldest', function () {
    config(['recommender.cache_store' => 'array']);
    $cache = app(FeedCache::class);
    $feed = FeedDefinition::make('posts', [], ['card_variants_max' => 3, 'cards_vary_by' => ['locale']]);

    foreach (['a', 'b', 'c', 'd', 'e'] as $locale) {
        $cache->cards($feed, [1], fn (array $ids) => [1 => ['id' => 1, 'locale' => $locale]], "locale={$locale}");
    }
    $entry = cache()->store('array')->get($cache->key($feed, 'card:1'));

    expect(array_keys($entry['__variants']))->toBe(['locale=c', 'locale=d', 'locale=e']);

    // A cached variant is still served without rendering.
    $rendered = false;
    $card = $cache->cards($feed, [1], function () use (&$rendered) { $rendered = true; return []; }, 'locale=e');
    expect($rendered)->toBeFalse()->and($card[1]['locale'])->toBe('e');
});
