# Kariva backend

Laravel 13 REST API (`/api/v1`) on PostgreSQL 16 + Redis. Source of truth: `docs/Kariva_Spec_v3.pdf` and `CLAUDE.md`.

## Run it

```sh
cd backend
composer install
cp .env.example .env && php artisan key:generate
createdb kariva && createdb kariva_test        # role kariva / password kariva, or edit .env and phpunit.xml
php artisan migrate --seed                      # 30 MVP tables + provinces, cities, categories
php artisan test                                # constraint tests + unit tests (Phase 0 gate)
```

Needs PHP 8.3 with `pdo_pgsql`, `intl`, `redis`, and PostgreSQL with the `pg_trgm` extension.

## Where things are

| What | Where |
| --- | --- |
| All tables, constraints, partial indexes, triggers | `database/migrations/2026_10_29_0000*` (raw SQL, grouped like the spec's ERD) |
| Seed data (34 provinces, cities, category tree with search aliases) | `database/seeders`, data in `database/seeders/data` |
| Phone to E.164, Persian/Arabic normalization | `app/Support/Phone.php`, `app/Support/Text.php` |
| morphMap (short names in `media`, `reports`, `favorites`) | `app/Providers/AppServiceProvider.php` |
| Nightly media clean-up (orphans, expired verification files) | `app/Jobs/PurgeMedia.php`, scheduled in `routes/console.php` |
| Constraint tests (one file per ERD group) | `tests/Feature/Database` |

Rule of the project: a data rule lives in the database first (CHECK, UNIQUE, partial unique index, composite FK, trigger), and has a test that proves the database refuses the bad row.
