<?php

use SolutionForest\Recommender\Boosters\QualityBooster;
use SolutionForest\Recommender\Recommender;

function feedWith(array $feed, array $extra = []): \SolutionForest\Recommender\Support\FeedDefinition
{
    config(['recommender.feeds' => ['shop' => $feed]] + $extra);
    app()->forgetInstance(Recommender::class);

    return app(Recommender::class)->feed('shop');
}

it('fills a feed from a named preset', function () {
    $feed = feedWith(['preset' => 'catalog']);

    expect($feed->int('half_life_days'))->toBe(14)
        ->and($feed->get('events'))->toHaveKey('purchase')
        ->and($feed->get('positive_events'))->toContain('purchase')
        ->and($feed->weighted('boosters'))->toBe([QualityBooster::class => 0.2]);
});

it('lets the feed override anything the preset sets', function () {
    $feed = feedWith(['preset' => 'social', 'half_life_days' => 1, 'exploration_ratio' => 0.0]);

    expect($feed->int('half_life_days'))->toBe(1)
        ->and($feed->float('exploration_ratio'))->toBe(0.0)
        ->and($feed->get('events'))->toHaveKey('share'); // still from the preset
});

it('supports app-defined presets alongside the built-in ones', function () {
    $feed = feedWith(['preset' => 'jobs'], ['recommender.presets' => ['jobs' => ['half_life_days' => 30, 'events' => ['view' => 1.0, 'apply' => 10.0]]]]);

    expect($feed->int('half_life_days'))->toBe(30)
        ->and(app(Recommender::class)->presets())->toHaveKeys(['catalog', 'social', 'events', 'jobs']);
});

it('names the available presets when one is unknown', function () {
    expect(fn () => feedWith(['preset' => 'shopz']))
        ->toThrow(InvalidArgumentException::class, 'unknown preset [shopz]. Available: catalog, social, events.');
});
