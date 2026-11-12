<?php

use SolutionForest\Recommender\Examples\Simple\Models\Article;

/*
 * The smallest useful feed: no InteractionSource, filter or card classes.
 *
 *  - 'model'        what you recommend (ids are its primary keys)
 *  - 'interactions' which tables hold user → item events, and their names
 *
 * Visibility lives in Article::scopeRecommendable(), the card in
 * Article::toRecommendationCard(). Everything else uses package defaults
 * (CoInteractionSource 0.6 + TrendingSource 0.4, view=1 / like=4).
 */
return [
    'feeds' => [
        'articles' => [
            'model' => Article::class,
            'interactions' => [
                'article_likes' => 'like',
                'article_views' => 'view',
            ],
            'diversity' => ['author_id' => 1], // at most one article per author per page
        ],
    ],
];
