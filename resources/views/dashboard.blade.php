@php
    $levelText = ['ok' => 'Healthy', 'warn' => 'Warnings', 'fail' => 'Failing'];
    $current = $selected !== null ? $feeds[$selected] : null;
    $icon = [
        'ok' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.86-9.81a.75.75 0 0 0-1.22-.88l-3.24 4.5-1.6-1.6a.75.75 0 1 0-1.06 1.06l2.22 2.22a.75.75 0 0 0 1.14-.09l3.76-5.2Z" clip-rule="evenodd"/></svg>',
        'warn' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.48 2.89a1.75 1.75 0 0 1 3.04 0l6.28 10.98A1.75 1.75 0 0 1 16.28 16.5H3.72a1.75 1.75 0 0 1-1.52-2.63L8.48 2.89ZM10 6.5a.75.75 0 0 1 .75.75v3.5a.75.75 0 0 1-1.5 0v-3.5A.75.75 0 0 1 10 6.5Zm0 7.5a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z" clip-rule="evenodd"/></svg>',
        'fail' => '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM7.28 7.28a.75.75 0 0 1 1.06 0L10 8.94l1.66-1.66a.75.75 0 1 1 1.06 1.06L11.06 10l1.66 1.66a.75.75 0 1 1-1.06 1.06L10 11.06l-1.66 1.66a.75.75 0 0 1-1.06-1.06L8.94 10 7.28 8.34a.75.75 0 0 1 0-1.06Z" clip-rule="evenodd"/></svg>',
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $selected ? $selected.' · ' : '' }}Recommender</title>
    <script>
        (function () { var t = localStorage.getItem('rec-theme'); if (t) document.documentElement.dataset.theme = t; })();
    </script>
    <style>
        :root {
            --bg: #f7f7f8; --panel: #ffffff; --panel-2: #fafafa; --ink: #18181b; --ink-2: #3f3f46; --muted: #71717a; --line: #e4e4e7; --line-2: #f0f0f2;
            --accent: #6d5dfc; --accent-soft: #efedff; --ok: #12a150; --ok-soft: #e7f7ee; --warn: #c27803; --warn-soft: #fdf3e1; --fail: #e0384b; --fail-soft: #fdecee;
            --shadow: 0 1px 2px rgba(16, 24, 40, .04), 0 1px 3px rgba(16, 24, 40, .06);
            --s0: #6d5dfc; --s1: #14b8a6; --s2: #f59e0b; --s3: #ec4899; --s4: #3b82f6; --s5: #84cc16;
        }
        @media (prefers-color-scheme: dark) { :root:not([data-theme=light]) { --bg: #0b0b0e; --panel: #141418; --panel-2: #18181d; --ink: #f4f4f5; --ink-2: #d4d4d8; --muted: #8b8b94; --line: #26262c; --line-2: #1f1f24; --accent: #8b7dff; --accent-soft: #221f3d; --ok: #34d17a; --ok-soft: #10261a; --warn: #f5b43c; --warn-soft: #2b2110; --fail: #ff5c6e; --fail-soft: #2d1418; --shadow: none; } }
        :root[data-theme=dark] { --bg: #0b0b0e; --panel: #141418; --panel-2: #18181d; --ink: #f4f4f5; --ink-2: #d4d4d8; --muted: #8b8b94; --line: #26262c; --line-2: #1f1f24; --accent: #8b7dff; --accent-soft: #221f3d; --ok: #34d17a; --ok-soft: #10261a; --warn: #f5b43c; --warn-soft: #2b2110; --fail: #ff5c6e; --fail-soft: #2d1418; --shadow: none; }

        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; }
        body { font: 14px/1.5 Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); -webkit-font-smoothing: antialiased; }
        a { color: inherit; text-decoration: none; }
        code, .mono { font-family: "JetBrains Mono", ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12px; }
        svg { width: 16px; height: 16px; flex: none; }

        .wrap { max-width: 1200px; margin: 0 auto; padding-left: 32px; padding-right: 32px; }
        @media (max-width: 700px) { .wrap { padding-left: 16px; padding-right: 16px; } }

        /* Top bar */
        .top { position: sticky; top: 0; z-index: 10; background: color-mix(in srgb, var(--bg) 85%, transparent); backdrop-filter: saturate(180%) blur(10px); border-bottom: 1px solid var(--line); }
        .top-row { display: flex; align-items: center; gap: 16px; height: 60px; }
        .brand { display: flex; align-items: center; gap: 10px; font-weight: 650; letter-spacing: -.01em; }
        .brand-mark { width: 28px; height: 28px; border-radius: 8px; display: grid; place-items: center; color: #fff; background: linear-gradient(135deg, var(--accent), #22c1a8); }
        .brand-mark svg { width: 16px; height: 16px; }
        .chips { display: flex; gap: 8px; flex-wrap: wrap; }
        .chip { display: inline-flex; align-items: center; gap: 6px; height: 26px; padding: 0 10px; border: 1px solid var(--line); border-radius: 999px; font-size: 12px; color: var(--muted); background: var(--panel); }
        .chip b { color: var(--ink); font-weight: 600; }
        @media (max-width: 800px) { .chips { display: none; } }
        .tabs { margin-left: auto; display: inline-flex; gap: 4px; padding: 4px; border: 1px solid var(--line); border-radius: 12px; background: var(--panel); box-shadow: var(--shadow); }
        .tabs a { display: inline-flex; align-items: center; gap: 8px; padding: 6px 12px; border-radius: 8px; color: var(--ink-2); font-weight: 500; }
        .tabs a:hover { background: var(--panel-2); }
        .tabs a.active { background: var(--accent-soft); color: var(--accent); font-weight: 600; }
        .tabs .count { font-size: 12px; color: var(--muted); font-variant-numeric: tabular-nums; }
        .dot { width: 8px; height: 8px; border-radius: 50%; flex: none; }
        .dot.ok { background: var(--ok); box-shadow: 0 0 0 3px var(--ok-soft); }
        .dot.warn { background: var(--warn); box-shadow: 0 0 0 3px var(--warn-soft); }
        .dot.fail { background: var(--fail); box-shadow: 0 0 0 3px var(--fail-soft); }
        .btn.icon { width: 32px; padding: 0; justify-content: center; }

        /* Main */
        main { padding-top: 28px; padding-bottom: 48px; }
        .topbar { display: flex; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 20px; }
        .crumbs { font-size: 12px; color: var(--muted); }
        h1 { margin: 2px 0 0; font-size: 24px; font-weight: 650; letter-spacing: -.02em; display: flex; align-items: center; gap: 12px; }
        .actions { margin-left: auto; display: flex; align-items: center; gap: 10px; }
        .pill { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
        .pill svg { width: 14px; height: 14px; }
        .pill.ok { color: var(--ok); background: var(--ok-soft); } .pill.warn { color: var(--warn); background: var(--warn-soft); } .pill.fail { color: var(--fail); background: var(--fail-soft); }
        .btn { display: inline-flex; align-items: center; gap: 8px; height: 32px; padding: 0 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--panel); color: var(--ink-2); font: inherit; font-size: 13px; cursor: pointer; box-shadow: var(--shadow); }
        .btn:hover { border-color: var(--muted); }
        .switch { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); cursor: pointer; user-select: none; }
        .switch input { appearance: none; width: 30px; height: 18px; border-radius: 999px; background: var(--line); position: relative; cursor: pointer; transition: background .15s; margin: 0; }
        .switch input::after { content: ""; position: absolute; top: 2px; left: 2px; width: 14px; height: 14px; border-radius: 50%; background: #fff; transition: transform .15s; box-shadow: 0 1px 2px rgba(0,0,0,.2); }
        .switch input:checked { background: var(--ok); }
        .switch input:checked::after { transform: translateX(12px); }
        .pulse { width: 6px; height: 6px; border-radius: 50%; background: var(--ok); animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--ok) 60%, transparent); } 70% { box-shadow: 0 0 0 6px transparent; } 100% { box-shadow: 0 0 0 0 transparent; } }

        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 14px; box-shadow: var(--shadow); }
        .card-head { display: flex; align-items: center; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--line-2); }
        .card-head h2 { margin: 0; font-size: 14px; font-weight: 600; }
        .card-head .sub { margin-left: auto; font-size: 12px; color: var(--muted); }
        .card-body { padding: 16px 18px; }

        /* Pipeline */
        .pipeline { display: grid; grid-template-columns: repeat(4, 1fr); margin-bottom: 16px; }
        @media (max-width: 1100px) { .pipeline { grid-template-columns: repeat(2, 1fr); } }
        .stage { position: relative; display: flex; gap: 12px; padding: 16px 18px; }
        .stage + .stage { border-left: 1px solid var(--line-2); }
        .stage .badge { width: 32px; height: 32px; border-radius: 10px; display: grid; place-items: center; flex: none; }
        .stage .badge.ok { color: var(--ok); background: var(--ok-soft); } .stage .badge.warn { color: var(--warn); background: var(--warn-soft); } .stage .badge.fail { color: var(--fail); background: var(--fail-soft); }
        .stage .name { font-size: 12px; color: var(--muted); }
        .stage .what { font-weight: 600; }
        .stage .step { position: absolute; top: 10px; right: 14px; font-size: 11px; color: var(--muted); }

        /* KPIs */
        .kpis { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 16px; }
        @media (max-width: 1100px) { .kpis { grid-template-columns: repeat(2, 1fr); } }
        .kpi { padding: 16px 18px; }
        .kpi .label { font-size: 12px; color: var(--muted); font-weight: 500; }
        .kpi .value { margin-top: 6px; font-size: 28px; line-height: 1.1; font-weight: 650; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
        .kpi .value small { font-size: 14px; font-weight: 500; color: var(--muted); letter-spacing: 0; }
        .kpi .foot { margin-top: 10px; font-size: 12px; color: var(--muted); }
        .meter { height: 6px; border-radius: 999px; background: var(--line-2); overflow: hidden; margin-top: 12px; }
        .meter i { display: block; height: 100%; border-radius: 999px; background: var(--accent); }
        .meter i.ok { background: var(--ok); } .meter i.warn { background: var(--warn); } .meter i.fail { background: var(--fail); }

        .cols { display: grid; grid-template-columns: 1.15fr 1fr; gap: 16px; margin-bottom: 16px; }
        @media (max-width: 1100px) { .cols { grid-template-columns: 1fr; } }

        /* Tables */
        table { width: 100%; border-collapse: collapse; }
        th { padding: 10px 18px; text-align: left; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); background: var(--panel-2); border-bottom: 1px solid var(--line-2); }
        td { padding: 10px 18px; border-bottom: 1px solid var(--line-2); font-variant-numeric: tabular-nums; }
        tr:last-child td { border-bottom: 0; }
        .num { text-align: right; }
        .rank { color: var(--muted); width: 36px; }
        .item { font-weight: 600; }
        .bar { position: relative; height: 8px; min-width: 80px; border-radius: 999px; background: var(--line-2); overflow: hidden; }
        .bar i { position: absolute; inset: 0 auto 0 0; border-radius: 999px; background: linear-gradient(90deg, var(--accent), color-mix(in srgb, var(--accent) 55%, #22c1a8)); }
        .rate { display: inline-block; min-width: 46px; padding: 1px 8px; border-radius: 999px; font-size: 12px; text-align: center; background: var(--accent-soft); color: var(--accent); font-weight: 600; }

        /* Checks */
        .checks { list-style: none; margin: 0; padding: 6px 0; }
        .checks li { display: flex; gap: 10px; padding: 9px 18px; }
        .checks li svg { margin-top: 2px; }
        .checks .ok { color: var(--ok); } .checks .warn { color: var(--warn); } .checks .fail { color: var(--fail); }
        .checks .text { color: var(--ink-2); }
        .checks .hint { display: block; margin-top: 2px; font-size: 12px; color: var(--muted); }
        .checks .hint code { padding: 1px 6px; border-radius: 6px; background: var(--panel-2); border: 1px solid var(--line-2); }
        details summary { list-style: none; cursor: pointer; padding: 10px 18px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--line-2); }
        details summary::-webkit-details-marker { display: none; }
        details summary::before { content: "▸ "; } details[open] summary::before { content: "▾ "; }

        /* Explain */
        .search { display: flex; gap: 8px; padding: 14px 18px; border-bottom: 1px solid var(--line-2); }
        .search label { flex: 1; display: flex; align-items: center; gap: 8px; height: 36px; padding: 0 12px; border: 1px solid var(--line); border-radius: 10px; background: var(--panel-2); color: var(--muted); }
        .search label:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
        .search input { flex: 1; border: 0; outline: 0; background: transparent; color: var(--ink); font: inherit; }
        .btn.primary { height: 36px; border: 0; background: var(--accent); color: #fff; font-weight: 600; box-shadow: none; }
        .stack { display: flex; height: 10px; border-radius: 999px; overflow: hidden; background: var(--line-2); min-width: 160px; }
        .stack i { display: block; height: 100%; }
        .stack i + i { border-left: 2px solid var(--panel); }
        .c0 { background: var(--s0); } .c1 { background: var(--s1); } .c2 { background: var(--s2); } .c3 { background: var(--s3); } .c4 { background: var(--s4); } .c5 { background: var(--s5); }
        .legend { display: flex; flex-wrap: wrap; gap: 14px; padding: 12px 18px; font-size: 12px; color: var(--muted); border-top: 1px solid var(--line-2); }
        .legend span { display: inline-flex; align-items: center; gap: 6px; }
        .legend i { width: 10px; height: 10px; border-radius: 3px; }
        .why { font-size: 12px; color: var(--muted); }
        .empty { padding: 28px 18px; text-align: center; color: var(--muted); }
        .note { padding: 10px 18px 14px; font-size: 12px; color: var(--muted); }
        .error { margin: 14px 18px; padding: 10px 12px; border-radius: 10px; color: var(--fail); background: var(--fail-soft); }
    </style>
</head>
<body>
<div id="recommender-app" data-updated="{{ now()->getTimestamp() }}">
    <header class="top">
        <div class="wrap top-row">
            <div class="brand">
                <span class="brand-mark"><svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 1.5l2.2 5.1 5.3.5-4 3.6 1.2 5.3L10 13.2 5.3 16l1.2-5.3-4-3.6 5.3-.5L10 1.5z"/></svg></span>
                Recommender
            </div>
            <div class="chips">
                <span class="chip"><span class="dot {{ $overview['enabled'] ? 'ok' : 'warn' }}"></span>{{ $overview['enabled'] ? 'Engine on' : 'Kill switch on' }}</span>
                <span class="chip">Cache <b class="mono">{{ $overview['cache_store'] }}</b>{{ $overview['locks'] ? '' : ' · no locks' }}</span>
                <span class="chip">{{ app()->environment() }}</span>
            </div>
            <div class="actions">
                <span class="crumbs" id="recommender-updated">Updated just now</span>
                @if ($refresh > 0)
                    <label class="switch"><span class="pulse"></span>Live<input type="checkbox" id="recommender-live" checked></label>
                @else
                    <span class="crumbs">Live paused</span>
                @endif
                <button class="btn icon" type="button" id="recommender-theme" title="Toggle light / dark">
                    <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 2a8 8 0 1 0 0 16V2Z"/><path fill-rule="evenodd" d="M10 1a9 9 0 1 1 0 18 9 9 0 0 1 0-18Zm0 1.5a7.5 7.5 0 1 0 0 15 7.5 7.5 0 0 0 0-15Z" clip-rule="evenodd"/></svg>
                </button>
            </div>
        </div>
    </header>

    <main class="wrap">
        @if ($current === null)
            <div class="card empty">
                No feeds configured. Add one under <code>feeds</code> in <code>config/recommender.php</code>:<br><br>
                <code>'posts' =&gt; ['model' =&gt; Post::class, 'interactions' =&gt; ['post_likes' =&gt; 'like']]</code>
            </div>
        @else
            @php($m = $current['metrics'])
            @php($issues = array_values(array_filter($current['checks'], fn ($c) => $c['level'] !== 'ok')))
            @php($passed = array_values(array_filter($current['checks'], fn ($c) => $c['level'] === 'ok')))

            <div class="topbar">
                <h1>
                    {{ $selected }}
                    <span class="pill {{ $current['status'] }}">{!! $icon[$current['status']] !!} {{ $levelText[$current['status']] }}</span>
                </h1>
                @if (count($feeds) > 1)
                    <nav class="tabs" aria-label="Feeds">
                        @foreach ($feeds as $name => $feed)
                            <a href="?feed={{ urlencode($name) }}" class="{{ $name === $selected ? 'active' : '' }}">
                                <span class="dot {{ $feed['status'] }}"></span>{{ $name }}
                                <span class="count">{{ number_format($feed['metrics']['scores']) }}</span>
                            </a>
                        @endforeach
                    </nav>
                @endif
            </div>

            {{-- Pipeline: where in the flow something is wrong --}}
            @php($stages = [
                ['setup', 'Interactions', $current['interactions'], 'Your tables and classes'],
                ['scores', 'Scores', $m['lag'] === null ? 'Never ran' : \SolutionForest\Recommender\Dashboard\Health::human($m['lag']).' behind', 'update-scores'],
                ['neighbours', 'Similar items', $m['version'] === null ? 'Never built' : number_format($m['neighbours']).' items', 'build-neighbours'],
                ['serving', 'Serving', $overview['enabled'] ? 'Personalised' : 'Trending only', $overview['locks'] ? 'Cache with locks' : 'Cache without locks'],
            ])
            <div class="card pipeline">
                @foreach ($stages as $i => [$key, $name, $what, $sub])
                    @php($level = $key === 'serving' && ! $overview['enabled'] ? 'warn' : $current['stages'][$key])
                    <div class="stage">
                        <span class="badge {{ $level }}">{!! $icon[$level] !!}</span>
                        <div>
                            <div class="name">{{ $name }}</div>
                            <div class="what">{{ $what }}</div>
                            <div class="name">{{ $sub }}</div>
                        </div>
                        <span class="step">{{ $i + 1 }}/4</span>
                    </div>
                @endforeach
            </div>

            @php($lagRatio = $m['lag'] === null ? 1 : min(1, $m['lag'] / \SolutionForest\Recommender\Dashboard\Health::MAX_SCORE_LAG))
            @php($coverage = $m['scores'] > 0 ? min(1, $m['neighbours'] / $m['scores']) : 0)
            <div class="kpis">
                <div class="card kpi">
                    <div class="label">Checks passing</div>
                    <div class="value">{{ count($passed) }}<small> / {{ count($current['checks']) }}</small></div>
                    <div class="meter"><i class="{{ $current['status'] }}" style="width: {{ count($current['checks']) ? round(count($passed) / count($current['checks']) * 100) : 0 }}%"></i></div>
                    <div class="foot">{{ count($issues) === 0 ? 'Nothing to fix' : count($issues).' need attention' }}</div>
                </div>
                <div class="card kpi">
                    <div class="label">Score lag</div>
                    <div class="value">{{ $m['lag'] === null ? '—' : \SolutionForest\Recommender\Dashboard\Health::human($m['lag']) }}</div>
                    <div class="meter"><i class="{{ $m['lag'] === null ? 'fail' : ($lagRatio >= 1 ? 'warn' : 'ok') }}" style="width: {{ max(4, round($lagRatio * 100)) }}%"></i></div>
                    <div class="foot">{{ $m['watermark'] ? 'Up to '.$m['watermark']->format('M j, H:i') : 'Run update-scores' }} · limit 1h</div>
                </div>
                <div class="card kpi">
                    <div class="label">Scored items</div>
                    <div class="value">{{ number_format($m['scores']) }}</div>
                    <div class="meter"><i style="width: {{ $m['scores'] ? round($m['hot'] / $m['scores'] * 100) : 0 }}%"></i></div>
                    <div class="foot">{{ number_format($m['hot']) }} trending · last event {{ $m['last_event'] ? \Illuminate\Support\Carbon::parse($m['last_event'])->diffForHumans(null, \Carbon\CarbonInterface::DIFF_RELATIVE_TO_NOW, true) : '—' }}</div>
                </div>
                <div class="card kpi">
                    <div class="label">Similar-items coverage</div>
                    <div class="value">{{ round($coverage * 100) }}<small>%</small></div>
                    <div class="meter"><i class="{{ $coverage > 0 ? 'ok' : 'fail' }}" style="width: {{ max(2, round($coverage * 100)) }}%"></i></div>
                    <div class="foot">{{ number_format($m['neighbours']) }} of {{ number_format($m['scores']) }} items have neighbours</div>
                </div>
            </div>

            <div class="cols">
                <div class="card">
                    <div class="card-head"><h2>Trending now</h2><span class="sub">time-decayed popularity</span></div>
                    @if ($current['trending'] === [])
                        <div class="empty">Nothing scored yet.</div>
                    @else
                        <table>
                            <thead><tr><th>#</th><th>Item</th><th style="width:40%">Hot</th><th class="num">Positive</th><th class="num">Exposure</th><th class="num">Rate</th></tr></thead>
                            <tbody>
                            @foreach ($current['trending'] as $i => $row)
                                <tr>
                                    <td class="rank">{{ $i + 1 }}</td>
                                    <td class="item">{{ $row['id'] }}</td>
                                    <td><div class="bar" title="{{ $row['hot'] }}"><i style="width: {{ round($row['hot'] * 100) }}%"></i></div></td>
                                    <td class="num">{{ number_format($row['positive']) }}</td>
                                    <td class="num">{{ number_format($row['exposure']) }}</td>
                                    <td class="num"><span class="rate">{{ $row['exposure'] > 0 ? round($row['positive'] / $row['exposure'] * 100) : 0 }}%</span></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    @endif
                </div>

                <div class="card">
                    <div class="card-head"><h2>Checks</h2><span class="sub">{{ count($issues) }} issue(s)</span></div>
                    <ul class="checks">
                        @forelse ($issues as $check)
                            <li>
                                <span class="{{ $check['level'] }}">{!! $icon[$check['level']] !!}</span>
                                <span class="text">{{ $check['label'] }}
                                    @if ($check['hint'])
                                        <span class="hint">@if (str_starts_with($check['hint'], 'php artisan'))<code>{{ $check['hint'] }}</code>@else{{ $check['hint'] }}@endif</span>
                                    @endif
                                </span>
                            </li>
                        @empty
                            <li><span class="ok">{!! $icon['ok'] !!}</span><span class="text">All checks pass.</span></li>
                        @endforelse
                    </ul>
                    @if ($passed !== [])
                        <details {{ $issues === [] ? 'open' : '' }}>
                            <summary>{{ count($passed) }} passed</summary>
                            <ul class="checks">
                                @foreach ($passed as $check)
                                    <li><span class="ok">{!! $icon['ok'] !!}</span><span class="text">{{ $check['label'] }}</span></li>
                                @endforeach
                            </ul>
                        </details>
                    @endif
                </div>
            </div>

            <div class="card">
                <div class="card-head"><h2>Explain a user</h2><span class="sub">why each item is recommended</span></div>
                <form method="get" class="search">
                    <input type="hidden" name="feed" value="{{ $selected }}">
                    <label>
                        <svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.08 3.08a.75.75 0 1 1-1.06 1.06l-3.08-3.08A7 7 0 0 1 2 9Z" clip-rule="evenodd"/></svg>
                        <input name="user" placeholder="User id, e.g. 42" value="{{ $explain['user'] ?? '' }}" autocomplete="off">
                    </label>
                    <button class="btn primary" type="submit">Explain</button>
                </form>
                @if ($explain === null)
                    <div class="empty">Enter a user id to see each source's share of every recommendation.</div>
                @elseif ($explain['error'])
                    <div class="error">{{ $explain['error'] }}</div>
                @elseif ($explain['rows'] === [])
                    <div class="empty">No candidates for user {{ $explain['user'] }} — they would see trending.</div>
                @else
                    <table>
                        <thead><tr><th>#</th><th>Item</th><th style="width:45%">Score breakdown</th><th class="num">Score</th><th>Sources</th></tr></thead>
                        <tbody>
                        @foreach ($explain['rows'] as $i => $row)
                            <tr>
                                <td class="rank">{{ $i + 1 }}</td>
                                <td class="item">{{ $row['id'] }}</td>
                                <td>
                                    <div class="stack" style="width: {{ max(8, round($row['score'] / $maxScore * 100)) }}%">
                                        @foreach ($row['contributions'] as $source => $value)
                                            <i class="c{{ $legend[$source] }}" style="flex: {{ max(0.0001, $value) }}" title="{{ $source }} +{{ $value }}"></i>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="num">{{ $row['score'] }}</td>
                                <td class="why">@foreach ($row['sources'] as $source => $value){{ $source }} <span class="mono">{{ $value }}</span>@if (! $loop->last) · @endif @endforeach</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                    <div class="legend">
                        @foreach ($legend as $source => $color)
                            <span><i class="c{{ $color }}"></i>{{ $source }}</span>
                        @endforeach
                        <span style="margin-left:auto">Fresh build before exploration and diversity; may differ slightly from the cached page.</span>
                    </div>
                @endif
            </div>
        @endif
    </main>
</div>

<script>
    (function () {
        var refresh = {{ (int) $refresh }};

        function wire() {
            var live = document.getElementById('recommender-live');
            if (live) {
                live.checked = localStorage.getItem('rec-live') !== 'off';
                live.onchange = function () { localStorage.setItem('rec-live', live.checked ? 'on' : 'off'); };
            }
            var theme = document.getElementById('recommender-theme');
            if (theme) theme.onclick = function () {
                var root = document.documentElement;
                var dark = root.dataset.theme ? root.dataset.theme === 'dark' : matchMedia('(prefers-color-scheme: dark)').matches;
                root.dataset.theme = dark ? 'light' : 'dark';
                localStorage.setItem('rec-theme', root.dataset.theme);
            };
        }

        function ago() {
            var app = document.getElementById('recommender-app'), out = document.getElementById('recommender-updated');
            if (!app || !out) return;
            var s = Math.max(0, Math.round(Date.now() / 1000 - app.dataset.updated));
            out.textContent = s < 5 ? 'Updated just now' : 'Updated ' + (s < 60 ? s + 's' : Math.round(s / 60) + 'm') + ' ago';
        }

        wire(); ago(); setInterval(ago, 1000);
        if (refresh <= 0) return;

        // Live refresh without a JS framework: re-fetch this page and swap the app.
        setInterval(function () {
            var live = document.getElementById('recommender-live');
            if (!live || !live.checked || document.hidden || (document.activeElement && document.activeElement.name === 'user')) return;
            fetch(location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (!html) return;
                    var next = new DOMParser().parseFromString(html, 'text/html').getElementById('recommender-app');
                    if (next) { document.getElementById('recommender-app').replaceWith(next); wire(); ago(); }
                })
                .catch(function () {});
        }, refresh * 1000);
    })();
</script>
</body>
</html>
