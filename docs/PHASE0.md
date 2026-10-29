# Phase 0 (Foundation): what was built and what was decided

## Gate

`php artisan test` is green: every constraint in the spec exists in a migration and has a test that proves PostgreSQL rejects the violating row.

## Constraints beyond the literal SQL in the spec

These were derived from the spec text. Each one has a test.

| Rule (spec section) | Enforced by |
| --- | --- |
| Every user has exactly one personal actor (§3, §7) | deferred constraint trigger on `users` (checked at commit) |
| Person actor type and `personal_user_id` never change | trigger on `actors` |
| Last owner can never leave; primary owner must hand over first (§11.2, §11.4) | trigger on `actor_members` |
| Only the primary owner can ask for page deletion / start a primary transfer (§11 matrix) | trigger on `actors`, trigger on `actor_invitations` |
| Admins can invite members but not owners (§11 matrix) | trigger on `actor_invitations` |
| Page with open orders cannot be deleted (§11.3) | trigger on `actors` (business only) |
| Suspension only stops *new* orders (§11.3) | trigger on `orders` insert |
| Verification kind equals actor type | composite FK `(actor_id, kind) -> actors(id, type)` |
| Offers exist only for service needs; job needs never become orders (§9) | composite FK on `needs(id, kind)` through a constant `need_kind` column |
| An order's service / offer / need belong to its provider / customer | composite FKs on `orders` |
| Order status machine (§9 table) | trigger on `orders`; illegal changes are refused |
| Price, title and parties locked from `accepted` on; completed is final (§9) | trigger on `orders` |
| Only one pending quote; no quotes after completion (§9) | partial unique index, trigger on `quotes` |
| Review only after `completed`, only by that order's two parties (§7) | composite FK + trigger |
| Invited past job: max 5 per provider per month, month counted in Asia/Kabul (§3) | trigger with advisory lock |
| Media collection decides visibility; one avatar and one cover (§8) | CHECK + partial unique index |
| Media has no FK to its parent, so a nightly job cleans orphans (§8) | `PurgeMedia` job |
| `order_events` and `audit_logs` are append-only | trigger |
| Messages only from participants | composite FK |
| One open report per user per subject (§7) | partial unique index |

## Assumptions to confirm with Ghafoor

1. **Offer orders** may start as `accepted` (the offer already carries a price) or as `requested`.
2. **`notifications.dedupe_key`** is unique per user, not globally, so both parties of one event can be notified. The ERD draws it as a plain unique key.
3. **`actor_stats`** has the consistency CHECKs, but the numbers are filled by an application job (as the spec says), not by a trigger.
4. **Pashto and English names** in the seed data are a first draft. A native speaker should review them. Dari names are the source of truth.
5. **Pending decisions from CLAUDE.md**, not built because they are not data constraints: request expiry (72 hours or 7 days) and auto-complete 7 days after `provider_done`.
6. **Invited status for users**: the spec lets a provider invite a customer by phone. The schema supports this with `users.status = 'invited'` plus a personal actor.
7. `actors` has extra columns that the contact buttons need: `contact_phone_e164`, `whatsapp_e164`, `bio`, `verified_at`. `users.is_platform_admin` marks platform admins for the separate admin panel.

## Not in Phase 0

Auth/OTP endpoints, policies, search endpoint, Vue prototype wiring. Those start in Phase 1 (Core).
