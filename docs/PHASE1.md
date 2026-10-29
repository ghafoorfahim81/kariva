# Phase 1 (Core): what was built

Goal from the spec: a specialist builds a page in 3 steps and is found; a customer finds them.
This is now true end to end (real browser, real API, real database).

## API (`/api/v1`)

| Area | Endpoints |
| --- | --- |
| Login | `POST /auth/otp`, `POST /auth/verify`, `POST /auth/logout`, `GET /me` |
| Acting as an actor | header `X-Actor-Id` (checked against `actor_members`), `GET /context` |
| Pages | `POST /actors`, `GET/PATCH /actors/{id}`, `PUT /actors/{id}/service-areas`, deletion request / cancel / confirm |
| Team | `GET /actors/{id}/members`, role change, remove, leave; invitations by phone, accept/decline, primary-owner transfer |
| Services | `GET/POST /actors/{id}/services`, `GET/PATCH/DELETE /services/{id}` (delete = archive) |
| Work samples | `GET/POST /actors/{id}/portfolio`, `PATCH/DELETE /portfolio/{id}` |
| Media | `POST /media`, `DELETE /media/{id}` (avatar, cover, service gallery, work-sample photos) |
| Catalog | `GET /categories`, `/provinces`, `/cities` |
| Search | `GET /search/actors?q=&category=&city=&type=&cursor=&limit=` (keyset pagination, no offset) |

Business-rule failures return `{ "code": "area.reason" }` (a translation key, never a sentence).

## Rules worth knowing

- OTP: 6 digits, 5 minutes, 5 attempts, stored hashed; 3 sends per hour per phone and per IP (the IP limit is tunable with `OTP_MAX_SENDS_PER_IP`). The send answer never reveals whether a number is registered.
- The browser SPA gets a session cookie (Sanctum, CSRF); a client without a session (the future mobile app) gets a token.
- First login creates the user and its personal actor. Registration needs only a phone.
- Search matches names, professions (all languages and aliases), service titles and city; ranking is verified, rating, completed jobs. A profile without a profession or an active service is not listed yet.
- Uploads: the type is judged from the file content, size per collection, random file names, single slots (avatar, cover) are replaced, a queued job scans the file; a file is shown only after it is marked clean.
- Any owner can request page deletion, only the primary owner confirms; open orders block it (DB rule).

## Frontend (`kariva-v4.8`)

New: `src/api/` (client with CSRF and actor header, endpoints, session store). Wired to the real API: login and sign-up with OTP, session restore, the 3-step profile (name, profession, city), discovery (people and businesses), catalog selects, create page, identity switch, add service with the price rules.
Vite proxies `/api` and `/sanctum` to `http://127.0.0.1:8000`, so the cookie session works without CORS.

Run both:

```sh
cd backend && php artisan serve                # set OTP_FAKE_CODE=123456 in .env for local login
cd kariva-v4.8 && npm ci && npm run dev
```

## Still prototype data (by design, later phases)

Orders, quotes, needs and offers, chat, notifications, reviews, admin panel, posts. They keep their demo data until Phase 2 and are not connected to the server.

## Not done yet in Phase 1 (next small steps)

- Avatar, cover, gallery and work-sample photos in the UI still use local previews; the upload API is ready and tested.
- Team and invitation screens (API ready and tested).
- Service edit and archive buttons, work-sample screens.
- Translation keys for the new texts (Pashto and English are partly missing), UUID leftovers in demo data, removing old tabs.
- The "services" and "work" discovery tabs still show prototype samples.

## Assumptions to confirm

1. The phone limit per IP defaults to 3 as in the spec; carriers share IPs, so you may want a higher number.
2. A person profile needs a profession (or one active service) to appear in search.
3. Public profiles show the call and WhatsApp numbers to everyone, as the product idea needs.
