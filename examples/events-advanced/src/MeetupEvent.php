<?php

namespace SolutionForest\Recommender\Examples\EventsAdvanced;

use Illuminate\Database\Eloquent\Model;

class MeetupEvent extends Model
{
    protected $table = 'meetup_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'starts_at' => 'datetime',
        ];
    }
}
