# The Nexmile service API

Private. Not served by the docs allowlist.

Section 9 of the AI BPO Technical Blueprint, implemented on the Nexmile side.
This is the Nexmile API Owner's deliverable: *"approved contracts, test
environment, service credentials, webhook schemas and integration acceptance."*

Base path `/api/internal/v1`. It is a different door from `/api/v1` on purpose.
The apps' API answers for whoever holds the token; this one answers about
somebody else entirely, which is a different enough promise to deserve its own
entrance, its own credentials and its own log.

## Why this had to be built before anything else

The blueprint says it plainly in §9: *"All endpoint names above are proposed
interface contracts, not assertions that these Nexmile APIs already exist."*
They did not. Before this commit the platform had:

- **no machine-to-machine authentication of any kind.** Every token belonged to
  a human who had passed a one-time password. Sanctum supports abilities;
  nothing used them.
- **no endpoint that returns another person's data.** Every controller scopes to
  `$request->user()`, which is right, and which means a support agent could not
  read the customer's order they are being asked about.
- **no access log anywhere.** The admin panel has never had one.

## Authentication

A `ServiceClient` holding a Sanctum token with abilities. Tokenable is
polymorphic already, so no new credential storage was invented — the only new
idea is that a token may belong to a service rather than a user.

```
Authorization: Bearer <token>
```

### Minting and revoking

```
php artisan nexmile:make-service-client --name="BPO platform" --scopes=orders.read,customers.read
php artisan nexmile:revoke-service-client bpo-platform
```

There is no screen for this. A credential that can read any customer's orders
should be issued deliberately, by somebody with shell access, and `revoke`
both deletes the tokens **and** suspends the client — deleting tokens alone
leaves something that works again the moment anyone mints a new one, which is
not what "revoke" means to the person typing it at two in the morning.

A scope that no route checks is refused at minting time. A scope nothing
enforces reads like a restriction and is not one, and the operator would
otherwise discover the typo in production.

### Scopes

| Scope | Grants |
| --- | --- |
| `customers.read` | A customer's identity and contact details |
| `orders.read` | An order, its status and timestamps; a customer's recent orders |
| `merchants.read` | A restaurant's profile and operating state |
| `riders.read` | The delivery state of an order |
| `support.notify` | Ask Nexmile to tell a customer their support thread has a reply |
| `tickets.write` | Raise a support case against an order |
| `refunds.check` | Ask whether an order is eligible for a refund |

## Endpoints

| Method | Path | Scope |
| --- | --- | --- |
| GET | `/customers/{customer}` | `customers.read` |
| GET | `/customers/{customer}/orders` | `orders.read` |
| GET | `/orders/{order}` | `orders.read` |
| GET | `/merchants/{merchant}` | `merchants.read` |
| GET | `/orders/{order}/delivery-status` | `riders.read` |
| POST | `/support-notifications` | `support.notify` |
| POST | `/support-tickets` | `tickets.write` |
| POST | `/refunds/eligibility-check` | `refunds.check` |

### Two departures from the blueprint's proposed names

**Delivery status is keyed on the order, not the rider.** §9 proposes
`GET /riders/{id}/delivery-status`. A support system has no business asking
where a named rider is; it has every business asking where the food on order
4821 has got to. Those are different questions even when the answer is the same
coordinates, and only one of them should be answerable.

**There is no refund execution endpoint, and there is a test asserting there
never is.** §9 marks eligibility read-only "in MVP". It should stay read-only
well past MVP: the moment an outside system can cause money to move, a
compromise of that system is a compromise of the bank account.

## What deliberately does not cross the boundary

- **The delivery address.** Only `delivery_area`. Support resolves a problem; it
  does not need to know which door. There is a test.
- **The rider's phone number.** The rider is named so an agent can say who is
  coming. Their number is not support's to hand out.
- **Rider position outside an active delivery.** Reuses `TrackingService`, which
  already releases a position only while the order is being carried.
- **Anything that changes an order's state.** No cancel, no reassign, no status
  change. Those stay behind the customer's own consent and the platform's own
  workflows.

## The audit trail

Every call writes a `service_api_logs` row — successes and refusals alike.
A run of 403s against a scope a credential does not hold is either a
misconfigured client or somebody trying doors, and both are worth seeing.

The row records the **route pattern** (`orders/{order}`) rather than the filled
URL, so a thousand calls group into one readable line, and the id is recorded
separately as `subject_type` + `subject_id`. That lets the question be asked
from the other end: *everything any service has ever read about order 4821*.

The caller's IP is **hashed, not stored**. The address identifies a host during
an investigation; keeping it in the clear turns an access log into a second
pile of personal data.

Append-only — `ServiceApiLog::UPDATED_AT = null`. A log that can be edited is
not evidence.

**Retention is not yet set.** Twelve months is the figure in the assessment, and
it needs a pruning command like `nexmile:prune-location-history` before this
goes anywhere near a real integration.

## Rate limiting

Per client, default 120 requests a minute, set on the client row. Exceeded
returns `429` with `Retry-After`. A runaway retry loop on the caller's side
should degrade their service, not ours.

## Error shape

Matches §7.2 of the blueprint:

```json
{ "error": { "code": "SERVICE_ACCESS_DENIED", "message": "...", "request_id": "..." } }
```

`SERVICE_ACCESS_DENIED` covers not-a-service, suspended and missing-scope
deliberately. A caller that is refused does not need to know which of the three
it was; an operator reading the log does, and the log has it.

## Still to build

In rough order of what blocks whom:

1. **Outbound webhooks** — §9 names `order.status_changed`. Nothing in the
   platform emits events outward today; the only webhook is inbound from
   Razorpay. Without it the BPO side must poll.
2. **A test environment and seeded data**, which §12 lists as a Nexmile
   deliverable alongside the contracts.
3. **Retention and pruning** for `service_api_logs`.
4. **An admin screen** showing which integrations exist, when each last called
   and what they have been reading. The data is there; nothing displays it.
5. **OpenAPI for this document.** It is deliberately excluded from the three app
   documents — no app holds these credentials, and documenting it beside the
   customer endpoints would invite somebody to try — but the BPO team needs a
   spec, and that should be a fourth Scramble document rather than a wiki page.

## Tests

`tests/Feature/InternalServiceApiTest.php`, 33 tests. Most of them are about
refusal, because these endpoints answer questions about people who are not the
caller. The ones that matter most:

- **a customer's own token cannot open this door**, even for their own order
- a credential without the scope is refused, and the refusal is logged
- a suspended integration is refused
- revoking stops it immediately
- the delivery address never crosses the boundary
- **there is no endpoint that issues a refund**
- the log records the route, the subject and a hashed address
- a credential cannot be minted with a scope nothing enforces
