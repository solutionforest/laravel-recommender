# How we score (maths)

This page is the **formulas** behind recommendation — not a separate product feature.

Plain-language pipeline first: [README → How we recommend](../README.md#how-we-recommend) and [How it works](how-it-works.md).

Not a black-box neural ranker. Classic, explainable ideas you can show with `recommender:explain`.

| Idea | Where | Classical lineage |
|---|---|---|
| Item–item CF | `build-neighbours`, `CoInteractionSource`, `similar()` | Amazon item-to-item CF (Linden et al.) |
| Cosine on binary sets | Neighbour similarity | Standard IR / CF |
| Exponential half-life | `half_life_days`, `hot` | HN / Reddit-style time decay |
| Incremental log-sum-exp | `Math::logAddExp` / `hotTerm` | One number per item, *O(1)* updates |
| Wilson lower bound | `QualityBooster` | Reddit ranking (Evan Miller / Wilson) |
| Linear blend | `Blender` | Multi-objective `Σ w·s` |
| Jitter (optional) | `Jitter`, `jitter` | Score + uniform noise, drawn once per cached list |
| Exploration interleave | `Explorer` | Lightweight explore/exploit |
| Diversity caps | `Diversifier` | Max N per shop/author per page |

## 1. Item–item CF

Nightly, for each item *a*, top neighbours *b*:

$$
\mathrm{sim}(a,b)=\frac{|U_a \cap U_b|}{\sqrt{|U_a|\,|U_b|}}
$$

where $U_x$ is users who interacted with *x* (within `history_days`, capped at `history_per_user` items per user). Pairs with fewer than `neighbours_min_co_count` shared users are dropped, and each item keeps its top `neighbours_max` neighbours.

At request time, `CoInteractionSource` loads the user’s `recent_items` most recent items and scores every neighbour the user has not interacted with:

$$
\mathrm{score}(c)=\sum_{i \in \mathrm{recent}} \mathrm{sim}(i,c)\cdot \frac{1}{1+0.1\cdot \mathrm{rank}(i)}
$$

where $\mathrm{rank}(i)$ is 0 for the most recent item, 1 for the next, and so on.

**Similar items** (`$post->similar(n)`) use the same neighbours directly: the top *n* items *b* by $\mathrm{sim}(a,b)$ for the item *a*, that pass the feed's `recommendable` scope as a guest. No viewer is involved, so the result is the same for everyone and cached per item.

## 2. Trending `hot`

$$
\mathrm{pop}(T)=\sum_k w_k\, e^{-\lambda (T-t_k)},\quad \lambda=\frac{\ln 2}{H}
$$

Equivalent to ranking by $\mathrm{hot}=\log\sum_k w_k\, e^{\lambda t_k}$. New events:

$$
\mathrm{hot} \leftarrow \mathrm{logAddExp}(\mathrm{hot},\ \log w + \lambda\cdot \mathrm{days}(t))
$$

## 3. Wilson quality

Raw like-rate over-rewards tiny samples. 95% Wilson lower bound ($z \approx 1.96$):

$$
\frac{p+\frac{z^2}{2n}-z\sqrt{\frac{p(1-p)+\frac{z^2}{4n}}{n}}}{1+\frac{z^2}{n}}
$$

Then scale so the best candidate in the batch is `1.0`.

## 4. Blend, jitter, explore, diversify

- **Blend:** scores ÷ source max → `Σ weight · score`, then boosters add `Σ weight · boost` (boost clamped to 0..1) to candidates the sources already returned.
- **Jitter** (optional, `jitter` = *j* in 0..1, default 0): once per list build, each candidate gets

$$
\mathrm{final}(c)=(1-j)\cdot\frac{\mathrm{score}(c)}{\max \mathrm{score}}+j\cdot u_c,\quad u_c\sim U(0,1)
$$

  and the list is re-sorted by it. $j=0$ keeps the ranking; $j=1$ is a uniform random order of the candidates; values in between keep strong items near the top while the order changes between builds. The order is fixed while the list is cached, so paging does not repeat. The shared trending list is never jittered.
- **Explore:** when `exploration_sources` are configured (none in the package defaults), every round(1 / `exploration_ratio`)-th slot (at most every 2nd) takes the next item from the shuffled exploration pool, skipping items already ranked.
- **Diversify:** greedy cap per group; overflow to later pages (caps are relaxed when a page cannot be filled otherwise).

## 5. Helpers for your custom sources (`Math`)

When you write a `CandidateSource` / `Booster`, return **any positive floats** — the blender normalises each source. You do **not** need perfect ML scores. Pick a helper that matches the intent:

| Intent | Helper | Typical use |
|---|---|---|
| Query order alone (row 0 best) | `Math::fromPosition($i)` | Soonest events, nearest matches |
| Preference strength × soft order | `Math::fromAffinity($w, $i, step: 0.05)` | Favorite type/host × starts_at order |
| Newer is better | `Math::fromAge($age, $halfLife)` | Fresh posts: `exp(−age / halfLife)`, ≈ 0.37 after `halfLife` (same unit as `$age`; a time constant, so the score halves after ≈ 0.69 · `halfLife`) |
| Scale batch so max = 1 | `Math::normalizeMax($scores)` | Boosters comparing counts |
| Whole ordered query → scores | `Math::scoreInOrder($rows)` / `scoreInOrder($rows, fn ($row) => $weight)` | Custom sources without a scoring loop |
| Soft-boost preferred attributes | `Math::preferRanked($ids, $favoriteKeys, $attrById)` | Boosters (“favorite type/host”) |

**How to choose**

1. Ordered SQL (`orderBy starts_at`) and every row is “equally matching”? → `scoreInOrder($rows)`.
2. Rows share a weight (join count for a type) but you still want a light order nudge? → `scoreInOrder($rows, fn ($row) => $weight)`.
3. Time since publish/start matters more than list index? → `fromAge`.
4. Unsure? Start with `scoreInOrder`; tune feed **weights** in config before inventing formulas.

See `examples/events-advanced` (`JoinPreferences`, Event scopes, these helpers).

## What we are *not* claiming

No deep learning, two-tower retrieval, or online bandits (`jitter` is plain randomness, not a learned explore/exploit policy). For sparse first-party like/view data this interpretable baseline is often the right production default. You can still plug a learned `CandidateSource` later.

Unit coverage: `tests/Unit/ScoringTest.php`, `tests/Unit/JitterTest.php`.
