<?php

use Illuminate\Support\Facades\Bus;
use SolutionForest\Recommender\Facades\Recommender;
use SolutionForest\Recommender\Feed\FeedCache;
use SolutionForest\Recommender\Jobs\BuildFeed;
use SolutionForest\Recommender\Tests\Fixtures\StaticSource;

/** A source whose candidates depend on the `tenant` attribute. */
class TenantSource implements SolutionForest\Recommender\Contracts\CandidateSource
{
    public function candidates(SolutionForest\Recommender\Support\Context $context, int $limit): array
    {
        $base = $context->attribute('tenant') === 'B' ? 40 : 10;

        return [$base + 1 => 9.0, $base + 2 => 8.0, $base + 3 => 7.0, $base + 4 => 6.0];
    }
}

beforeEach(function () {
    StaticSource::reset();
    $this->configureFeed([
        'sources' => [TenantSource::class => 1.0],
        'trending_sources' => [TenantSource::class => 1.0],
        'list_vary_by' => ['tenant'],
        'filter' => null,
    ]);
});

it('serves one user only the items of the tenant they are in', function () {
    $a = Recommender::page('reviews', 7, 1, 3, ['tenant' => 'A']);
    $b = Recommender::page('reviews', 7, 1, 3, ['tenant' => 'B']);

    expect($a->ids)->toBe([11, 12, 13])
        ->and($b->ids)->toBe([41, 42, 43]);

    // Back to A: its own list and its own seen-set continue where they left off.
    expect(Recommender::page('reviews', 7, 1, 3, ['tenant' => 'A'])->ids)->toBe([14, 11, 12]);
});

it('keeps guest trending lists apart per tenant', function () {
    expect(Recommender::page('reviews', null, 1, 3, ['tenant' => 'A'])->ids)->toBe([11, 12, 13])
        ->and(Recommender::page('reviews', null, 1, 3, ['tenant' => 'B'])->ids)->toBe([41, 42, 43])
        ->and(Recommender::trending('reviews', ['tenant' => 'B']))->toBe([41, 42, 43, 44]);
});

it('explains what that tenant sees', function () {
    $rows = Recommender::explain('reviews', 7, 5, ['tenant' => 'B']);

    expect(array_column($rows, 'id'))->toBe([41, 42, 43, 44]);
});

it('forgets one tenant variant at a time', function () {
    Recommender::page('reviews', 7, 1, 3, ['tenant' => 'A']);
    Recommender::page('reviews', 7, 1, 3, ['tenant' => 'B']);

    Recommender::forgetUser('reviews', 7, ['tenant' => 'A']);

    expect(Recommender::page('reviews', 7, 1, 3, ['tenant' => 'A'])->ids)->toBe([11, 12, 13])
        ->and(Recommender::page('reviews', 7, 1, 3, ['tenant' => 'B'])->ids)->toBe([44, 41, 42]);
});

it('warms the variant for the given attributes and keeps jobs unique per variant', function () {
    Bus::fake();
    Recommender::warm('reviews', 7, ['tenant' => 'B']);
    Bus::assertDispatched(BuildFeed::class, fn (BuildFeed $job) => $job->attributes === ['tenant' => 'B']);

    $a = new BuildFeed('reviews', 7, ['tenant' => 'A']);
    $b = new BuildFeed('reviews', 7, ['tenant' => 'B']);
    expect($a->uniqueId())->not->toBe($b->uniqueId());

    $b->handle(app(SolutionForest\Recommender\Recommender::class));
    $feed = Recommender::feed('reviews');
    $cache = app(FeedCache::class);
    expect($cache->list($feed, 7, Recommender::listVariant($feed, ['tenant' => 'B']))['ids'])->toBe([41, 42, 43, 44])
        ->and($cache->list($feed, 7, Recommender::listVariant($feed, ['tenant' => 'A'])))->toBeNull();
});

it('leaves cache keys unchanged for feeds without list_vary_by', function () {
    $this->configureFeed(['list_vary_by' => []]);
    $feed = Recommender::feed('reviews');

    expect(Recommender::listVariant($feed, ['tenant' => 'A']))->toBeNull();
    Recommender::page('reviews', 7, 1, 3, ['tenant' => 'A']);
    $cache = app(FeedCache::class);
    expect($cache->list($feed, 7))->not->toBeNull()
        ->and($cache->key($feed, 'list:7'))->toBe('rec:reviews:list:7');
});

it('shares the seen-set across devices but not across tenants by default', function () {
    $this->configureFeed(['list_vary_by' => ['tenant', 'device']]);

    Recommender::page('reviews', 7, 1, 2, ['tenant' => 'A', 'device' => 'phone']);
    $laptop = Recommender::page('reviews', 7, 1, 2, ['tenant' => 'A', 'device' => 'laptop']);

    // Same tenant, other device: its own list, but items already served on the phone are skipped.
    expect($laptop->ids)->toBe([13, 14]);
});
