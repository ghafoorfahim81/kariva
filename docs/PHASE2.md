# Phase 2 (Transactions): backend

All routes below run as the actor in the `X-Actor-Id` header (checked against `actor_members`) unless marked public.
Business-rule failures return `{ "code": "area.reason" }`.

## Orders (`/orders`)

| Step | Endpoint | Who | From | To |
| --- | --- | --- | --- | --- |
| Create | `POST /orders` (`service_id` or `provider_actor_id` + `title`) | customer (editor+) | | `requested` |
| Quote | `POST /orders/{id}/quote` | provider | `requested`, `quoted` | `quoted` (a new quote replaces the pending one) |
| Accept | `POST /orders/{id}/accept` | customer | `quoted` | `accepted` (agreed price copied and locked) |
| Reject | `POST /orders/{id}/reject` | provider | `requested`, `quoted` | `rejected` |
| Cancel | `POST /orders/{id}/cancel` | customer | `requested`, `quoted` | `cancelled` |
| Done | `POST /orders/{id}/done` | provider | `accepted` | `provider_done` |
| Confirm | `POST /orders/{id}/confirm` | customer | `provider_done` | `completed` |
| Dispute | `POST /orders/{id}/dispute` | customer | `provider_done` | `disputed` |

Also `GET /orders` (`role`, `status`, keyset `cursor`) and `GET /orders/{id}` (parties only; others get 404).
Every step locks the order row, writes one append-only `order_events` row, notifies the other side and keeps the database rules (status machine, price lock after acceptance) as the last line of defence.
Creating an order opens its conversation.

## Invited past job

`POST /invited-jobs` (provider records phone + title) prepares the customer (a user in status `invited` with a personal actor), creates an order in `invited` and sends a code (purpose `invited_job`). `POST /orders/{id}/confirm-invited` (either party enters the code) completes it. `POST /orders/{id}/invited-code` sends the code again. At most 5 per provider per month (Asia/Kabul), enforced by the database. An unconfirmed past job does not count for the profile.

## Needs and offers

Public board `GET /needs` (kind, category incl. sub-categories, city, keyset). As an actor: `POST /needs`, `GET /my/needs`, `POST /needs/{id}/close`, `GET/POST /needs/{id}/offers`, `POST /offers/{id}/withdraw|reject|accept`.
A **service** need collects offers; accepting one creates an order (origin `offer`, straight in `accepted` with the offer price), turns the other offers down and closes the need. A **job** need takes no offers and never creates an order; people talk through a direct conversation.

## Reviews, reports, favorites

`POST /orders/{id}/review` (customer, completed orders only, one per order, stars 1-5, ready-made tags, text), `POST /reviews/{id}/reply` (provider, once), public `GET /actors/{id}/reviews`.
`actor_stats` (completed count, rating count and average) is recomputed from the source rows after each completion and review.
`POST /reports` (one open report per user and subject), `GET/PUT/DELETE /favorites`.

## Chat and notifications

`GET /conversations` (with unread counts), `POST /conversations/direct`, `GET|POST /conversations/{id}/messages`, `POST /conversations/{id}/read`. Participants only; the team of a page shares its conversations; viewers can read but not write. The client polls in the MVP.
`GET /notifications`, `POST /notifications/{id}/read`, `POST /notifications/read-all`. The unique `(user, dedupe_key)` keeps a retried event from notifying twice.

## Time rules (hourly scheduler)

| Rule | Default | Setting |
| --- | --- | --- |
| A request nobody answers expires | 72 hours | `ORDER_EXPIRE_REQUESTED_HOURS` |
| A quote nobody accepts expires | 7 days after the quote | `ORDER_EXPIRE_QUOTED_DAYS` |
| `provider_done` without answer is completed by the system | 7 days | `ORDER_AUTO_COMPLETE_DAYS` |
| A need expires | 30 days | `NEED_LIFETIME_DAYS` |

These were the two open decisions in `CLAUDE.md`. The spec proposed these values and Ghafoor had no other preference, so they are the defaults; they are settings, not code.
Commands: `orders:expire`, `orders:auto-complete`, `needs:expire`.

## Not in this part

Photos and voice in messages (the `media` table already allows them), the order-attachment upload, the reverse "customer rates" direction, blocks (phase 5), and the web screens, which are wired in the next step.
