# HealthyLife AI — Backend (Laravel API)

AI-powered client management platform for nutritionists. This is the Laravel REST API
consumed by the Next.js nutritionist dashboard and the Flutter client app. See the
project root for the PRD, SRS, Milestones, and User Stories documents — this backend
implements Sprint 1 (Authentication) and Sprint 2 (Onboarding, Health Profile, Food
Database) of the Sprint 1–3 task breakdown.

## Stack

- PHP 8.3+, Laravel 13
- MySQL 8+
- JWT access tokens (`firebase/php-jwt`) + rotating opaque refresh tokens (custom,
  see `app/Services/Auth`) — chosen over a JWT auth package because refresh-token
  rotation and reuse detection (MVP spec §8: theft response) are custom logic
  regardless of package.
- `spatie/laravel-permission` for role/permission checks (Layer 1 of the permission
  model — see below)

## Local setup

1. **Install dependencies**

   ```bash
   composer install
   ```

2. **Environment**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Then fill in:
   - `DB_*` — a local MySQL 8+ server. Default `.env.example` values assume
     `127.0.0.1:3306`, database `healthylife_ai`, user `root`, no password
     (matches a stock XAMPP/Laragon install). Create the database first:
     ```sql
     CREATE DATABASE healthylife_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     ```
   - `JWT_SECRET` — generate a per-environment secret, **never reuse `APP_KEY`**:
     ```bash
     php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
     ```

3. **Migrate & seed**

   ```bash
   php artisan migrate --seed
   ```

   This creates the three MVP roles (`nutritionist`, `client`, `admin`) with the
   permission matrix from PRD §2.2, a demo nutritionist
   (`nutritionist@example.com` / `password`), and ~58 curated Arabic dishes
   (`ArabicFoodSeeder`).

4. **Import the USDA food database** (S2-07, optional but needed for a
   realistic food-search demo) — not committed to the repo (36MB uncompressed):

   ```bash
   mkdir -p storage/app/imports/usda
   # Download & unzip https://fdc.nal.usda.gov/fdc-datasets/FoodData_Central_sr_legacy_food_csv_2018-04.zip
   # into storage/app/imports/usda/ (need food.csv + food_nutrient.csv), then:
   php artisan foods:import-usda
   ```

   Imports ~7,800 generic ingredients (SR Legacy — the final, general-ingredient
   release, not the 300k+-item Branded Foods set). Takes 1–2 minutes; safe to
   re-run (upserts by `usda_fdc_id`).

5. **Run**

   ```bash
   php artisan serve
   ```

## Authentication (Sprint 1)

Full request/response payloads and error codes for every `/auth/*` endpoint:
see [API_CONTRACT.md](API_CONTRACT.md) — the binding reference for the
Frontend, Desktop, and Mobile roles integrating against this API (S1-06).

A runnable Postman collection covering every endpoint in this API (all of
Sprint 1 + 2) lives in [`postman/`](postman/) —
`HealthyLife-AI.postman_collection.json` plus a companion
`HealthyLife-AI-Local.postman_environment.json`. Import both into Postman, or
run headless with Newman:

```bash
npx newman run postman/HealthyLife-AI.postman_collection.json \
    --environment postman/HealthyLife-AI-Local.postman_environment.json
```

Requests chain automatically (register → login → add client → activate →
health profile → body composition → foods → dashboard), saving tokens and
IDs into collection variables via test/prerequest scripts — no manual
copy-pasting needed to run the whole collection top-to-bottom. `postman/build_collection.py`
generates both JSON files and is the source of truth; regenerate with it
after changing an endpoint, keeping it in sync with `API_CONTRACT.md`.

Every role (nutritionist, client, admin) is a row in the single `users` table,
distinguished by its Spatie role — there's no separate `nutritionists` /
`clients` table. A client's `nutritionist_id` links it to the nutritionist that
owns it (BR-1: one client belongs to exactly one nutritionist).

| Endpoint | Auth | Notes |
|---|---|---|
| `POST /api/v1/auth/register` | — | Nutritionist self-registration only (FR-01). Client accounts are created by a nutritionist in Sprint 2, via invite link — never through this endpoint. |
| `POST /api/v1/auth/login` | — | Locks the account for 15 min after 5 consecutive failed attempts (FR-04). |
| `POST /api/v1/auth/refresh` | — | Body: `{ "refresh_token": "..." }`. Single-use, rotates on every call. Reusing an already-rotated token revokes every session the user holds (theft response). |
| `POST /api/v1/auth/logout` | — | Body: `{ "refresh_token": "..." }`. Revokes that one session. |
| `GET /api/v1/auth/me` | `Authorization: Bearer <access_token>` | Returns the authenticated user. |

Access tokens are short-lived JWTs (`JWT_TTL`, default 15 min — MVP spec §8) carrying
`role` and `nutritionist_id` claims. Refresh tokens are opaque, stored only as a
SHA-256 hash (`refresh_tokens` table), default 30-day TTL (`JWT_REFRESH_TTL`).

Protect a route with the `jwt` middleware alias:

```php
Route::middleware('jwt')->get('/something', ...);
```

## Data isolation (BR-2 / NFR-12)

> "Permission alone is not security." — PRD §2.2

Spatie roles answer *"can this role perform this action?"*. They do **not** answer
*"can this user touch this row?"* — that's `App\Models\Scopes\NutritionistScope`, a
global scope that filters every query on a model to the current nutritionist's own
rows. Apply it to any model that carries a `nutritionist_id` column by using the
trait:

```php
use App\Models\Concerns\BelongsToNutritionist;

class HealthProfile extends Model
{
    use BelongsToNutritionist; // adds the scope + auto-fills nutritionist_id on create
}
```

`Subscriber` (Sprint 2) is the first real model to `use` it, and adds the
first model in the app that's ever used as a **route-model-bound** controller
parameter (`Route::get('clients/{subscriber}', ...)` type-hinted as
`Subscriber $subscriber`). That case needs an *extra* explicit check —
`$subscriber->belongsToCaller()` — because Laravel resolves route-model
bindings via `SubstituteBindings`, which runs before this app's custom `jwt`
middleware sets the authenticated user; `NutritionistScope` correctly sees no
user yet at that point and skips filtering rather than locking everyone out,
which means the *scope alone* does not protect a route-bound parameter. Every
controller action that receives one calls `belongsToCaller()` and 404s if
false — see `ClientController::show()` for the pattern. Caught by
`tests/Feature/Clients/ClientManagementTest::test_a_nutritionist_cannot_fetch_another_nutritionists_client_directly`
(and the equivalent tests for health profile / body-composition endpoints) —
if you add a new route-bound `Subscriber` action, add its isolation test too.

## Clients, health profile, food DB (Sprint 2)

Full request/response payloads: see [API_CONTRACT.md](API_CONTRACT.md).

- A client is a row in the same `users` table (role `client`), **plus** a
  `Subscriber` row holding the client-specific domain data (goal, per-
  nutritionist code, invite/activation status) — auth stays on `users`,
  business data lives on `subscribers`. A client has **no email** (PRD F-1:
  added by name + phone only), so `users.email` is nullable and login accepts
  `phone` as an alternate to `email`.
- Invite links (FR-02/FR-03) mirror Sprint 1's refresh tokens exactly: opaque,
  single-use, only a SHA-256 hash stored (`ClientInviteService`,
  `client_invites` table). Default 7-day TTL (`INVITE_TOKEN_TTL_DAYS`).
- `daily_calorie_needs` (FR-11) is Mifflin-St Jeor BMR × a standard clinical
  activity-tier multiplier (`NutritionCalculatorService`) — recalculated on
  every health-profile save, never trusted stale.
- Food search (FR-25) uses a `LIKE 'query%'` prefix scan on indexed columns,
  not FULLTEXT — InnoDB's default `ft_min_word_len` (4) would silently drop
  matches for common 3-letter Arabic food words. See `Food::scopeSearch()`.

## Testing

```bash
php artisan test
```

Feature tests run against an in-memory SQLite database (`phpunit.xml`), so they
don't require the local MySQL server.

## Code style

```bash
./vendor/bin/pint
```
