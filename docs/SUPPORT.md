# In-app support — the bubble

**For the app developers.** Everything here is live on the server.

A chat bubble in the customer app. The customer types a question, an AI
answers it using their real order, and when it cannot help the thread is
handed to a person.

Nexmile holds none of it. Threads, messages and the AI live on the **Nexmile
BPO platform**, a separate application with its own database. This API
proxies, so the app has one base URL, one auth scheme and one token — the same
one it already holds.

---

## Endpoints

All under `/v1/support`, all behind the customer's own Sanctum token.

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/v1/support/entry` | what the bubble shows before it is tapped |
| `POST` | `/v1/support/conversations` | open the thread; optional `order_id` |
| `GET` | `/v1/support/conversations/{id}` | the thread with its messages |
| `GET` | `/v1/support/conversations/{id}/messages` | `?after_id=` — only what is new |
| `POST` | `/v1/support/conversations/{id}/messages` | send; answers **202** |
| `POST` | `/v1/support/conversations/{id}/handover` | ask for a person |
| `POST` | `/v1/support/conversations/{id}/close` | mark it resolved |
| `POST` | `/v1/support/messages/{id}/feedback` | `{"value": "up"}` or `"down"` on an AI reply |

### A message

```json
{
  "id": "0193f2a1-7c3d-7b9e-a4e1-2f8c6d5b4a30",
  "sender": "ai",
  "content": "Your order was handed to the rider and is on its way.",
  "created_at": "2026-11-24T19:44:10+05:30",
  "agent_name": null,
  "actions": [{ "code": "track_order", "order_id": 10432, "label": "Track it" }],
  "feedback": null
}
```

`sender` is `customer`, `ai`, `agent` or `system`. **Render all four, and never
style `ai` and `agent` the same.** A customer who thinks they have reached a
person has been misled, whatever the transcript says afterwards.

`agent_name` is a first name and only on `agent`. No surname, no photo.

`label` on an action arrives translated. **Skip any `code` you do not
recognise, silently** — that is what lets a new action ship without an app
release, the same rule as the review tags and the delivery instruction chips.

### Opening one

```json
POST /v1/support/conversations
{ "order_id": 10432 }
```

Idempotent. One open thread per customer, so call it from the bubble, from an
order screen and from the help menu without checking first.

**A different `order_id` switches the thread's subject** and adds a `system`
line saying so. It used to keep the first order and ignore the second, which
meant a customer with yesterday's thread still open got answers about
yesterday's dinner.

**Pass `order_id` wherever an order is on screen.** It is the difference
between "how can I help?" and an answer about the order they are looking at.
An order that is not theirs is dropped rather than refused — they still get a
thread, it simply has no order attached.

### Sending one

```json
POST /v1/support/conversations/{id}/messages
{ "content": "where is my order", "client_id": "a-uuid-you-generate" }
```

**`202`, and the body is the customer's own message** — not the reply. A model
takes seconds to answer, and holding a request open for that fails on the
networks this product serves.

`client_id` is a uuid per message, the same one on a retry. It is what lets a
timeout that already reached the server be told apart from a customer tapping
send twice. Without it, a flaky connection shows the question twice and pays
for two answers.

Throttled to `support.messages_per_minute` (20) per customer. Each message may
cost a model call, so that is a cap on a bill as much as on load.

---

## The reply does not come back with the request

```
POST .../messages            → 202, your own message
GET  .../messages?after_id=  → [] until the reply lands, then the reply
```

**Poll every 2 seconds while a reply is outstanding, and stop after 30
seconds.** Nothing faster — the answer cannot arrive sooner. Nothing slower —
the customer is watching. At 30 seconds show a retry rather than polling
forever, because a dead job would leave the phone polling all evening.

**When nothing is outstanding, do not poll at all.** An idle thread that polls
is a battery complaint and a bill.

`meta.pending_reply` on the thread and on the poll says whether a job is in
flight, so an app killed and reopened mid-answer can restore the typing
indicator instead of showing a thread that looks finished.

**After a timeout, resend the same message with the same `client_id`.** It
will not be stored twice, and if nothing is in flight and nothing has answered
it, the server queues the reply again. That covers the one case polling cannot:
a worker killed mid-job, which leaves no failure to catch and no reply that
will ever arrive.

### unread, and what marks it read

`GET /v1/support/entry` carries `unread`: replies the customer has not looked
at. Counted from when they last **opened** the thread, not from when they last
spoke — somebody who reads an answer and is satisfied says nothing, and a
badge that never clears is one people learn to ignore.

| Call | Marks read? |
|---|:--:|
| `GET /v1/support/entry` | ❌ — glancing at a home screen is not reading |
| `POST /v1/support/conversations` | ✅ — this is the deliberate "open the chat" |
| `GET /v1/support/conversations/{id}` | ✅ |
| `GET .../messages` (polling) | ❌ |

**So opening the chat needs no second call.** Both responses still report the
count they are about to clear, so a badge can be animated away rather than
vanishing before it was seen.

`entry` creates nothing. A customer who has never asked for help gets
`unread: 0` and `has_open_thread: false`, which is the ordinary case and not
an error.

### When the app is backgrounded

```json
{ "type": "support.reply", "conversation_id": "…" }
```

Tap opens the thread. Same shape as every order push. **No message text** — a
notification is read by whoever is holding the phone, and a support thread can
be about a complaint, a missing delivery or money. Not sent while the app is
in the foreground.

---

## Three rules

**1. Say which is which.** The AI gets a visible label on every message, not a
different shade of grey. The agent gets a human first name. They must not
share a bubble style or an avatar.

**2. The AI's sentences are never data.** Render `content` as the AI's text.
But a status chip, an ETA, a refund amount or a rider's name comes from
`GET /v1/orders/{id}` or `/track` — never from parsing the reply. A model can
be confidently wrong, and the moment the app treats its prose as data, a
hallucinated "your refund has been processed" becomes something a customer
reasonably believes. Never regex a number out of `content`.

**3. The handover promise is the server's.** The handover response carries
`system_message` — render that string. There are no agents on shift yet, so
the reply window has to be honest and changeable from the server. An app that
hard-codes "an agent will be with you shortly" makes a promise neither end can
keep.

---

## When it goes wrong

| Situation | Status | What to show |
|---|---|---|
| Support platform unreachable | `503` | `message` verbatim, plus the phone number |
| Too many messages | `429` | "give it a moment" — do not auto-retry |
| Thread closed | `409` | offer to start a new one |
| Support platform up, model down | `202` | normal. The thread hands over to a person and the message is kept |
| No network | — | keep the message in the thread marked **not sent**, with retry |

The last one matters most. A customer whose message vanished will send it
again and then conclude nobody is reading. Keep it on screen until the server
has it.

**Never auto-retry more than twice**, and never without backoff. `client_id`
protects the server from duplicates, but each attempt against an AI endpoint
costs real money.

---

## What is not built

- **No photo attachments.** The obvious next thing — a picture of the wrong
  dish settles a refund in one message — and it needs storage, size limits and
  a decision about how long a photo of somebody's dinner is kept.
- **No live agents.** Handover creates a thread a person reads. Do not show
  "an agent is joining".
- **No websockets.** Polling, like the tracking screen. There is no Reverb or
  Pusher deployment and no users yet to justify one.
- **Refunds are impossible here, not deferred.** Nothing outside Nexmile can
  move money, on purpose, and there is no action code that would.

---

## The support phone number

```
GET /v1/app/config
```

**No token.** Somebody who cannot sign in is exactly the person who needs to
phone a human, so this sits in front of the auth wall.

```json
{ "data": {
  "support": { "phone": "+914522000000", "email": "…", "chat_enabled": true },
  "locales": ["en", "ta", "hi"]
} }
```

`phone` is **null until a real number exists**. Hide the call button on null
rather than dialling something invented — a support number that rings nowhere
spends somebody's last patience.

`chat_enabled` is the chat's deliberate off switch, server-side. It is not the
same as `available` on `/v1/support/entry`, which reports that the platform
cannot be reached. `chat_enabled: false` means somebody turned it off: hide
the bubble entirely rather than showing a broken one. It is there so the chat
can be switched on, or off, without an app release.

Read it at startup and cache it. Nothing in it is per-customer.

## For whoever operates this

The support platform needs two things or the bubble answers nothing:

- `BPO_SERVICE_TOKEN`, the same value in both applications' `.env`.
- A **queue worker on the BPO side**. Replies are generated in a job; with no
  worker, no reply is ever written and every thread times out into a handover.

With no `ANTHROPIC_API_KEY` set on the BPO, the platform still works — as a
ticket system. A customer types, it is stored, and a person is told. That is
the degraded path the app has to handle anyway, so it is the default rather
than a failure mode nobody has seen.
