<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use SolutionForest\Recommender\Eloquent\Recommendable;
use SolutionForest\Recommender\Support\Context;

/**
 * @property int $id
 * @property int $host_id
 * @property int $district_id
 * @property int $type_id
 * @property string $title
 * @property bool $published
 * @property \Illuminate\Support\Carbon|null $starts_at
 */
class Event extends Model
{
    use Recommendable;

    protected $guarded = [];

    protected $casts = [
            'published' => 'boolean',
            'starts_at' => 'datetime',
    ];

    /** Visible to guests and members.
     *
     * @param  Builder<self>  $query
     * @return Builder<self> */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    /** Upcoming (or recently started / undated).
     *
     * @param  Builder<self>  $query
     * @return Builder<self> */
    public function scopeUpcoming(Builder $query, DateTimeInterface $now): Builder
    {
        $cutoff = CarbonImmutable::parse($now)->subDay();

        return $query->where(function (Builder $q) use ($cutoff) {
            $q->whereNull('starts_at')->orWhere('starts_at', '>=', $cutoff);
        });
    }

    /** Hide the viewer’s own hosted events.
     *
     * @param  Builder<self>  $query
     * @return Builder<self> */
    public function scopeNotHostedBy(Builder $query, int|string|null $userId): Builder
    {
        return $userId === null ? $query : $query->where('host_id', '<>', $userId);
    }

    /**
     * Published + upcoming + not own host — what the feed may show. The
     * package calls it with the ranking Context; sources call it too.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecommendable(Builder $query, Context $context): Builder
    {
        return $query->published()->upcoming($context->now())->notHostedBy($context->userId);
    }
}
