# Suggested improvements

Proposals for making the first feed easier to reach and maintenance predictable. All five are done; the remaining wishlist lives in [operations → roadmap](operations.md#roadmap--wishlist).

| Priority | Proposal | Why it helps | Suggested acceptance check |
|---|---|---|---|
| 1 | ✅ Done — `recommender:doctor` and the dashboard. Add a `recommender:doctor` check for configured classes, tables, columns, event counts and cache locks. | The wizard cannot tell whether generated queries match a real schema; failures otherwise surface during a build. | A fixture with a missing column produces an actionable message and a nonzero exit code. |
| 2 | ✅ Done — `recommender:rebuild-scores {feed} --days= --dry-run`, confirmation, interruption marker. Offer a guided, feed-scoped score rebuild command with a dry run, explicit confirmation and backfill window. | The current incremental watermark makes manual replay easy to double-count; shared tables make broad deletes risky. | Tests cover two feeds, interrupted rebuilds and a replay with no double-counting. |
| 3 | ✅ Done — `make-feed` registers a config-only feed (any id type; neighbours from `positive_events` / `history_events`); `--classes` keeps the old scaffold. Allow the scaffold to select strong events for neighbour histories and support non-integer IDs. | The generated source currently includes views in histories and casts IDs to integers; either may be wrong for an app. | Generated sources work with UUID fixtures and configurable history event types. |
| 4 | ✅ Done — explain rows include `final` (after exploration + diversity), `cached` and `exploration`. Make `explain` report the final exploration/diversity order and distinguish it from the cached page. | Users can otherwise expect its pre-diversity output to match what they see. | A test with exploration and diversity makes both order and cache state clear. |
| 5 | ✅ Done — [hiding an item](reference.md#hiding-an-item-visibility-changes): `Recommendable` forgets cards on save; pages skip items without a card. Provide an explicit cache invalidation recipe or API for visibility changes. | Lists and cards can outlive a publication or permission change, while filtering occurs at build time. | A test hides an item after caching and verifies that the documented invalidation removes it. |

Follow the [tutorial](tutorial.md), [reference](reference.md), and [operations guide](operations.md).
