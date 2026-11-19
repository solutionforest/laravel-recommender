<?php

namespace Database\Seeders;

use App\Models\Post;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        mt_srand(2026);
        $topics = [
            'Laravel' => ['Queues without tears', 'Eloquent tips you will use daily', 'Testing with Pest', 'Deploying with zero downtime', 'Livewire forms in 10 minutes', 'Caching strategies that scale', 'Writing your first package', 'Rate limiting APIs'],
            'Cooking' => ['Weeknight ramen', 'Sourdough for beginners', 'One-pan chicken', 'Dim sum at home', 'Five sauces to master', 'Meal prep Sunday', 'Perfect fried rice', 'Easy egg tarts'],
            'Travel' => ['48 hours in Tokyo', 'Hiking Dragon\'s Back', 'Kyoto on a budget', 'Packing light for a week', 'Night markets of Taipei', 'Island hopping in Sai Kung', 'Seoul cafe guide', 'Rail pass tips for Japan'],
            'Fitness' => ['Your first 5K', 'Mobility in 10 minutes', 'Strength basics', 'Running in the heat', 'Desk stretches', 'Cycling the harbour', 'Recovery days matter', 'Home workout, no gear'],
        ];
        User::factory()->count(60)->create();
        $posts = [];
        foreach ($topics as $topic => $titles) {
            foreach ($titles as $title) {
                $posts[$topic][] = Post::create(['user_id' => mt_rand(1, 60), 'title' => $title, 'topic' => $topic, 'created_at' => now()->subDays(mt_rand(2, 40))])->id;
            }
        }
        $names = array_keys($topics);
        $likes = $views = [];
        foreach (range(2, 60) as $u) {
            $fav = $names[$u % 4];
            $other = $names[($u + 1) % 4];
            foreach (array_rand(array_flip($posts[$fav]), 4) as $id) {
                $likes[] = ['user_id' => $u, 'post_id' => $id, 'created_at' => now()->subHours(mt_rand(2, 72))];
            }
            foreach (array_rand(array_flip(array_merge($posts[$fav], $posts[$other])), 8) as $id) {
                $views[] = ['user_id' => $u, 'post_id' => $id, 'created_at' => now()->subHours(mt_rand(2, 72))];
            }
        }
        // User 1 (the viewer in the video) liked two Laravel posts.
        $likes[] = ['user_id' => 1, 'post_id' => $posts['Laravel'][0], 'created_at' => now()->subHours(3)];
        $likes[] = ['user_id' => 1, 'post_id' => $posts['Laravel'][2], 'created_at' => now()->subHours(3)];
        DB::table('post_likes')->insert($likes);
        DB::table('post_views')->insert($views);
    }
}
