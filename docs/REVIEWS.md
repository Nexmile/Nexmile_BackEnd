# Ratings — the food, the dishes and the delivery

**For the app developers.** Everything here is live on the server.

One review per delivered order, inside a window. A review can carry four
different things, and all but the first are optional: a score for the food,
scores for individual dishes, tags, and a separate verdict on the rider.

---

## Leaving one

```
POST /v1/orders/{id}/review
```

```json
{
  "rating": 4,
  "comment": "Biryani was great",
  "dishes": { "41": 5, "44": 3 },
  "tags": ["tasty", "good_packaging"],
  "rider": {
    "rating": 5,
    "tags": ["on_time", "polite"],
    "comment": "Thanks!"
  }
}
```

`rating` is the only required field. Answers `201` with the saved review.

| Field | Notes |
|---|---|
| `rating` | 1–5, the food and the restaurant |
| `comment` | up to 1000 characters, **public** |
| `dishes` | `menu_item_id` → 1–5. A dish that was not on the order is dropped silently |
| `tags` | food tag codes — see the vocabulary below |
| `rider.rating` | 1–5 |
| `rider.tags` | delivery tag codes |
| `rider.comment` | up to 300 characters, **private** |

`rider_rating` at the top level is the older flat form. Still accepted, so a
build already shipped with it keeps working. When both arrive, `rider.rating`
wins.

### The rider block is dropped on a collected order

Not ignored — dropped. A pickup order has no rider, and a stored complaint
about a rider who never existed is a row somebody will one day read as if it
meant something. Don't send the block for pickup; if you do, it saves as null
and the response shows that.

### Refusals

All `422`, with a message in `errors` that is written to be shown to the
customer as it is.

| Case | Key |
|---|---|
| Not delivered yet | `order` |
| Already reviewed | `order` |
| Past the window | `order` — the message names the number of days |
| Unknown food tag | `tags` |
| Unknown delivery tag | `rider.tags` |

Tag errors are reported against the field, not the index: `tags`, never
`tags.2`. An unknown code means the app is ahead of the server, and the message
names the codes it did not recognise.

---

## The window

**Seven days from delivery** (`reviews.window_days`). After that the endpoint
refuses and `can_review` is false.

A rating is evidence about one meal on one evening. Five weeks later it is
memory, and it lands on a rider or a kitchen that has had two hundred orders
since and cannot act on it.

---

## Tag vocabulary

**Codes, not labels.** The apps hold the translations, so renaming "tasty" in
Tamil is a client change rather than a migration.

Tags are grouped by **subject**, and the subject is enforced: a delivery code
sent as a food tag is a `422`. Putting `food_spilled` on a kitchen's report is
not an opinion, it is a mistake.

### Food — `tags`

| Praise | Issues |
|---|---|
| `tasty` | `cold_food` |
| `fresh_hot` | `poor_taste` |
| `good_portion` | `small_portion` |
| `good_packaging` | `wrong_items` |
| `value_for_money` | `missing_items` |
| `hygienic` | `poor_packaging` |
| | `too_oily` |

### Delivery — `rider.tags`

| Praise | Issues |
|---|---|
| `on_time` | `late` |
| `polite` | `rude` |
| `followed_instructions` | `didnt_follow_instructions` |
| `careful_handling` | `food_spilled` |
| `good_communication` | `asked_extra_money` |
| | `unprofessional` |

Up to **eight per subject** (`reviews.max_tags`).

**Which tags you offer at which star count is your call.** The server does not
tie praise to 4–5 and issues to 1–3. Four stars with `too_oily` is a customer
telling the truth, and refusing it would lose the sentence as well as the tag.

---

## What is public and what is not

The split matters more here than anywhere else in the API, because one half of
a review is about a business and the other is about a person.

| | Public | Where it goes |
|---|:--:|---|
| `rating`, `comment`, `tags` | ✅ | the restaurant's review list, its score |
| `dishes` | ✅ | each dish's own rating on the menu |
| `rider.rating` | partly | feeds the rider's `rating` and `rating_count` on `/track`; the individual score is not published |
| `rider.tags`, `rider.comment` | ❌ | ops, and the rider's record |

`GET /restaurants/{id}/reviews` carries the food half only — `rating`,
`comment`, `tags`, `dishes` and a **first name**. It does not carry the rider's
tags or comment under any circumstances, and a test asserts the strings never
appear in that response.

The reason is not squeamishness. On a restaurant's page `rude` names nobody. On
a rider's it is an accusation against one worker who has no way to answer it
there, and the rider is the person in this system with the least protection
from a number nobody sanity-checks. A rating a customer takes the trouble to
give should reach someone who can act on it — which is ops, not strangers.

`asked_extra_money` and `rude` are the two codes that ought to page a person
(`reviews.rider_tags_needing_review`). They are stored against the rider today;
**nothing alerts anybody yet**, because there is no channel that reaches ops.
The list is named in config so that when there is one, the decision about which
tags are serious is already made and written down.

---

## Reading it back

### On an order

`GET /v1/orders` and `GET /v1/orders/{id}` carry three fields for the history
card:

```json
{
  "review": { "rating": 4, "rider_rating": 5 },
  "can_review": false,
  "rider": { "name": "Murugan", "photo_url": null }
}
```

- **`review`** is `null` when the order has not been rated, so "not rated" is
  distinguishable from "rated 0". Only the two numbers — the comment and the
  tags are on the single-review endpoint, and a history list has no business
  carrying twenty people's sentences.
- **`can_review`** is `true` while the order is delivered, inside the window
  and not yet rated. Answered by the same code that accepts or refuses the
  review, so the button and the endpoint cannot disagree.
- **`rider`** is present on delivered orders as well as live ones, so a card
  can name who is being rated a day later. Only a name: the phone number
  belongs on the live tracking screen while the delivery is happening.

**`photo_url` is always `null` today.** There is no rider photo. Riders upload
KYC documents, which include a face, and serving one of those as a profile
picture would publish an identity document. The key is there so the app can
drop its placeholder the day a real photo exists.

### The full review

```
GET /v1/orders/{id}/review
```

`data` is null until there is one. Otherwise everything that was saved,
including the rider block — showing somebody what they themselves wrote a
moment ago is not publishing it.

### A restaurant's reviews

```
GET /v1/restaurants/{id}/reviews?with_comment=1
```

Paginated, newest first, plus `meta.rating`, `meta.rating_count` and
`meta.breakdown` — the histogram behind the score, because a 4.2 built from
forty fives and ten ones is a different restaurant from a 4.2 where everybody
said four.

---

## Thresholds

A restaurant, a dish and a rider all need **three ratings** before a score is
published (`ReviewService::MIN_RATINGS_TO_PUBLISH`). Below that, `rating` is
`null` rather than a number — not `0.0`.

One five-star review from the owner's cousin is not a rating, and a single bad
night should not brand a new kitchen 1.0 forever. Show nothing on null; the
badge is worth more when it is sometimes absent.

Aggregates are **recomputed from the table**, never nudged arithmetically, so
hiding an abusive review moves every score it was holding up.

---

## The reminder push

`order.review_requested`, about 45 minutes after delivery
(`reviews.reminder_after_minutes`), with `order_id` in the data — the same
shape as every other order push, so an app that opens the order for any of
them needs no new handling.

Sent only if the order is still ratable when the job fires: already rated,
refunded out of `delivered`, or past the window and it is dropped. Nagging
somebody about an order they have already rated is the push that teaches them
to turn notifications off.

The `order.delivered` push already says "tap to rate", and it arrives while the
customer is at the door with their hands full. This is the ask that actually
gets answered.

**It needs the queue worker.** No worker, no reminder — nothing else covers for
it, the same choice dispatch makes and for the same reason.
