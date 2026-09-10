# API Contract

S1-06 / S2-09. Binding contract for the backend API, for the Frontend (Web
Dashboard), Desktop (Electron), and Mobile (Flutter) roles to integrate against.
Source of truth is the `app/Http/Controllers/Api/**` code — if this doc and the
code ever disagree, the code wins; report the drift so this gets fixed.

**Contents**: [Authentication](#authentication-sprint-1) (Sprint 1) ·
[Clients](#clients-sprint-2) · [Health Profile](#health-profile-sprint-2) ·
[Body Composition](#body-composition-readings-sprint-2) ·
[Food Search](#food-search-sprint-2) · [Dashboard](#dashboard-overview-sprint-2)
(Sprint 2) · [Meal Plans](#meal-plans-sprint-3) ·
[Meal Plan Templates](#meal-plan-templates-sprint-3) ·
[Food Submission & Approval](#food-submission--approval-sprint-3) ·
[Client's Own Plan](#clients-own-plan-sprint-3) (Sprint 3)

Base URL: `{APP_URL}/api/v1` (local dev default: `http://127.0.0.1:8000/api/v1`).
All requests/responses are JSON (`Content-Type: application/json`, `Accept: application/json`).

The web dashboard does **not** call these directly — it goes through a same-origin
BFF layer (`Frontend/healthylife-dashboard/src/app/api/auth/*`) that holds the
refresh token in an HttpOnly cookie. Desktop and Mobile have no such layer and call
these endpoints directly, so they own storing the refresh token themselves —
store it in the OS keychain / Electron `safeStorage` / Flutter secure storage,
never in plain prefs/localStorage.

## Common shapes

**User object** (returned by register/login and `GET /me`):

```json
{
  "id": 1,
  "name": "Demo Nutritionist",
  "email": "nutritionist@example.com",
  "phone": null,
  "role": "nutritionist",
  "nutritionist_id": null
}
```

`role` is one of `nutritionist` / `client` / `admin`. `nutritionist_id` is only
non-null for a `client`-role user (the nutritionist that owns them — BR-1).

**Token pair** (returned by register/login/refresh):

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "refresh_token": "WpoIngUVVz7R9w2oufb2jKrcDVTsKGJn...",
  "token_type": "Bearer",
  "expires_in": 900
}
```

- `access_token`: short-lived JWT (default 900s / 15 min, `JWT_TTL`). Send it as
  `Authorization: Bearer <access_token>` on every authenticated request. It
  carries `sub` (user id), `role`, and `nutritionist_id` claims — informational
  only, never trust a decoded claim over what the API itself returns/enforces.
- `refresh_token`: opaque, single-use, rotates on every `/refresh` call (FR-05).
  Default 30-day TTL (`JWT_REFRESH_TTL`). **Reusing an already-rotated or
  already-logged-out refresh token revokes every session the user holds** —
  this is deliberate theft detection, not a bug. Never call `/refresh` twice
  in parallel with the same token (e.g. from two racing requests); serialize
  refresh calls per session.
- `expires_in`: seconds until `access_token` expires (`= JWT_TTL * 60`).

**Validation error** (any endpoint, `422`):

```json
{
  "message": "The email has already been taken. (and 1 more error)",
  "errors": {
    "email": ["The email has already been taken."],
    "password": ["The password field confirmation does not match."]
  }
}
```

Standard Laravel form-request shape. Note: a `password`/`password_confirmation`
mismatch attaches its error to the **`password`** key, not
`password_confirmation` — a Laravel `confirmed`-rule quirk, not a typo.

**Rate limiting** (`429`, register/login: 10 req/min, refresh: 20 req/min, per IP):

```json
{ "message": "Too Many Attempts." }
```

Also sets a `Retry-After` header (seconds).

---

## Authentication (Sprint 1)

## `POST /auth/register`

Nutritionist self-registration only (FR-01). Client accounts are never created
here — a nutritionist creates them via invite link (Sprint 2, US-02).

**Request**

```json
{
  "name": "Jane Nutri",
  "email": "jane@example.com",
  "password": "Passw0rd!",
  "password_confirmation": "Passw0rd!"
}
```

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `email` | required, string, valid email, max 255, unique |
| `password` | required, matches `password_confirmation`, min 8 chars, mixed case, at least one number |

**201 Created** — User object + token pair (flattened into one object, `user` nested):

```json
{
  "user": { "id": 4, "name": "Jane Nutri", "email": "jane@example.com", "phone": null, "role": "nutritionist", "nutritionist_id": null },
  "access_token": "...",
  "refresh_token": "...",
  "token_type": "Bearer",
  "expires_in": 900
}
```

**422** — validation (see Common shapes). No other error case.

---

## `POST /auth/login`

**Request** — a nutritionist logs in by email; a client has no email (added by
name + phone only — Clients section below) and logs in by phone instead.
Exactly one of `email`/`phone` is required:

```json
{ "email": "jane@example.com", "password": "Passw0rd!" }
```

```json
{ "phone": "0501234567", "password": "ClientPass1!" }
```

**200 OK** — same body shape as register's 201 (no `password_confirmation` involved).

**401 Unauthorized** — wrong password OR unknown email. Deliberately identical
either way, to avoid leaking which one was wrong:

```json
{ "message": "These credentials do not match our records." }
```

**423 Locked** — 5 consecutive failed attempts (FR-04). Correct password is
*also* rejected with this while locked:

```json
{ "message": "Account is locked due to too many failed login attempts.", "locked_until": "2026-09-06T12:15:00+00:00" }
```

`locked_until` is ISO 8601 UTC. Show a countdown/retry time to the user rather
than a generic error.

**422** — missing `email`/`password`.

---

## `POST /auth/refresh`

**Request**

```json
{ "refresh_token": "WpoIngUVVz7R9w2oufb2jKrcDVTsKGJn..." }
```

**200 OK** — new token pair (no `user` key — see below):

```json
{ "access_token": "...", "refresh_token": "...", "token_type": "Bearer", "expires_in": 900 }
```

`GET /auth/me` gets you the user object if you need it after a refresh (e.g. on
app cold-start with only a stored refresh token) — one extra call, but avoids
`/refresh` doing two jobs.

**401 Unauthorized** — one of two messages, both mean "start over, send the user
to login":

```json
{ "message": "Refresh token is invalid or has expired." }
```

```json
{ "message": "Refresh token was already used. All sessions have been revoked as a precaution." }
```

**422** — missing `refresh_token`.

---

## `POST /auth/logout`

**Request**

```json
{ "refresh_token": "WpoIngUVVz7R9w2oufb2jKrcDVTsKGJn..." }
```

**200 OK** — always, whether or not the token was still valid (idempotent —
logging out twice, or logging out an already-expired session, is not an error):

```json
{ "message": "Logged out." }
```

Revokes only *this* session's refresh token. The already-issued access token
keeps working until its own short TTL expires — there's no server-side access-
token revocation (it's stateless by design); a client should discard it locally
immediately on logout regardless.

**422** — missing `refresh_token`.

---

## `GET /auth/me`

**Request**: no body. Requires `Authorization: Bearer <access_token>`.

**200 OK** — the User object (see Common shapes), unwrapped (no `data` envelope).

**401 Unauthorized** — missing, malformed, expired, or otherwise invalid access
token:

```json
{ "message": "Unauthenticated." }
```

An expired access token here means: call `/auth/refresh` with the stored
refresh token, then retry with the new access token. If refresh also fails,
the session is over — send the user to login.

---

## Clients (Sprint 2)

Everything below requires `Authorization: Bearer <access_token>` for a
**nutritionist**-role user (`permission:clients.manage` / `health_profile.manage`
— PRD §2.2). A client-role or admin-role token gets **403 Forbidden**. Every
list/read/write is scoped to the calling nutritionist's own clients — another
nutritionist's client ID returns **404**, never 403 (it never confirms the ID
belongs to someone else).

**Subscriber object** (a "client" in every screen — `Subscriber` is the
internal model name, the API and UI both say "client"):

```json
{
  "id": 12,
  "code": "PT-101",
  "name": "Sara Ahmad",
  "phone": "0501234567",
  "goal": "weight_loss",
  "status": "pending",
  "adherence_status": null,
  "last_logged_at": null,
  "created_at": "2026-09-06T09:54:30+00:00"
}
```

- `goal`: one of `weight_loss` / `weight_gain` / `weight_maintenance` / `health_monitoring`.
- `status`: `pending` (invited, not yet activated) or `active`. This is invite
  lifecycle only — there's no "deactivate a client" action in this sprint.
- `adherence_status`: `on_track` / `needs_attention` / `late` / `null`. Always
  `null` for now — it's computed from plan-vs-actual logging, which lands in a
  later sprint. Never fabricated to look populated.

### `POST /clients`

Add a client (FR-02) — by name + phone only, no email (PRD F-1). Issues a
single-use invite in the same call.

**Request**

```json
{ "name": "Sara Ahmad", "phone": "0501234567", "goal": "weight_loss" }
```

`phone` unique **per nutritionist**, not globally (BR-1) — two different
nutritionists can each have a client with the same phone number.

**201 Created**

```json
{
  "client": { "id": 12, "code": "PT-101", "name": "Sara Ahmad", "phone": "0501234567", "goal": "weight_loss", "status": "pending", "adherence_status": null, "last_logged_at": null, "created_at": "..." },
  "invite_token": "GHCnFz3xzIQspdcvJxX8uyYJXUGpS9WYYMh1NQeh",
  "invite_expires_at": "2026-09-13T09:54:30+00:00"
}
```

`invite_token` is returned **once** — it's not retrievable again (mirrors the
refresh-token pattern: only its hash is stored). Build the shareable link
yourself, e.g. `${FRONTEND_URL}/activate/${invite_token}` for web, or a mobile
deep link — the API doesn't hardcode a frontend URL scheme since more than one
frontend consumes it. Default validity 7 days (`INVITE_TOKEN_TTL_DAYS`).

**422** — validation (duplicate phone for this nutritionist, invalid `goal`, etc).

### `GET /clients`

List/filter/search (FR-06), paginated (Laravel's standard `data`/`links`/`meta`
envelope).

| Query param | Values |
|---|---|
| `status` | `pending` \| `active` |
| `adherence` | `on_track` \| `needs_attention` \| `late` |
| `search` | matches client name or code, **prefix only** (`"sar"` matches "Sara", not "Ansara") |
| `per_page` | 1–100, default 20 |

### `GET /clients/{id}`

Single client, same shape as the `client` object above.

### `POST /invites/{token}/activate`

**Public — no `Authorization` header.** FR-03: the client sets their password
and is logged in immediately, same token shape as login/register.

**Request**

```json
{ "password": "ClientPass1!", "password_confirmation": "ClientPass1!" }
```

**200 OK** — full auth response (`user`, `access_token`, `refresh_token`,
`token_type`, `expires_in`) — see Common shapes. `user.role` is `"client"`.

**422** — the token doesn't exist, is expired, or was already used:

```json
{ "message": "This invite link has already been used." }
```

---

## Health Profile (Sprint 2)

One profile per client (FR-07/FR-09). `PUT` is an upsert — first call creates
it (**201**), every later call updates it (**200**); same request/response
shape either way.

### `GET /clients/{id}/health-profile`

**200 OK** with the profile object below, or **204 No Content** if the client
has none yet (not an error — just means the form hasn't been filled in).

### `PUT /clients/{id}/health-profile`

**Request** — every field required, even on an update (it's edited as one form,
not partially patched):

```json
{
  "weight_kg": 82, "height_cm": 170, "age": 29, "gender": "female", "activity_level": "light",
  "health_conditions": ["مقاومة أنسولين"],
  "medications": [{ "name": "Levothyroxine", "dose": "50mcg", "schedule": "morning, fasting" }],
  "allergies": ["Peanuts"],
  "food_preferences": ["Vegetarian breakfast"],
  "surgery_history": null, "lab_notes": null, "nutritionist_notes": null
}
```

- `gender`: `male` \| `female` (Mifflin-St Jeor needs the binary constant).
- `activity_level`: `sedentary` \| `light` \| `moderate` \| `active` \| `very_active`.
- `health_conditions` / `allergies` / `food_preferences`: arrays of plain strings.
- `medications`: array of `{ name, dose?, schedule? }` — only `name` is required per entry.

**200/201** — the saved profile, with `daily_calorie_needs` freshly computed
(FR-11, Mifflin-St Jeor × activity multiplier — never a stale cached number):

```json
{
  "weight_kg": 82, "height_cm": 170, "age": 29, "gender": "female", "activity_level": "light",
  "health_conditions": ["مقاومة أنسولين"], "medications": [{"name": "Levothyroxine", "dose": "50mcg", "schedule": "morning, fasting"}],
  "allergies": ["Peanuts"], "food_preferences": ["Vegetarian breakfast"],
  "surgery_history": null, "lab_notes": null, "nutritionist_notes": null,
  "daily_calorie_needs": 2168,
  "updated_at": "2026-09-06T09:56:45+00:00"
}
```

**422** — validation (bad `gender`/`activity_level` enum value, out-of-range
`weight_kg`/`height_cm`/`age`, malformed `medications` entry).

---

## Body Composition Readings (Sprint 2)

FR-10: one row per visit — a history, never overwritten. `weight_kg` is
captured on every reading (it's also the series the weight-trend chart plots).

### `GET /clients/{id}/body-composition-readings`

**200 OK** — a plain array (not paginated — a client's full history is small),
newest first:

```json
[
  { "id": 9, "recorded_at": "2026-09-01", "weight_kg": 80, "body_fat_percent": 23.5, "muscle_mass_kg": null, "water_percent": null, "waist_cm": null },
  { "id": 3, "recorded_at": "2026-08-01", "weight_kg": 82, "body_fat_percent": null, "muscle_mass_kg": null, "water_percent": null, "waist_cm": null }
]
```

### `POST /clients/{id}/body-composition-readings`

**Request** — only `recorded_at` and `weight_kg` are required, the rest are
whatever the device/visit actually captured:

```json
{ "recorded_at": "2026-09-06", "weight_kg": 82, "body_fat_percent": 30.5, "waist_cm": 85 }
```

`recorded_at` must not be in the future. **201 Created** with the saved reading.

---

## Food Search (Sprint 2)

FR-25. Requires only `Authorization: Bearer <access_token>` — any authenticated
role, no specific permission (it's read-only reference data, not client data).

### `GET /foods/search`

| Query param | Values |
|---|---|
| `q` | required, 2–255 chars — matches the **start** of either name, not a substring anywhere in it |
| `per_page` | 1–50, default 20 |

Searches both `name_en` and `name_ar` as a prefix (`"chick"` matches "Chicken
Breast", not "Sandwich with Chicken"; `"حمص"` matches "حمص بطحينة"). Only
`status = "approved"` foods are returned — a nutritionist's own pending
submission never shows up in search results for anyone (BR-5), themselves
included, until an admin approves it.

**200 OK** — paginated:

```json
{
  "data": [
    { "id": 7801, "name_en": "Hummus", "name_ar": "حمص بطحينة", "source": "admin", "calories_per_100g": 166, "protein_g_per_100g": 7.9, "carbs_g_per_100g": 14.3, "fat_g_per_100g": 9.6, "fiber_g_per_100g": 6 }
  ],
  "links": { "...": "..." },
  "meta": { "...": "..." }
}
```

`source` is `usda` (SR Legacy import, English only), `admin` (curated Arabic
layer, both languages), or `nutritionist` (a submission — never appears here
until approved; see [Food Submission & Approval](#food-submission--approval-sprint-3)
for how one gets there, added in Sprint 3).

**422** — `q` missing or shorter than 2 characters.

---

## Dashboard Overview (Sprint 2)

### `GET /dashboard/overview`

F-2 stat cards, for the calling nutritionist only. `permission:clients.manage`.

**200 OK**

```json
{
  "total": 3, "active": 2, "pending": 1,
  "on_track": 1, "needs_attention": 1, "late": 0,
  "not_logged_today": 2
}
```

`on_track`/`needs_attention`/`late`/`not_logged_today` will read low or zero
until a later sprint's logging feature starts populating real adherence data
— that's accurate given the current data, not a bug.

---

## Meal Plans (Sprint 3)

S3-01/S3-02/S3-04/S3-07 / FR-12–FR-16, BR-4, BR-6, BR-10. `permission:plans.manage`
on every endpoint below except the client's own view (separate section).

**Alternatives (BR-4)**: an alternative is a meal item that references its
planned item via `parent_item_id`; a planned item has none. The request/response
shape nests alternatives under the item they belong to (`items[].alternatives`)
— you're never asked for a raw `parent_item_id` yourself, there's nothing to
point one at yet when you're creating a plan from scratch.

**Status (BR-6/BR-10)**: `draft` → `active` → `archived`. Only `POST
.../activate` ever moves a plan to `active` — creating one (by hand or via
`ai-draft`) always produces a `draft`. Activating a plan archives whatever plan
was previously `active` for that same client; a client has exactly one active
plan.

**Request body** (`POST`/`PUT`):

```json
{
  "start_date": "2026-09-15",
  "meals": [
    {
      "name": "breakfast",
      "day_index": null,
      "items": [
        {
          "food_id": 12,
          "quantity_grams": 200,
          "alternatives": [
            { "food_id": 45, "quantity_grams": 150 }
          ]
        }
      ]
    }
  ]
}
```

- `name`: one of `breakfast` / `snack` / `lunch` / `dinner`.
- `day_index`: `null` for a meal that repeats every day (the common case), or
  `0`–`6` (Monday–Sunday) for one specific day of a weekly plan.
- `food_id` (planned item and every alternative): must be an **approved** food
  — a pending or rejected food is rejected with a `422` the same way a
  nonexistent one is.
- `PUT` fully replaces the plan's meals/items (PRD F-4: edited as one whole
  form) — it is not a patch. Omitting a meal that existed before deletes it.

### `GET /clients/{id}/meal-plans`

**200 OK** — every plan (any status) for this client, newest first, as an
array of the response shape below.

### `POST /clients/{id}/meal-plans`

**201 Created** — the shape every meal-plan endpoint returns:

```json
{
  "id": 8,
  "subscriber_id": 3,
  "is_template": false,
  "is_ai_draft": false,
  "start_date": "2026-09-15",
  "status": "draft",
  "meals": [
    {
      "id": 21,
      "name": "breakfast",
      "day_index": null,
      "macros": { "calories": 400, "protein_g": 20, "carbs_g": 40, "fat_g": 10 },
      "items": [
        {
          "id": 55,
          "food": { "id": 12, "name_en": "Grilled Chicken", "...": "..." },
          "quantity_grams": 200,
          "macros": { "calories": 400, "protein_g": 20, "carbs_g": 40, "fat_g": 10 },
          "alternatives": [
            {
              "id": 56,
              "food": { "id": 45, "...": "..." },
              "quantity_grams": 150,
              "macros": { "...": "..." },
              "alternatives": []
            }
          ]
        }
      ]
    }
  ],
  "summary_by_day": { "0": { "calories": 400, "protein_g": 20, "carbs_g": 40, "fat_g": 10 } },
  "created_at": "2026-09-09T10:00:00+00:00",
  "updated_at": "2026-09-09T10:00:00+00:00"
}
```

FR-14: `macros` on an item is that item's own calories/macros at its quantity.
`macros` on a meal, and every value under `summary_by_day`, sums **planned
items only** — an alternative is a substitute, never added on top of the
plan's total (it's still fully reported on its own `macros` key, so the UI can
show "if they had this instead"). `summary_by_day` is keyed by `day_index`
(as a string, since it's a JSON object key) — a plan with no day-specific
meals reports everything under key `"0"`.

### `GET /clients/{id}/meal-plans/{planId}`

**200 OK** — same shape as above. **404** if `planId` doesn't belong to `id`.

### `PUT /clients/{id}/meal-plans/{planId}`

Same request/response shape as `POST`. This is also how an AI draft
(`is_ai_draft: true`) gets edited before it's approved — there's no separate
"edit a draft" endpoint (S3-07).

### `POST /clients/{id}/meal-plans/{planId}/activate`

No request body. **200 OK**, same shape, `status: "active"`, `is_ai_draft:
false` (activating a draft is how it gets approved — BR-6/BR-10).

### `POST /clients/{id}/meal-plans/ai-draft`

F-5 (PRD, P1) — "Suggest a starting plan." No request body; the client's
`HealthProfile` (calorie target, allergies) drives it.

Two generation paths, both producing the identical response shape (see
`AiDraftPlanService`'s docblock):

1. **LLM** — an OpenAI-compatible chat completion (`config/ai.php`; Groq's free
   tier by default), attempted only when `OPENAI_BASE_URL` **and**
   `OPENAI_API_KEY` are both set in the serving environment. The model may only
   choose from the allergy-filtered approved foods it is handed, and every
   response is re-validated against that exact list before it is trusted — an
   invented `food_id`, an out-of-range quantity, or the same headline food
   repeated across meals discards the **whole** response, not just the bad part.
2. **Rule-based** — the original generator, used whenever no provider is
   configured, the call fails (network, HTTP error including a `429` rate
   limit, unparseable JSON), or the response fails that validation. Picks the
   best calorie-matching **approved** food per slot plus up to two alternatives.

Both split the client's daily calorie target across the four meal slots by a
standard clinical rule of thumb (breakfast 25% / snack 10% / lunch 35% /
dinner 30%) and exclude any food whose name contains one of the client's
allergy terms (case-insensitive substring match — a real safety net,
explicitly not a certified allergen system: it can't catch an allergen an
ingredient name doesn't mention).

The fallback is silent by design: a caller always gets a usable draft and
never an AI error. That also means the response alone doesn't say which path
ran — use [`GET /system/ai-status`](#get-systemai-status) to check whether an
environment is even configured to attempt the LLM path.

**201 Created** — same shape, `is_ai_draft: true`, `status: "draft"`. Always a
draft; the nutritionist reviews (`PUT`, if anything needs changing) and
approves (`activate`) it exactly like any hand-built plan.

**422** — the client has no `HealthProfile` yet (nothing to target), or every
approved food conflicts with a listed allergy.

---

## Meal Plan Templates (Sprint 3)

S3-03 / FR-15. `permission:plans.manage`. A template is a plan with no
`subscriber_id` (`is_template: true`) that the nutritionist owns directly —
saving one is a snapshot (editing the original client's plan afterward never
changes the template), and applying one clones its meals/items into a brand
new `draft` for the target client, which still needs its own `activate` call.

### `GET /meal-plan-templates`

**200 OK** — this nutritionist's own templates (never another nutritionist's),
same response shape as a meal plan.

### `POST /clients/{id}/meal-plans/{planId}/save-as-template`

No request body. **201 Created** — a new template cloned from `planId`.

### `POST /meal-plan-templates/{templateId}/apply/{subscriberId}`

No request body. **201 Created** — a new `draft` plan for `subscriberId`,
cloned from the template. **404** if `templateId` isn't this nutritionist's
own template, or `subscriberId` isn't their own client.

---

## Food Submission & Approval (Sprint 3)

S3-05 / FR-24, BR-5.

### `POST /foods`

`permission:foods.suggest` (nutritionist). Enters `status: "pending"` —
invisible to [Food Search](#food-search-sprint-2) (yours included) until an
admin approves it.

**Request**:

```json
{
  "name_en": "Kabsa",
  "name_ar": "كبسة",
  "calories_per_100g": 180,
  "protein_g_per_100g": 8,
  "carbs_g_per_100g": 22,
  "fat_g_per_100g": 6,
  "fiber_g_per_100g": 1.5
}
```

At least one of `name_en` / `name_ar` is required, not both. `source` and
`status` aren't request fields — the server sets `source: "nutritionist"`,
`status: "pending"`, and attributes `submitted_by` to the caller; a submission
can't arrive pre-approved or attributed to someone else.

**201 Created** — the same shape as a [Food Search](#food-search-sprint-2)
result, plus `status`.

### `GET /foods/pending`

`permission:foods.approve` (admin). The review queue — without this, nothing
else surfaces a pending submission to act on. **200 OK**, paginated, same
shape as search results.

### `POST /foods/{id}/approve` · `POST /foods/{id}/reject`

`permission:foods.approve` (admin). No request body. **200 OK** with the
updated food (`status: "approved"` or `"rejected"`). **403** for a
nutritionist, including on their own submission — approval is an admin-only
action, not a self-service one.

---

## Client's Own Plan (Sprint 3)

S3-04 / FR-16. `permission:plans.view.own` (client role).

### `GET /me/meal-plan`

No route parameter — the caller's own `Subscriber` row is resolved from their
JWT, not supplied by them, so there is no client-supplied ID for one client to
point at another client's plan with. `permission:plans.view.own` is held by
the `client` role only (PRD §2.2's permission matrix) — a nutritionist or
admin calling this gets **403**, not a 204; the endpoint only exists for a
client viewing their own plan.

**200 OK** — this client's current **active** plan (never a `draft` or an
unapproved AI draft — BR-6/BR-10), same shape as [Meal
Plans](#meal-plans-sprint-3). **204 No Content** — no active plan yet.

---

## System (Operational)

Read-only operational checks. Authenticated, but no permission gate — they
report no client data.

### `GET /system/ai-status`

Whether **this deployed environment** has an AI provider configured for
[`ai-draft`](#post-clientsidmeal-plansai-draft). Setting an environment
variable in a hosting dashboard doesn't prove the running process received it,
and the AI draft's fallback is deliberately silent, so without this the only
way to tell an unconfigured environment from a failing one was to inspect the
arithmetic of a returned plan.

**200 OK**

```json
{
  "configured": true,
  "provider_host": "api.groq.com",
  "model": "openai/gpt-oss-120b",
  "timeout_seconds": 12
}
```

Never returns the API key, in whole or in part, and makes no call to the
provider (so it can't be polled to burn quota). `configured: false` means this
environment never attempts an LLM call at all. `configured: true` while drafts
still come back rule-based means the call itself or its validation is failing
— check the application log for `AI draft:`.
