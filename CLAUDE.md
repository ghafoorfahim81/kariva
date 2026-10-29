# Kariva — CLAUDE.md

Kariva is a skills and services marketplace for the Afghan market (NOT a LinkedIn clone).
Target user: low literacy, phone-first. Goal: customers find specialists, specialists find customers, easily.
Owner: Ghafoor Fahim. Timezone Asia/Kabul. Reference spec: `Kariva_Spec_v3.pdf` (the source of truth; this file is a summary).

## Stack
- Backend: Laravel REST API, PostgreSQL, Redis, Sanctum (cookie SPA now, tokens when mobile starts)
- Frontend: Vue 3 SPA (Vite, TypeScript, Tailwind). Prototype: `kariva-v4.8/` (patched V4.7). Do NOT rewrite it from scratch; modify it.
- Realtime: Reverb (messaging; optional in MVP). Storage: S3-compatible. Server: Ubuntu + Nginx.
- Web first. Flutter mobile comes months after the web launch, on the same API. No Inertia.

## Language and UI rules
- Dari first (RTL), Pashto second, English third. All UI text uses translation keys. User-written text is never translated.
- Font: Vazirmatn. Big touch targets, icons plus short words, large call and WhatsApp buttons.
- Normalize Persian/Arabic digits and letters on input. Phone is stored in E.164 (`+93…`, 9 digits after +93, starting with 7), validated with libphonenumber.
- Store time in UTC, display Jalali. Money is `numeric(14,2)` with currency (AFN/USD) and unit; no conversion, no tax, no commission.
- Names: columns are `name` (Dari, required) plus optional `name_ps`, `name_en`; fall back to `name` when empty.

## Data model rules (30 MVP tables, details in the spec)
- Use UUID primary keys.
- Identity: `users`, `otp_codes`, `actors` (person or business, `personal_user_id` UNIQUE), `actor_members` (roles: owner, admin, editor, viewer), `actor_invitations` (kind: member / transfer_primary), `verification_requests`, `audit_logs`.
- Catalog and location: `categories` (tree, with an `aliases` text[] column and a GIN index), `provinces`, `cities`, `actor_service_areas`, `services`.
- Media: one polymorphic `media` table. Use the Laravel morphMap with short names (`actor`, `service`…), never PHP class names. Partial unique index for avatar and cover. Has `scan_status` and `purge_at`.
- Market: `portfolio_items`, `needs`, `offers`, `orders`, `quotes`, `order_events`, `reviews`, `actor_stats`.
- Trust and communication: `reports`, `favorites` (polymorphic: actor/service/need), `conversations`, `conversation_participants`, `messages`, `notifications`.
- System: `personal_access_tokens`, `jobs`, `failed_jobs`.
- Phase 2 only (do not build yet): `posts`, `post_comments`, `contracts`, `disputes`, `blocks`, `user_devices`, `skills`, `actor_skills`, `service_area_overrides`, `notification_preferences`.

## Business rules (decided)
- Registration is open to everyone. Phone is mandatory, email is optional.
- A page (actor) can have several owners, with exactly one `is_primary`. Primary-owner transfer is by invitation and acceptance. Only the primary owner can delete the page or transfer primary ownership; a co-owner can do everything else.
- A company with open orders cannot be deleted. Suspension only stops new orders.
- Services: `delivery_mode` onsite/online/both, coverage areas, archived services are hidden.
- A service need creates an order; a job opportunity does not.
- Orders: origin service / direct / offer / invited. One status machine, history in `order_events`. Only one `pending` quote per order. After `completed`, no financial field changes.
- Reviews only after `completed`: composite FK `(order_id, customer_actor_id, provider_actor_id)` plus a trigger.
- "Invited past job": confirmed by the customer via OTP, max 5 per month.
- Contract is optional and phase 2. Posts and comments are phase 5 (after the public launch and a working admin panel).
- Pending decisions (ask Ghafoor before building): expiry 72h/7d, auto-complete 7 days after `provider_done`.

## Search
- `search_text` normalized, `pg_trgm` index: category name + aliases + service name + city. Ranking: verified, then average review and completed jobs. Keyset pagination, never offset.

## Security
- Every API request carries the active `actor_id`; policies decide only from `actor_members`.
- Rate-limit OTP. Scan uploads. Verification documents are private. Log sensitive actions in `audit_logs`.

## Roadmap
0 Foundation (1–2 weeks) → 1 Core (3–4) → 2 Transactions (4–5) → 3 Trust and launch (4–5) → 4 Public rollout, city by city (2–3 months) → 5 Phase 2 web: posts/comments, contracts, disputes → 6 Flutter mobile.

**Current step: Phase 0.** Create the Laravel project and write migrations for all MVP tables with every constraint (unique, partial unique, composite FK, triggers), seed provinces, cities and categories, and write constraint tests. Gate: all constraint tests green.

## Working rules
- Every data constraint in the spec must also exist in a migration, and have a test.
- Prefer modifying existing code over rewriting. Keep answers to Ghafoor in Dari unless he writes in English.
- Prototype leftovers: UUIDs, translation keys, "past job", `expired` status, removing old tabs.
