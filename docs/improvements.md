# Suggested improvements

These are proposals, not features available today. They focus on making the first successful feed easier to reach and keeping maintenance predictable.

| Priority | Proposal | Why it helps | Suggested acceptance check |
|---|---|---|---|
| 1 | ✅ Done — `recommender:doctor` and the dashboard. Add a `recommender:doctor` check for configured classes, tables, columns, event counts and cache locks. | The wizard cannot tell whether generated queries match a real schema; failures otherwise surface during a build. | A fixture with a missing column produces an actionable message and a nonzero exit code. |
| 2 | Offer a guided, feed-scoped score rebuild command with a dry run, explicit confirmation and backfill window. | The current incremental watermark makes manual replay easy to double-count; shared tables make broad deletes risky. | Tests cover two feeds, interrupted rebuilds and a replay with no double-counting. |
| 3 | Allow the scaffold to select strong events for neighbour histories and support non-integer IDs. | The generated source currently includes views in histories and casts IDs to integers; either may be wrong for an app. | Generated sources work with UUID fixtures and configurable history event types. |
| 4 | Make `explain` report the final exploration/diversity order and distinguish it from the cached page. | Users can otherwise expect its pre-diversity output to match what they see. | A test with exploration and diversity makes both order and cache state clear. |
| 5 | Provide an explicit cache invalidation recipe or API for visibility changes. | Lists and cards can outlive a publication or permission change, while filtering occurs at build time. | A test hides an item after caching and verifies that the documented invalidation removes it. |

Keep changes to the public API and data migration behavior reviewable separately. Until these exist, follow the [tutorial](tutorial.md), [reference](reference.md), and [operations guide](operations.md).
