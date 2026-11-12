# How we score (maths)

This page is the **formulas** behind recommendation — not a separate product feature.

Plain-language pipeline first: [README → How we recommend](../README.md#how-we-recommend) and [How it works](how-it-works.md).

Not a black-box neural ranker. Classic, explainable ideas you can show with `recommender:explain`.

| Idea | Where | Classical lineage |
|---|---|---|
| Item–item CF | `build-neighbours`, `CoInteractionSource` | Amazon item-to-item CF (Linden et al.) |
| Cosine on binary sets | Neighbour similarity | Standard IR / CF |
| Exponential half-life | `half_life_days`, `hot` | HN / Reddit-style time decay |
| Incremental log-sum-exp | `Math::logAddExp` / `hotTerm` | One number per item, *O(1)* updates |
| Wilson lower bound | `QualityBooster` | Reddit ranking (Evan Miller / Wilson) |
| Linear blend | `Blender` | Multi-objective `Σ w·s` |
| Exploration interleave | `Explorer` | Lightweight explore/exploit |
| Diversity caps | `Diversifier` | Max N per shop/author per page |

## 1. Item–item CF

Nightly, for each item *a*, top neighbours *b*:

$$
\mathrm{sim}(a,b)=\frac{|U_a \cap U_b|}{\sqrt{|U_a|\,|U_b|}}
$$

where $U_x$ is users who interacted with *x* (within `history_days`, capped per user).

At request time, `CoInteractionSource` loads the user’s recent items and scores:

$$
\mathrm{score}(c)=\sum_{i \in \mathrm{recent}} \mathrm{sim}(i,c)\cdot \frac{1}{1+0.1\cdot \mathrm{rank}(i)}
$$

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

## 4. Blend, explore, diversify

- **Blend:** scores ÷ source max → `Σ weight · score`.
- **Explore:** ~`exploration_ratio` slots from `exploration_sources`.
- **Diversify:** greedy cap per group; overflow to later pages.

## 5. Helpers for your custom sources (`Math`)

When you write a `CandidateSource` / `Booster`, return **any positive floats** — the blender normalises each source. You do **not** need perfect ML scores. Pick a helper that matches the intent:

| Intent | Helper | Typical use |
|---|---|---|
| Query order alone (row 0 best) | `Math::fromPosition($i)` | Soonest events, nearest matches |
| Preference strength × soft order | `Math::fromAffinity($w, $i, step: 0.05)` | Favorite type/host × starts_at order |
| Newer is better | `Math::fromAge($age, $halfLife)` | Fresh posts (`halfLife` in same unit as `$age`) |
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

No deep learning, two-tower retrieval, or online bandits. For sparse first-party like/view data this interpretable baseline is often the right production default. You can still plug a learned `CandidateSource` later.

Unit coverage: `tests/Unit/ScoringTest.php`.
