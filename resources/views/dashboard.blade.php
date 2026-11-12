<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Recommender</title>
    <style>
        :root { --bg:#f6f7f9; --card:#fff; --ink:#1d2330; --muted:#6b7280; --line:#e5e7eb; --ok:#15803d; --warn:#b45309; --fail:#b91c1c; --accent:#4f46e5; }
        @media (prefers-color-scheme: dark) { :root { --bg:#0f1115; --card:#181b22; --ink:#e5e7eb; --muted:#9ca3af; --line:#2a2f3a; --ok:#4ade80; --warn:#fbbf24; --fail:#f87171; --accent:#818cf8; } }
        * { box-sizing:border-box; }
        body { margin:0; font:14px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif; background:var(--bg); color:var(--ink); }
        header { display:flex; align-items:center; gap:16px; padding:16px 24px; border-bottom:1px solid var(--line); background:var(--card); }
        header h1 { font-size:16px; margin:0; }
        header .meta { color:var(--muted); display:flex; gap:16px; flex-wrap:wrap; }
        header .live { margin-left:auto; color:var(--muted); }
        main { max-width:1200px; margin:0 auto; padding:24px; }
        nav { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px; }
        nav a { padding:6px 12px; border:1px solid var(--line); border-radius:999px; text-decoration:none; color:var(--ink); background:var(--card); }
        nav a.active { border-color:var(--accent); color:var(--accent); font-weight:600; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:12px; margin-bottom:16px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:16px; }
        .stat .label { color:var(--muted); font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
        .stat .value { font-size:22px; font-weight:600; margin-top:4px; }
        .stat .sub { color:var(--muted); font-size:12px; }
        .cols { display:grid; grid-template-columns:1fr 1fr; gap:16px; }
        @media (max-width:860px) { .cols { grid-template-columns:1fr; } }
        h2 { font-size:14px; margin:0 0 12px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:6px 8px; border-bottom:1px solid var(--line); vertical-align:top; }
        th { color:var(--muted); font-weight:500; font-size:12px; }
        th.num, td.num { text-align:right; font-variant-numeric:tabular-nums; }
        .badge { display:inline-block; padding:1px 8px; border-radius:999px; font-size:12px; font-weight:600; }
        .ok { color:var(--ok); } .warn { color:var(--warn); } .fail { color:var(--fail); }
        .badge.ok { background:color-mix(in srgb,var(--ok) 14%,transparent); }
        .badge.warn { background:color-mix(in srgb,var(--warn) 14%,transparent); }
        .badge.fail { background:color-mix(in srgb,var(--fail) 14%,transparent); }
        ul.checks { list-style:none; margin:0; padding:0; }
        ul.checks li { padding:6px 0; border-bottom:1px solid var(--line); }
        ul.checks .hint { color:var(--muted); font-size:12px; margin-left:22px; }
        form { display:flex; gap:8px; margin-bottom:12px; }
        input { flex:1; padding:6px 10px; border:1px solid var(--line); border-radius:6px; background:var(--bg); color:var(--ink); }
        button { padding:6px 14px; border:0; border-radius:6px; background:var(--accent); color:#fff; cursor:pointer; }
        .empty { color:var(--muted); }
        code { font-size:12px; }
    </style>
</head>
<body>
<div id="recommender-app">
    <header>
        <h1>Recommender</h1>
        <div class="meta">
            <span>Status:
                @if ($overview['enabled'])
                    <span class="badge ok">enabled</span>
                @else
                    <span class="badge warn">disabled (kill switch)</span>
                @endif
            </span>
            <span>Cache: <code>{{ $overview['cache_store'] }}</code>
                <span class="badge {{ $overview['locks'] ? 'ok' : 'warn' }}">{{ $overview['locks'] ? 'locks' : 'no locks' }}</span>
            </span>
            <span>Updated {{ now()->toDateTimeString() }}</span>
        </div>
        @if ($refresh > 0)
            <label class="live"><input type="checkbox" id="recommender-live" checked> live ({{ $refresh }}s)</label>
        @endif
    </header>

    <main>
        @if ($feeds === [])
            <div class="card empty">No feeds configured. Add one under <code>feeds</code> in <code>config/recommender.php</code>.</div>
        @else
            <nav>
                @foreach ($feeds as $name => $feed)
                    <a href="?feed={{ urlencode($name) }}" class="{{ $name === $selected ? 'active' : '' }}">
                        <span class="{{ $feed['status'] }}">●</span> {{ $name }}
                    </a>
                @endforeach
            </nav>

            @php($feed = $feeds[$selected])
            @php($m = $feed['metrics'])

            <div class="grid">
                <div class="card stat">
                    <div class="label">Health</div>
                    <div class="value {{ $feed['status'] }}">{{ strtoupper($feed['status']) }}</div>
                    <div class="sub">{{ count(array_filter($feed['checks'], fn ($c) => $c['level'] !== 'ok')) }} issue(s)</div>
                </div>
                <div class="card stat">
                    <div class="label">Scores lag</div>
                    <div class="value">{{ $m['lag'] === null ? '—' : \SolutionForest\Recommender\Dashboard\Health::human($m['lag']) }}</div>
                    <div class="sub">{{ $m['watermark']?->toDateTimeString() ?? 'never ran' }}</div>
                </div>
                <div class="card stat">
                    <div class="label">Scored items</div>
                    <div class="value">{{ number_format($m['scores']) }}</div>
                    <div class="sub">last event {{ $m['last_event'] ?? '—' }}</div>
                </div>
                <div class="card stat">
                    <div class="label">Neighbours</div>
                    <div class="value">{{ number_format($m['neighbours']) }}</div>
                    <div class="sub">version {{ $m['version'] ?? '—' }} · {{ $m['shards'] }} shard(s)</div>
                </div>
            </div>

            <div class="cols">
                <div class="card">
                    <h2>Checks</h2>
                    <ul class="checks">
                        @foreach ($feed['checks'] as $check)
                            <li>
                                <span class="{{ $check['level'] }}">{{ ['ok' => '✓', 'warn' => '!', 'fail' => '✗'][$check['level']] }}</span>
                                {{ $check['label'] }}
                                @if ($check['hint'])
                                    <div class="hint">{{ $check['hint'] }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>

                <div class="card">
                    <h2>Trending now</h2>
                    @if ($feed['trending'] === [])
                        <p class="empty">Nothing scored yet.</p>
                    @else
                        <table>
                            <thead><tr><th>Item</th><th class="num">Hot</th><th class="num">Positive</th><th class="num">Exposure</th></tr></thead>
                            <tbody>
                            @foreach ($feed['trending'] as $row)
                                <tr><td>{{ $row['id'] }}</td><td class="num">{{ $row['hot'] }}</td><td class="num">{{ number_format($row['positive']) }}</td><td class="num">{{ number_format($row['exposure']) }}</td></tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>
            </div>

            <div class="card" style="margin-top:16px">
                <h2>Explain a user</h2>
                <form method="get">
                    <input type="hidden" name="feed" value="{{ $selected }}">
                    <input name="user" placeholder="User id" value="{{ $explain['user'] ?? '' }}" autocomplete="off">
                    <button type="submit">Explain</button>
                </form>
                @if ($explain !== null)
                    @if ($explain['error'])
                        <p class="fail">{{ $explain['error'] }}</p>
                    @elseif ($explain['rows'] === [])
                        <p class="empty">No candidates for user {{ $explain['user'] }} — they would see trending.</p>
                    @else
                        <table>
                            <thead><tr><th>#</th><th>Item</th><th class="num">Score</th><th>Why (source → normalised score)</th></tr></thead>
                            <tbody>
                            @foreach ($explain['rows'] as $i => $row)
                                <tr>
                                    <td>{{ $i + 1 }}</td>
                                    <td>{{ $row['id'] }}</td>
                                    <td class="num">{{ $row['score'] }}</td>
                                    <td>@foreach ($row['sources'] as $source => $value)<code>{{ $source }}</code> {{ $value }}@if (! $loop->last) · @endif @endforeach</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <p class="empty">Fresh build before exploration and diversity; not necessarily the cached page.</p>
                    @endif
                @endif
            </div>
        @endif
    </main>
</div>

@if ($refresh > 0)
<script>
    // Live refresh without a JS framework: re-fetch this page and swap the app.
    (function () {
        setInterval(function () {
            var live = document.getElementById('recommender-live');
            if (!live || !live.checked || document.hidden || document.activeElement?.tagName === 'INPUT' && document.activeElement.name === 'user') return;
            fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (!html) return;
                    var next = new DOMParser().parseFromString(html, 'text/html').getElementById('recommender-app');
                    if (next) document.getElementById('recommender-app').replaceWith(next);
                })
                .catch(function () {});
        }, {{ $refresh * 1000 }});
    })();
</script>
@endif
</body>
</html>
