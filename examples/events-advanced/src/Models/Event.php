<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced\Models;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'starts_at' => 'datetime',
        ];
    }

    /** Visible to guests and members. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published', true);
    }

    /** Upcoming (or recently started / undated). */
    public function scopeUpcoming(Builder $query, DateTimeInterface $now): Builder
    {
        $cutoff = CarbonImmutable::parse($now)->subDay();

        return $query->where(function (Builder $q) use ($cutoff) {
            $q->whereNull('starts_at')->orWhere('starts_at', '>=', $cutoff);
        });
    }

    /** Hide the viewer’s own hosted events. */
    public function scopeNotHostedBy(Builder $query, int|string|null $userId): Builder
    {
        return $userId === null ? $query : $query->where('host_id', '<>', $userId);
    }

    /** Published + upcoming + not own host — the usual recommendable set. */
    public function scopeRecommendable(Builder $query, DateTimeInterface $now, int|string|null $userId = null): Builder
    {
        return $query->published()->upcoming($now)->notHostedBy($userId);
    }
}
