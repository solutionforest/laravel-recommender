# Video tutorial

- `tutorial.mp4` is the full "your first feed" walkthrough (1280×720, about 1:22, H.264). It is linked from the main README.
- `tutorial-preview.gif` is the short loop shown in the README.

Everything on screen comes from a fresh Laravel 13 app with this package installed. Terminal scenes replay the captured output of real commands (`composer require` from Packagist, `recommender:bootstrap`, `recommender:explain`, `similar()` in tinker). The JSON feed and the dashboard are recorded live from `php artisan serve`. Only the title cards are made by hand.

`art/` is `export-ignore`d, so none of this ships with `composer require`.

## Regenerating it

Needs PHP 8.3+, Composer, Python 3 with Playwright (`pip install playwright && playwright install chromium`) and ffmpeg.

```bash
mkdir video && cd video
composer create-project laravel/laravel demo && cd demo
composer config repositories.rec '{"type":"path","url":"/path/to/laravel-recommender"}'
composer require "solutionforest/laravel-recommender:*@dev"
# copy art/video/source/demo/*: the migration, Post model and DemoSeeder; append routes.php to routes/web.php
php artisan migrate && php artisan db:seed --class=DemoSeeder
cd ..
```

Capture the terminal output **outside an AI agent session**, or through `source/human.sh`. Laravel compacts console output (no padding, no dot leaders) when it detects an agent such as Claude Code. `human.sh` unsets those variables; `runpty.py` runs the command in a 100×40 pseudo-terminal so colours and widths match a real terminal:

```bash
S=/path/to/laravel-recommender/art/video/source
mkdir -p out && cd demo
$S/human.sh python3 $S/runpty.py php artisan recommender:bootstrap posts --preset=blog --model='App\Models\Post' \
  --likes=post_likes --views=post_views --owner-column=user_id --published-column=published --title-column=title -n > ../out/bootstrap.ansi
$S/human.sh python3 $S/runpty.py php artisan recommender:explain posts 1 --limit=6 > ../out/explain.ansi
$S/human.sh python3 $S/runpty.py php artisan tinker --execute 'dump(array_map(fn ($c) => $c["title"], App\Models\Post::find(1)->similar(4)));' > ../out/similar.ansi
# out/install.ansi: the same wrapper around `composer require solutionforest/laravel-recommender` in a second fresh app
$S/human.sh php artisan serve --port=8765 &   # note the PID, stop it when done
cd ..
```

Build the scenes, serve them from the demo app (the browser scenes fetch `/posts/for-you` and frame `/recommender` on the same origin), and record:

```bash
VIDEO_WORKDIR=. python3 $S/build_scenes.py
cp -r scenes demo/public/_video
(cd demo && php artisan tinker --execute 'SolutionForest\Recommender\Facades\Recommender::forgetUser("posts", 1);')
VIDEO_WORKDIR=. python3 $S/record.py          # one webm per scene in clips/
```

Encode and join. Trim the blank first 0.3 s of each clip, fade 0.25 s in and out, then concatenate:

```bash
mkdir -p enc
for f in clips/*.webm; do n=$(basename $f .webm); d=$(ffprobe -v error -show_entries format=duration -of csv=p=0 $f)
  len=$(python3 -c "print(round($d-0.3,2))"); fo=$(python3 -c "print(round($len-0.25,2))")
  ffmpeg -y -ss 0.3 -i $f -t $len -vf "fps=30,format=yuv420p,fade=t=in:st=0:d=0.25,fade=t=out:st=$fo:d=0.25" -c:v libx264 -preset slow -crf 22 -an enc/$n.mp4; done
ls enc/*.mp4 | sed "s/^/file '/;s/$/'/" > list.txt
ffmpeg -y -f concat -safe 0 -i list.txt -c copy -movflags +faststart tutorial.mp4
```

The preview GIF is four cuts from `enc/` (title, the end of bootstrap, the JSON, the dashboard) at 8 fps and 760 px wide with a 96-colour palette. Keep it under about 3 MB.
