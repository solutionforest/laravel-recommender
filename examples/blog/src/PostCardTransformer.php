<?php

namespace SolutionForest\Recommender\Examples\Blog;

use SolutionForest\Recommender\Contracts\CardTransformer;

/**
 * Step 3 — what the API returns per item. Cached per post and shared by all
 * users, so nothing viewer-specific in here.
 *
 * Lab lesson (media): if your cover URL can be a video (`.mp4`), do **not** put
 * it in `<img src>`. Map to a poster/thumb URL here (e.g. Azure `video_thumb/{stem}.jpg`)
 * and expose `cover.is_video` / `cover.video_url` for the client.
 */
class PostCardTransformer implements CardTransformer
{
    public function cards(array $ids): array
    {
        return Post::query()
            ->whereIn('id', $ids)
            ->where('published', true) // never render something that was unpublished meanwhile
            ->get()
            ->mapWithKeys(fn (Post $post) => [$post->id => [
                'id' => $post->id,
                'title' => $post->title,
                'author_id' => $post->author_id,
                'published_at' => $post->created_at?->toIso8601String(),
            ]])
            ->all();
    }
}
