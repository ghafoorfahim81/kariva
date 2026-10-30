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

## Web screens (kariva-v4.8)

New files, each wired straight to the API (the old demo state for these screens is no longer used):

| File | Replaces |
| --- | --- |
| `components/OrdersPanel.vue` | the demo order list and order sheet: list by side (customer / provider), quote, accept, reject, cancel, done, confirm, dispute, review form, history, past-job flow and code entry |
| `components/RequestOrderModal.vue` | "request a service" (from a service or directly) |
| `components/NeedsBoard.vue` | the demo "opportunities" tab: public board with filters, post a need, make an offer, see and accept offers, close, message about a job |
| `components/ChatPanel.vue` | the demo messages tab (polls every few seconds) |
| `components/NotificationsPanel.vue` | the demo notification centre; tapping a notification opens the order, chat or board |
| `components/ReviewsModal.vue` | public reviews, provider reply, report a review |
| `state/live.ts` | unread counters for the bell and the messages tab (polled every 20 s) |

Also: favorites and rating summaries on profile cards use the API; the sidebar shows live needs; all dates are shown in the Jalali calendar (Asia/Kabul) and amounts keep their own currency and unit.
Verified in a real browser with two users: request, price, accept (price locked), done, confirm, review (profile stats update), chat with unread badge, notifications; a need with an offer that becomes an order; a job need that offers only a message; a past job confirmed by code.

## Not done yet

- Photos and voice in messages, order attachments.
- Block user, admin panel and verification screens (Phase 3), reports from the profile page.
- The old demo code paths (posts, careers, old order state, demo admin) are still in `App.vue` but no longer reached by the screens above; removing them is part of the prototype clean-up.
- Translation keys: the new screens use `tr(fa, en)`; Pashto falls back to Dari until the texts are written.
