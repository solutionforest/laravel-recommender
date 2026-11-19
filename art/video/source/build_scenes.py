"""Builds the HTML scenes of the README video tutorial.

Terminal scenes replay output captured from real commands in the demo app
(out/*.ansi); editor scenes show real files from the demo app.
"""
import html
import os
import json
import re
from pathlib import Path

ROOT = Path(os.environ.get('VIDEO_WORKDIR', '.')).resolve()  # holds demo/ and out/
OUT = ROOT / 'scenes'
OUT.mkdir(exist_ok=True)
DEMO = ROOT / 'demo'

COLORS = {30: '#6e7681', 31: '#ff7b72', 32: '#3fb950', 33: '#d29922', 34: '#58a6ff', 35: '#bc8cff', 36: '#39c5cf', 37: '#c9d1d9',
          90: '#8b949e', 91: '#ffa198', 92: '#56d364', 93: '#e3b341', 94: '#79c0ff', 95: '#d2a8ff', 96: '#56d4dd', 97: '#ffffff'}
BG = {40: '#484f58', 41: '#da3633', 42: '#238636', 43: '#9e6a03', 44: '#1f6feb', 45: '#8957e5', 46: '#1b7c83', 47: '#b1bac4'}


def ansi_to_html(text: str) -> list[str]:
    """SGR colours → spans, one HTML string per line. Cursor codes dropped."""
    text = text.replace('\r\n', '\n').replace('\r', '')
    text = re.sub(r'\x1b\[\?[0-9;]*[a-zA-Z]', '', text)
    text = re.sub(r'\x1b\[[0-9;]*[ABCDEFGHJKST]', '', text)
    lines = []
    for raw in text.split('\n'):
        out, fg, bg, bold = [], None, None, False
        for part in re.split(r'(\x1b\[[0-9;]*m)', raw):
            m = re.fullmatch(r'\x1b\[([0-9;]*)m', part)
            if m:
                codes = [int(c) for c in m.group(1).split(';') if c] or [0]
                i = 0
                while i < len(codes):
                    c = codes[i]
                    if c == 0:
                        fg, bg, bold = None, None, False
                    elif c == 1:
                        bold = True
                    elif c in (22,):
                        bold = False
                    elif c == 39:
                        fg = None
                    elif c == 49:
                        bg = None
                    elif c in COLORS:
                        fg = COLORS[c]
                    elif c in BG:
                        bg = BG[c]
                    elif c in (38, 48) and i + 2 < len(codes) and codes[i + 1] == 5:
                        val = codes[i + 2]
                        col = {2: '#3fb950', 3: '#d29922', 1: '#ff7b72', 4: '#58a6ff', 6: '#39c5cf', 8: '#6e7681'}.get(val % 16, '#c9d1d9')
                        fg, bg = (col, bg) if c == 38 else (fg, col)
                        i += 2
                    i += 1
                continue
            if not part:
                continue
            style = []
            if fg:
                style.append(f'color:{fg}')
            if bg:
                style.append(f'background:{bg};color:#fff')
            if bold:
                style.append('font-weight:700')
            esc = html.escape(part)
            out.append(f'<span style="{";".join(style)}">{esc}</span>' if style else esc)
        lines.append(''.join(out))
    while lines and lines[-1].strip() == '':
        lines.pop()
    while lines and lines[0].strip() == '':
        lines.pop(0)
    return lines


BASE_CSS = """
*{box-sizing:border-box;margin:0;padding:0}
html,body{width:1280px;height:720px;overflow:hidden;background:radial-gradient(1200px 700px at 20% 0%,#1b2136 0%,#0b0e17 60%);font-family:'Inter','Segoe UI',system-ui,sans-serif;color:#e6edf3}
.step{position:absolute;left:56px;top:34px;display:flex;align-items:center;gap:14px}
.badge{background:#ef4444;color:#fff;font-weight:800;font-size:15px;padding:6px 12px;border-radius:999px;letter-spacing:.04em}
.title{font-size:30px;font-weight:800;letter-spacing:-.02em}
.sub{position:absolute;left:56px;top:84px;font-size:18px;color:#9da7b3;max-width:1150px}
.win{position:absolute;left:56px;right:56px;top:128px;bottom:40px;background:#0d1117;border:1px solid #30363d;border-radius:14px;box-shadow:0 30px 80px rgba(0,0,0,.55);overflow:hidden}
.bar{height:38px;background:#161b22;border-bottom:1px solid #30363d;display:flex;align-items:center;padding:0 14px;gap:8px}
.dot{width:12px;height:12px;border-radius:50%}
.bar .name{margin-left:12px;color:#8b949e;font-size:13px;font-family:'JetBrains Mono','DejaVu Sans Mono',monospace}
.body{padding:16px 20px;font-family:'JetBrains Mono','DejaVu Sans Mono',Menlo,monospace;font-size:13.2px;line-height:1.5;white-space:pre;color:#c9d1d9;height:calc(100% - 38px);overflow:hidden}
.line{opacity:0}
.line.on{opacity:1}
.prompt{color:#3fb950}.cmd{color:#e6edf3}
.caret{display:inline-block;width:8px;height:16px;background:#e6edf3;vertical-align:-3px;animation:b 1s steps(1) infinite}
@keyframes b{50%{opacity:0}}
.hl{background:rgba(56,139,253,.18);border-left:3px solid #58a6ff;margin-left:-20px;padding-left:17px}
.k{color:#ff7b72}.s{color:#a5d6ff}.c{color:#8b949e}.f{color:#d2a8ff}.v{color:#ffa657}
"""

CHROME = '<div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span><span class="dot" style="background:#28c840"></span><span class="name">{name}</span></div>'


def header(step: str, title: str, sub: str) -> str:
    badge = f'<span class="badge">{html.escape(step)}</span>' if step else ''
    return f'<div class="step">{badge}<span class="title">{html.escape(title)}</span></div><div class="sub">{sub}</div>'


def page(body: str, script: str) -> str:
    return f'<!doctype html><html><head><meta charset="utf-8"><style>{BASE_CSS}</style></head><body>{body}<script>{script}</script></body></html>'


def terminal_scene(name: str, step: str, title: str, sub: str, command: str, ansi_file: str | None, hold: float, max_lines: int = 26, line_ms: int = 55, scroll: bool = True, lines_override: list[str] | None = None):
    lines = lines_override if lines_override is not None else (ansi_to_html((ROOT / 'out' / ansi_file).read_text(encoding='utf8')) if ansi_file else [])
    body = header(step, title, sub) + '<div class="win">' + CHROME.format(name='~/my-app — zsh') + '<div class="body" id="t"></div></div>'
    script = f"""
const lines = {json.dumps(lines)};
const cmd = {json.dumps(command)};
const t = document.getElementById('t');
const max = {max_lines};
function render(shown, typed, caret) {{
  let rows = ['<span class="prompt">➜</span> <span class="cmd">' + typed + '</span>' + (caret ? '<span class="caret"></span>' : '')];
  rows = rows.concat(shown);
  if ({str(scroll).lower()} && rows.length > max) rows = rows.slice(rows.length - max);
  t.innerHTML = rows.join('\\n');
}}
(async () => {{
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  await sleep(500);
  for (let i = 1; i <= cmd.length; i++) {{ render([], cmd.slice(0, i), true); await sleep(38); }}
  await sleep(450);
  const shown = [];
  for (const l of lines) {{ shown.push(l); render(shown, cmd, false); await sleep({line_ms}); }}
  await sleep({int(hold * 1000)});
  window.done = true;
}})();
"""
    (OUT / f'{name}.html').write_text(page(body, script), encoding='utf8')


PHP_KEYWORDS = r'\b(use|class|extends|public|function|return|fn|new|namespace|true|false|null|array|void)\b'


def highlight_php(src: str) -> list[str]:
    out = []
    for line in src.split('\n'):
        esc = html.escape(line)
        if esc.lstrip().startswith(('//', '|', '*', '/*')):
            out.append(f'<span class="c">{esc}</span>')
            continue
        code, comment = esc, ''
        m = re.search(r'\s//\s.*$', esc)
        if m:
            code, comment = esc[:m.start()], f'<span class="c">{esc[m.start():]}</span>'
        strings = []
        code = re.sub(r'(&#x27;[^&]*?&#x27;)', lambda m: strings.append(m.group(1)) or f'\x00{len(strings) - 1}\x00', code)
        code = re.sub(PHP_KEYWORDS, r'<span class="k">\1</span>', code)
        code = re.sub(r'(\$[a-zA-Z_]+)', r'<span class="v">\1</span>', code)
        code = re.sub(r'\x00(\d+)\x00', lambda m: f'<span class="s">{strings[int(m.group(1))]}</span>', code)
        out.append(code + comment)
    return out


def editor_scene(name: str, step: str, title: str, sub: str, filename: str, src: str, highlight: list[int], hold: float):
    lines = highlight_php(src)
    rows = []
    for i, l in enumerate(lines, 1):
        num = f'<span style="color:#484f58;display:inline-block;width:34px">{i}</span>'
        cls = 'line hl' if i in highlight else 'line'
        rows.append(f'<div class="{cls}">{num}{l or " "}</div>')
    body = header(step, title, sub) + '<div class="win">' + CHROME.format(name=filename) + '<div class="body" style="white-space:pre;font-size:15px;line-height:1.6">' + ''.join(rows) + '</div></div>'
    script = f"""
(async () => {{
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  await sleep(300);
  for (const el of document.querySelectorAll('.line')) {{ el.classList.add('on'); await sleep(45); }}
  await sleep({int(hold * 1000)});
  window.done = true;
}})();
"""
    (OUT / f'{name}.html').write_text(page(body, script), encoding='utf8')


def card_scene(name: str, inner: str, hold: float):
    body = f'<div style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:18px">{inner}</div>'
    script = f"setTimeout(() => window.done = true, {int(hold * 1000)});"
    (OUT / f'{name}.html').write_text(page(body, script), encoding='utf8')


logo = (Path(__file__).resolve().parents[2] / 'logo.svg').read_text(encoding='utf8')  # art/logo.svg
logo = re.sub(r'<\?xml[^>]*>', '', logo)

card_scene('01-title', f"""
<div style="width:120px;height:120px">{logo.replace('<svg', '<svg width="120" height="120"', 1)}</div>
<div style="font-size:56px;font-weight:800;letter-spacing:-.03em">Laravel Recommender</div>
<div style="font-size:24px;color:#9da7b3">A “for you” feed from the interactions your app already records</div>
<div style="margin-top:18px;font-size:18px;color:#ef4444;font-weight:700;letter-spacing:.08em">YOUR FIRST FEED IN 3 STEPS</div>
""", 3.5)

editor_scene('02-tables', '0', 'You already have posts, likes and views',
             'Any Eloquent model plus interaction tables with <code>user_id</code>, <code>post_id</code>, <code>created_at</code>. The package reads them; it never copies your rows.',
             'database/migrations/create_posts_tables.php',
             """Schema::create('posts', function (Blueprint $table) {
    $table->id();
    $table->string('title');
    $table->boolean('published')->default(true);
    $table->timestamps();
});

// One table per interaction: post_likes, post_views
Schema::create('post_likes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id');
    $table->foreignId('post_id');
    $table->timestamp('created_at')->index();
    $table->index(['user_id', 'created_at']);
});""", [10, 11, 12], 4.5)

terminal_scene('03-install', '1', 'Install', 'One Composer package. Laravel 10–13, PHP 8.3+.',
               'composer require solutionforest/laravel-recommender', 'install.ansi', 2.0, max_lines=23, line_ms=60)

terminal_scene('04-bootstrap', '1', 'Set up the feed in one command',
               'Migrates, registers the feed, builds the first scores, checks everything and times each part. Real output.',
               'php artisan recommender:bootstrap posts', 'bootstrap.ansi', 3.2, max_lines=23, line_ms=75)

config = (DEMO / 'config' / 'recommender.php').read_text(encoding='utf8')
feed = config[config.index("    'feeds' => ["):]
feed = feed[:feed.index('\n];')]
editor_scene('05-config', '1', 'What bootstrap wrote: a few lines of config',
             'Pick a preset (<code>catalog</code>, <code>social</code>, <code>events</code>), point at the model and the interaction tables. No classes to write.',
             'config/recommender.php', feed, [3, 7, 9, 10], 5.0)

model = (DEMO / 'app' / 'Models' / 'Post.php').read_text(encoding='utf8')
model = model[model.index('use Illuminate'):].rstrip()
editor_scene('06-model', '2', 'Tell the model what may be shown',
             'A visibility scope and the JSON card for each item. Both optional; <code>Recommendable</code> also refreshes cached cards on save.',
             'app/Models/Post.php', model, [6, 10, 12, 15, 17], 5.5)

editor_scene('07-route', '3', 'Add a route', 'That is the whole integration. The scoring jobs are scheduled for you: keep Laravel’s scheduler running.',
             'routes/web.php', """use App\\Models\\Post;
use Illuminate\\Http\\Request;

Route::get('/posts/for-you', fn (Request $request) => Post::recommendedFor($request->user()));""", [4], 4.0)

terminal_scene('09-similar', 'Bonus', 'Related posts: one call',
               '<code>$post->similar(4)</code>: the posts most often liked together with this one, the same for every viewer, cached per post.',
               "php artisan tinker --execute 'dump(array_map(fn ($c) => $c[\"title\"], Post::find(1)->similar(4)));'", 'similar.ansi', 3.5)

terminal_scene('10-explain', 'Debug', 'Why does user 1 get these posts?',
               'Per-source contributions for every item: co-interaction (“liked together”), trending and the quality booster.',
               'php artisan recommender:explain posts 1 --limit=6', 'explain.ansi', 4.5, line_ms=110)

card_scene('12-end', f"""
<div style="width:84px;height:84px">{logo.replace('<svg', '<svg width="84" height="84"', 1)}</div>
<div style="font-size:44px;font-weight:800;letter-spacing:-.02em">That’s your first feed.</div>
<div style="font-size:22px;color:#9da7b3;line-height:1.7">composer require solutionforest/laravel-recommender<br>
<span style="color:#e6edf3">Docs:</span> tutorial · tuning · attribute match for new items · dashboard</div>
<div style="margin-top:12px;font-size:20px;color:#58a6ff">github.com/solutionforest/laravel-recommender</div>
""", 4.0)
print('scenes:', sorted(p.name for p in OUT.glob('*.html')))


BROWSER_CSS = """
.url{flex:1;margin-left:18px;height:24px;border-radius:6px;background:#0d1117;border:1px solid #30363d;color:#c9d1d9;font:13px 'JetBrains Mono','DejaVu Sans Mono',monospace;display:flex;align-items:center;padding:0 10px}
.view{position:absolute;top:38px;left:0;right:0;bottom:0;background:#fff}
iframe{border:0;width:100%;height:100%;background:#fff}
pre.json{margin:0;padding:18px 22px;font:14px/1.55 'JetBrains Mono','DejaVu Sans Mono',monospace;color:#24292f;white-space:pre;height:100%;overflow:hidden;background:#fff}
.jk{color:#0550ae}.js{color:#0a3069}.jn{color:#953800}
"""


def browser_scene(name: str, step: str, title: str, sub: str, url: str, inner: str, script: str):
    bar = ('<div class="bar"><span class="dot" style="background:#ff5f57"></span><span class="dot" style="background:#febc2e"></span>'
           f'<span class="dot" style="background:#28c840"></span><span class="url">{html.escape(url)}</span></div>')
    body = f'<style>{BROWSER_CSS}</style>' + header(step, title, sub) + '<div class="win">' + bar + f'<div class="view">{inner}</div></div>'
    (OUT / f'{name}.html').write_text(page(body, script), encoding='utf8')


browser_scene('08-json', '3', 'A personal feed, as JSON',
              'User 1 liked two Laravel posts, so other Laravel posts come first. Laravel’s paginator JSON, plus <code>next_cursor</code>.',
              'localhost/posts/for-you', '<pre class="json" id="j"></pre>', """
(async () => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  await fetch('/demo/login/1', {credentials: 'same-origin', redirect: 'manual'});
  const data = await (await fetch('/posts/for-you', {credentials: 'same-origin', headers: {Accept: 'application/json'}})).json();
  const show = {data: data.data.slice(0, 7), origin: data.origin, has_more: data.has_more, next_cursor: data.next_cursor, current_page: data.current_page, per_page: data.per_page};
  const text = JSON.stringify(show, null, 2)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;')
    .replace(/"([^"]+)":/g, '<span class="jk">"$1"</span>:')
    .replace(/: "([^"]*)"/g, ': <span class="js">"$1"</span>')
    .replace(/: (\\d+|true|false|null)/g, ': <span class="jn">$1</span>');
  const el = document.getElementById('j');
  const rows = text.split('\\n');
  for (let i = 1; i <= rows.length; i++) { el.innerHTML = rows.slice(0, i).join('\\n'); await sleep(28); }
  await sleep(5200);
  window.done = true;
})();
""")

browser_scene('11-dashboard', 'Watch', 'The dashboard at /recommender',
              'Health checks with fixes, jobs, trending and “explain a user”, in one page. Gate it in production.',
              'localhost/recommender?feed=posts&user=1', '<iframe id="f" src="/recommender?feed=posts&user=1"></iframe>', """
(async () => {
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const f = document.getElementById('f');
  await new Promise(r => f.addEventListener('load', r, {once: true}));
  await sleep(2600);
  const w = f.contentWindow, d = f.contentDocument;
  const max = d.documentElement.scrollHeight - w.innerHeight;
  for (let y = 0; y <= max; y += 6) { w.scrollTo(0, y); await sleep(16); }
  await sleep(2200);
  window.done = true;
})();
""")
print('browser scenes ready')
