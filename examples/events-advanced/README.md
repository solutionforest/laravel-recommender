# Example: advanced events

Standalone copy of [`../events`](../events/) (view / like / join → recommended events), **plus** product rules so **district**, **hosting party**, **event type**, and **comments** all shape the ranking.

If you only need the simple feed, use [`../events`](../events/) instead.

| Signal | Affects recommendation? | How |
|---|---|---|
| Event `district_id` + user living district | Yes | `SameDistrictSource` |
| Event `host_id` (party / organiser) | Yes | `FavoriteHostSource` + `PreferredHostBooster` (most-joined hosts) |
| Event `type_id` | Yes | `FavoriteTypeSource` + `PreferredTypeBooster` (most-joined types) |
| Comments (`event_comments`) | Yes | `comment` interaction (trending + CF) + `CommentHeatBooster` (busy threads) |
| view / like / join | Yes | Same as events (`join` strongest; `comment` between like and join) |

Covered by [`EventsAdvancedExampleTest`](../../tests/Feature/EventsAdvancedExampleTest.php).

```mermaid
flowchart LR
  V[(views)] & L[(likes)] & C[(comments)] & J[(joins)] --> I[EventInteractionSource]
  UD[(user_districts)] --> D[SameDistrictSource]
  J --> T[FavoriteTypeSource]
  J --> H[FavoriteHostSource]
  C --> B[CommentHeatBooster]
  I --> Jobs[scores / neighbours]
  Jobs --> R[Recommender]
  D --> R
  T --> R
  H --> R
  B --> R
  E[(events)] --> F[VisibleEventFilter] --> R
  R --> API["GET /events/recommended"]
```

## Tables

Same as the events example, with extras:

| Table / column | Role |
|---|---|
| `events.host_id` | Hosting party / organiser |
| `events.district_id` / `type_id` | Location + category |
| `event_views` / `_likes` / `_joins` | Same as events |
| `event_comments` | Discussion — each row is a `comment` event |
| `user_districts` | Where the user lives |

## Try it

```bash
vendor/bin/pest tests/Feature/EventsAdvancedExampleTest.php
```

## Layout

```
src/
  Models/Event.php
  Interactions/EventInteractionSource.php
  Sources/          # SameDistrict, FavoriteType, FavoriteHost, Fresh
  Boosters/         # PreferredType, PreferredHost, CommentHeat
  Filters/VisibleEventFilter.php
  Cards/EventCardTransformer.php
  Groups/EventHostGroups.php
  Http/RecommendedEventsController.php
  Support/JoinPreferences.php
```

## Write sources like English

Custom sources return floats; the blender normalises them. This example uses:

| Piece | Role |
|---|---|
| `Event::recommendable(...)` | published + upcoming + not own host |
| `JoinPreferences::types()` / `hosts()` | “what this user joins most” |
| `Math::scoreInOrder($events)` | ordered query → scores |
| `Math::scoreInOrder($events, fn ($e) => $weight)` | affinity × soft order |
| `Math::preferRanked(...)` | booster for favorite type/host |
| `Math::normalizeMax(...)` | comment heat scaled to max = 1 |

Full guide: [docs/maths.md §5](../../docs/maths.md). Tune **config source weights** before changing formulas.

## Use in your app

1. Copy `src/` folders → `app/Recommendation/Events` (or keep `EventsAdvanced`).
2. Merge feed `events` into `config/recommender.php`.
3. Map `host_id` / `district_id` / `type_id` / comments to your schema.
4. Tune weights: raise district for local apps, host for organiser loyalty, type for taste, `comment` / `CommentHeatBooster` when discussion matters.
