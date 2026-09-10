"""Generates HealthyLife-AI.postman_collection.json (+ the companion local
environment file) from the request/example data defined below.

Regenerate after adding or changing an endpoint — this script, not the
JSON output, is the source of truth for the collection. Keep it in sync
with API_CONTRACT.md (the prose version of the same contract) when either
one changes.

Usage:  python postman/build_collection.py
Verify: npx newman run postman/HealthyLife-AI.postman_collection.json \\
            --environment postman/HealthyLife-AI-Local.postman_environment.json
        (needs the Laravel API running locally — see the backend README)
"""

import json
from pathlib import Path

POSTMAN_DIR = Path(__file__).resolve().parent

JSON_HEADERS = [
    {"key": "Content-Type", "value": "application/json"},
    {"key": "Accept", "value": "application/json"},
]

def url_obj(path, query=None):
    """path like 'auth/register' or 'clients/{{subscriber_id}}/health-profile'."""
    full = "{{base_url}}/" + path
    parts = [p for p in path.split("/") if p != ""]
    o = {"raw": full, "host": ["{{base_url}}"], "path": parts}
    if query:
        q = []
        for k, v, desc, disabled in query:
            item = {"key": k, "value": v, "description": desc}
            if disabled:
                item["disabled"] = True
            q.append(item)
        o["query"] = q
        # reflect query in raw for readability
        qs = "&".join(f"{k}={v}" for k, v, _, dis in query if not dis)
        if qs:
            o["raw"] = full + "?" + qs
    return o

def body_obj(d):
    return {
        "mode": "raw",
        "raw": json.dumps(d, ensure_ascii=False, indent=2),
        "options": {"raw": {"language": "json"}},
    }

def make_request(name, method, path, description, query=None, body=None, noauth=False, tests=None, prerequest=None, examples=None):
    request = {
        "method": method,
        "header": list(JSON_HEADERS),
        "url": url_obj(path, query),
        "description": description,
    }
    if body is not None:
        request["body"] = body_obj(body)
    if noauth:
        request["auth"] = {"type": "noauth"}

    item = {"name": name, "request": request, "response": []}

    events = []
    if prerequest:
        events.append({"listen": "prerequest", "script": {"type": "text/javascript", "exec": prerequest}})
    if tests:
        events.append({"listen": "test", "script": {"type": "text/javascript", "exec": tests}})
    if events:
        item["event"] = events

    if examples:
        ex_list = []
        for ex_name, status_text, code, resp_body, resp_headers in examples:
            ex_list.append({
                "name": ex_name,
                "originalRequest": {
                    "method": method,
                    "header": list(JSON_HEADERS),
                    "url": url_obj(path, query),
                    **({"body": body_obj(body)} if body is not None else {}),
                },
                "status": status_text,
                "code": code,
                "_postman_previewlanguage": "json",
                "header": resp_headers,
                "cookie": [],
                "body": json.dumps(resp_body, ensure_ascii=False, indent=2) if resp_body is not None else "",
            })
        item["response"] = ex_list

    return item

JSON_RESP_HEADER = [{"key": "Content-Type", "value": "application/json"}]

# ---------------------------------------------------------------------------
# Test scripts (token capture)
# ---------------------------------------------------------------------------

SAVE_NUTRITIONIST_TOKENS = [
    "const body = pm.response.json();",
    "if (pm.response.code < 300) {",
    "    pm.collectionVariables.set('access_token', body.access_token);",
    "    pm.collectionVariables.set('refresh_token', body.refresh_token);",
    "    pm.test('access_token saved to collection variables', function () {",
    "        pm.expect(body.access_token).to.be.a('string');",
    "    });",
    "}",
]

SAVE_NUTRITIONIST_TOKENS_REFRESH = [
    "// /auth/refresh does not return a `user` object, only a new token pair.",
    "const body = pm.response.json();",
    "if (pm.response.code < 300) {",
    "    pm.collectionVariables.set('access_token', body.access_token);",
    "    pm.collectionVariables.set('refresh_token', body.refresh_token);",
    "}",
]

SAVE_CLIENT_TOKENS = [
    "// Kept separate from access_token/refresh_token (the nutritionist's",
    "// session) so activating a client mid-collection-run doesn't clobber",
    "// the nutritionist requests below it.",
    "const body = pm.response.json();",
    "if (pm.response.code < 300) {",
    "    pm.collectionVariables.set('client_access_token', body.access_token);",
    "    pm.collectionVariables.set('client_refresh_token', body.refresh_token);",
    "}",
]

SAVE_CLIENT_ADD_RESULT = [
    "// Chains straight into every other Clients/Health Profile/Body",
    "// Composition request below, which all reference {{subscriber_id}}.",
    "const body = pm.response.json();",
    "if (pm.response.code === 201) {",
    "    pm.collectionVariables.set('subscriber_id', body.client.id);",
    "    pm.collectionVariables.set('invite_token', body.invite_token);",
    "    pm.test('client id and invite_token saved to collection variables', function () {",
    "        pm.expect(body.client.id).to.be.a('number');",
    "        pm.expect(body.invite_token).to.be.a('string');",
    "    });",
    "}",
]

GENERATE_NUTRITIONIST_EMAIL = [
    "// A fresh, unique email every run, so Register never 422s with",
    "// 'already taken' — and Login — Nutritionist (email), right below",
    "// it in this same folder, reuses exactly this value.",
    "pm.collectionVariables.set('nutritionist_email', `jane.nutri.${Date.now()}@example.com`);",
]

GENERATE_CLIENT_PHONE = [
    "// A fresh, unique phone every run, so Add Client never 422s with",
    "// 'already taken' for this nutritionist — Activate Invite and",
    "// Login — Client (phone) reuse exactly this value.",
    "pm.collectionVariables.set('client_phone', `05${Date.now()}`.slice(0, 10));",
]

SAVE_FOOD_ID_FROM_SEARCH = [
    "// Feeds every Meal Plans request below, which builds its items",
    "// around a real, currently-approved food id rather than a hardcoded",
    "// number that may not exist on whatever database this runs against.",
    "const body = pm.response.json();",
    "if (pm.response.code < 300 && body.data && body.data.length > 0) {",
    "    pm.collectionVariables.set('food_id', body.data[0].id);",
    "    pm.test('food_id saved to collection variables', function () {",
    "        pm.expect(body.data[0].id).to.be.a('number');",
    "    });",
    "}",
]

SAVE_MEAL_PLAN_ID = [
    "const body = pm.response.json();",
    "if (pm.response.code < 300 && body.id) {",
    "    pm.collectionVariables.set('meal_plan_id', body.id);",
    "}",
]

SAVE_AI_DRAFT_PLAN_ID = [
    "const body = pm.response.json();",
    "if (pm.response.code < 300 && body.id) {",
    "    pm.collectionVariables.set('ai_draft_plan_id', body.id);",
    "}",
]

SAVE_TEMPLATE_ID = [
    "const body = pm.response.json();",
    "if (pm.response.code < 300 && body.id) {",
    "    pm.collectionVariables.set('meal_plan_template_id', body.id);",
    "}",
]

SAVE_PENDING_FOOD_ID = [
    "const body = pm.response.json();",
    "if (pm.response.code === 201 && body.id) {",
    "    pm.collectionVariables.set('pending_food_id', body.id);",
    "}",
]

STATUS_CODE_ASSERTION = lambda code: [
    f"pm.test('Status code is {code}', function () {{",
    f"    pm.response.to.have.status({code});",
    "});",
]

# ---------------------------------------------------------------------------
# Shared example payloads (kept in sync with API_CONTRACT.md)
# ---------------------------------------------------------------------------

USER_NUTRITIONIST = {
    "id": 4, "name": "Jane Nutri", "email": "jane@example.com", "phone": None,
    "role": "nutritionist", "nutritionist_id": None,
}

USER_CLIENT = {
    "id": 12, "name": "Sara Ahmad", "email": None, "phone": "0501234567",
    "role": "client", "nutritionist_id": 4,
}

TOKEN_PAIR = {
    "access_token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI0Iiwicm9sZSI6Im51dHJpdGlvbmlzdCJ9.signature",
    "refresh_token": "WpoIngUVVz7R9w2oufb2jKrcDVTsKGJnXHt0aC1x9dLLTM2sVn3Q8kR7pYb5eFgH",
    "token_type": "Bearer",
    "expires_in": 900,
}

CLIENT_OBJECT = {
    "id": 12, "code": "PT-101", "name": "Sara Ahmad", "phone": "0501234567",
    "goal": "weight_loss", "status": "pending", "adherence_status": None,
    "last_logged_at": None, "created_at": "2026-09-06T09:54:30+00:00",
}

HEALTH_PROFILE_REQUEST = {
    "weight_kg": 82, "height_cm": 170, "age": 29, "gender": "female", "activity_level": "light",
    "health_conditions": ["مقاومة أنسولين"],
    "medications": [{"name": "Levothyroxine", "dose": "50mcg", "schedule": "morning, fasting"}],
    "allergies": ["Peanuts"],
    "food_preferences": ["Vegetarian breakfast"],
    "surgery_history": None, "lab_notes": None, "nutritionist_notes": None,
}

HEALTH_PROFILE_RESPONSE = dict(HEALTH_PROFILE_REQUEST)
HEALTH_PROFILE_RESPONSE["daily_calorie_needs"] = 2168
HEALTH_PROFILE_RESPONSE["updated_at"] = "2026-09-06T09:56:45+00:00"

BODY_COMPOSITION_READING = {
    "id": 9, "recorded_at": "2026-09-01", "weight_kg": 80, "body_fat_percent": 23.5,
    "muscle_mass_kg": None, "water_percent": None, "waist_cm": None,
}

FOOD_ITEM = {
    "id": 7801, "name_en": "Hummus", "name_ar": "حمص بطحينة", "source": "admin", "status": "approved",
    "calories_per_100g": 166, "protein_g_per_100g": 7.9, "carbs_g_per_100g": 14.3,
    "fat_g_per_100g": 9.6, "fiber_g_per_100g": 6,
}

# ---- Sprint 3 (S3-08) ------------------------------------------------------

FOOD_MAIN = {
    "id": 12, "name_en": "Grilled Chicken", "name_ar": None, "source": "admin", "status": "approved",
    "calories_per_100g": 200, "protein_g_per_100g": 25, "carbs_g_per_100g": 0,
    "fat_g_per_100g": 8, "fiber_g_per_100g": 0,
}

FOOD_ALT = {
    "id": 45, "name_en": "Grilled Fish Fillet", "name_ar": None, "source": "admin", "status": "approved",
    "calories_per_100g": 150, "protein_g_per_100g": 22, "carbs_g_per_100g": 0,
    "fat_g_per_100g": 5, "fiber_g_per_100g": 0,
}

MEAL_ITEM_ALT_OBJECT = {
    "id": 56, "food": FOOD_ALT, "quantity_grams": 150.0,
    "macros": {"calories": 225.0, "protein_g": 33.0, "carbs_g": 0.0, "fat_g": 7.5},
    "alternatives": [],
}

MEAL_ITEM_OBJECT = {
    "id": 55, "food": FOOD_MAIN, "quantity_grams": 200.0,
    "macros": {"calories": 400.0, "protein_g": 50.0, "carbs_g": 0.0, "fat_g": 16.0},
    "alternatives": [MEAL_ITEM_ALT_OBJECT],
}

MEAL_OBJECT = {
    "id": 21, "name": "breakfast", "day_index": None,
    "macros": {"calories": 400.0, "protein_g": 50.0, "carbs_g": 0.0, "fat_g": 16.0},
    "items": [MEAL_ITEM_OBJECT],
}

MEAL_PLAN_OBJECT = {
    "id": 8, "subscriber_id": 3, "is_template": False, "is_ai_draft": False,
    "start_date": "2026-09-15", "status": "draft", "meals": [MEAL_OBJECT],
    "summary_by_day": {"0": {"calories": 400.0, "protein_g": 50.0, "carbs_g": 0.0, "fat_g": 16.0}},
    "created_at": "2026-09-09T10:00:00+00:00", "updated_at": "2026-09-09T10:00:00+00:00",
}

MEAL_PLAN_TEMPLATE_OBJECT = {**MEAL_PLAN_OBJECT, "id": 30, "subscriber_id": None, "is_template": True}

MEAL_PLAN_AI_DRAFT_OBJECT = {**MEAL_PLAN_OBJECT, "id": 31, "is_ai_draft": True}

MEAL_PLAN_REQUEST_BODY = {
    "start_date": "2026-09-15",
    "meals": [
        {
            "name": "breakfast",
            "day_index": None,
            "items": [{
                "food_id": "{{food_id}}",
                "quantity_grams": 200,
                "alternatives": [
                    {"food_id": "{{food_id}}", "quantity_grams": 150},
                ],
            }],
        },
    ],
}

FOOD_SUBMISSION_REQUEST = {
    "name_en": "Kabsa", "name_ar": "كبسة",
    "calories_per_100g": 180, "protein_g_per_100g": 8,
    "carbs_g_per_100g": 22, "fat_g_per_100g": 6, "fiber_g_per_100g": 1.5,
}

FOOD_SUBMISSION_RESPONSE = {**FOOD_SUBMISSION_REQUEST, "id": 9001, "source": "nutritionist", "status": "pending"}

DASHBOARD_OVERVIEW = {
    "total": 3, "active": 2, "pending": 1,
    "on_track": 1, "needs_attention": 1, "late": 0, "not_logged_today": 2,
}

VALIDATION_ERROR_EXAMPLE = {
    "message": "The email has already been taken. (and 1 more error)",
    "errors": {
        "email": ["The email has already been taken."],
        "password": ["The password field confirmation does not match."],
    },
}


# ---------------------------------------------------------------------------
# Folder: Authentication (Sprint 1)
# ---------------------------------------------------------------------------

register_req = make_request(
    name="Register Nutritionist",
    method="POST",
    path="auth/register",
    description="""FR-01. Nutritionist self-registration only — client accounts are never created here. A nutritionist creates them via **Clients → Add Client**, which issues an invite link (Sprint 2, US-02).

**Validation**

| Field | Rules |
|---|---|
| `name` | required, string, max 255 |
| `email` | required, valid email, max 255, **unique** |
| `password` | required, matches `password_confirmation`, min 8 chars, mixed case, at least one number |

A `prerequest` script generates a fresh, unique `nutritionist_email` collection variable before every send, so re-running this never 422s with "already taken" — and **Login — Nutritionist (email)**, right below it in this folder, reuses that same value, so running this collection top-to-bottom logs in as the account this request just created.

On success, the `test` script saves `access_token` / `refresh_token` as collection variables so every other authenticated request in this collection runs immediately, with no manual copy-pasting.""",
    body={
        "name": "Jane Nutri",
        "email": "{{nutritionist_email}}",
        "password": "{{nutritionist_password}}",
        "password_confirmation": "{{nutritionist_password}}",
    },
    noauth=True,
    prerequest=GENERATE_NUTRITIONIST_EMAIL,
    tests=SAVE_NUTRITIONIST_TOKENS,
    examples=[
        ("201 Created", "Created", 201, {"user": USER_NUTRITIONIST, **TOKEN_PAIR}, JSON_RESP_HEADER),
        ("422 Validation error", "Unprocessable Content", 422, VALIDATION_ERROR_EXAMPLE, JSON_RESP_HEADER),
    ],
)

login_nutritionist_req = make_request(
    name="Login — Nutritionist (email)",
    method="POST",
    path="auth/login",
    description="""FR-01 / FR-04. A **nutritionist** logs in with `email` + `password`. (A **client** has no email — see *Login — Client (phone)* in this same folder.)

Reuses `{{nutritionist_email}}` / `{{nutritionist_password}}` — the same values **Register** (just above this request) generated and saved — so running this collection top-to-bottom logs back into the account Register just created, rather than 401ing against a hardcoded stranger's email.

Saves `access_token` / `refresh_token` on success, same as Register.

**Errors**
- **401** — wrong password *or* unknown email. Deliberately identical either way, so the response never reveals which one was wrong.
- **423 Locked** — 5 consecutive failed attempts (FR-04). The *correct* password is also rejected with this while locked. `locked_until` is ISO 8601 UTC — show a countdown, not a generic error.
- **422** — missing `email` or `password`.

**Rate limit**: 10 requests/minute per IP (`429 Too Many Attempts` + a `Retry-After` header).""",
    body={"email": "{{nutritionist_email}}", "password": "{{nutritionist_password}}"},
    noauth=True,
    tests=SAVE_NUTRITIONIST_TOKENS,
    examples=[
        ("200 OK", "OK", 200, {"user": USER_NUTRITIONIST, **TOKEN_PAIR}, JSON_RESP_HEADER),
        ("401 Invalid credentials", "Unauthorized", 401,
         {"message": "These credentials do not match our records."}, JSON_RESP_HEADER),
        ("423 Account locked", "Locked", 423,
         {"message": "Account is locked due to too many failed login attempts.",
          "locked_until": "2026-09-06T12:15:00+00:00"}, JSON_RESP_HEADER),
    ],
)

login_client_req = make_request(
    name="Login — Client (phone)",
    method="POST",
    path="auth/login",
    description="""Same endpoint as *Login — Nutritionist*, different identifier: a **client** was added by name + phone only (no email — PRD F-1), so they authenticate with `phone` + `password` instead of `email` + `password`. Exactly one of `email` / `phone` must be present.

Saves the result into `client_access_token` / `client_refresh_token` (kept separate from the nutritionist's `access_token` / `refresh_token` so running this doesn't clobber the rest of the collection).

Uses `{{client_phone}}` / `{{client_password}}`. ⚠️ **Ordering note**: this request sits in the Authentication folder for discoverability, but a client's password only exists once **Clients → Activate Invite (Client)** has run — which sets `{{client_phone}}` (via Add Client) and consumes it. On a fresh top-to-bottom Collection Runner pass this folder runs *before* Clients, so this specific request will correctly `401` the first time through. Run it again by itself after Activate Invite has completed and it will succeed — that's expected sequencing, not a bug in the example.""",
    body={"phone": "{{client_phone}}", "password": "{{client_password}}"},
    noauth=True,
    tests=SAVE_CLIENT_TOKENS,
    examples=[
        ("200 OK", "OK", 200, {"user": USER_CLIENT, **TOKEN_PAIR}, JSON_RESP_HEADER),
    ],
)

refresh_req = make_request(
    name="Refresh Token",
    method="POST",
    path="auth/refresh",
    description="""FR-05. Exchanges the current `refresh_token` for a **new** access + refresh token pair. No `user` object is returned (call **Get Current User** after, if needed).

⚠️ **Single-use, rotating.** Every call consumes the token you send and issues a brand-new one. **Reusing an already-rotated (or already-logged-out) refresh token revokes every session the user holds** — this is deliberate theft detection, not a bug. Never fire this request twice in parallel with the same token.

**Errors** (both `401`, both mean "send the user back to login"):
- `Refresh token is invalid or has expired.`
- `Refresh token was already used. All sessions have been revoked as a precaution.`

**422** — missing `refresh_token`. **Rate limit**: 20 requests/minute per IP.""",
    body={"refresh_token": "{{refresh_token}}"},
    noauth=True,
    tests=SAVE_NUTRITIONIST_TOKENS_REFRESH,
    examples=[
        ("200 OK", "OK", 200, TOKEN_PAIR, JSON_RESP_HEADER),
        ("401 Reused token", "Unauthorized", 401,
         {"message": "Refresh token was already used. All sessions have been revoked as a precaution."},
         JSON_RESP_HEADER),
    ],
)

me_req = make_request(
    name="Get Current User (Me)",
    method="GET",
    path="auth/me",
    description="""Returns the authenticated user — nutritionist, client, or admin — for whichever `access_token` is sent. Uses the collection's default Bearer auth (`{{access_token}}`); switch the Authorization tab to `{{client_access_token}}` to check a client's session instead.

**401** if the token is missing, malformed, expired, or otherwise invalid — in that case call **Refresh Token** and retry once; if refresh also fails, the session is over.""",
    examples=[
        ("200 OK", "OK", 200, USER_NUTRITIONIST, JSON_RESP_HEADER),
        ("401 Unauthenticated", "Unauthorized", 401, {"message": "Unauthenticated."}, JSON_RESP_HEADER),
    ],
)

logout_req = make_request(
    name="Logout",
    method="POST",
    path="auth/logout",
    description="""Revokes **this one session's** refresh token. Idempotent — logging out twice, or logging out an already-expired session, is not an error and always returns `200`.

The already-issued access token keeps working until its own short TTL expires (it's stateless by design, no server-side access-token revocation) — a client should discard it locally immediately regardless.

**422** — missing `refresh_token`.""",
    body={"refresh_token": "{{refresh_token}}"},
    examples=[
        ("200 OK", "OK", 200, {"message": "Logged out."}, JSON_RESP_HEADER),
    ],
)

auth_folder = {
    "name": "Authentication",
    "description": "Sprint 1 (S1-06). Registration, login, JWT access/refresh token issuance, and the current-user endpoint. See the collection-level description for the shared User object, token pair, and validation-error shapes these all follow.",
    "item": [register_req, login_nutritionist_req, login_client_req, refresh_req, me_req, logout_req],
}


# ---------------------------------------------------------------------------
# Folder: Clients (Sprint 2)
# ---------------------------------------------------------------------------

add_client_req = make_request(
    name="Add Client",
    method="POST",
    path="clients",
    description="""FR-02. A nutritionist adds a client by **name + phone only — no email** (PRD F-1). Issues a single-use invite token in the same call (mirrors the refresh-token pattern: only its hash is stored server-side, the plaintext is returned **once**, here, and never retrievable again).

`phone` is unique **per nutritionist**, not globally (BR-1) — two different nutritionists can each have a client with the same phone number.

Requires `Authorization: Bearer {{access_token}}` for a **nutritionist**-role user (`clients.manage` permission) — a client or admin token gets `403`.

Build the shareable activation link yourself: `{{web_app_url}}/en/activate/<invite_token>` for the web dashboard, or a mobile deep link — the API doesn't hardcode a frontend URL scheme since more than one frontend consumes it. Default validity 7 days.

A `prerequest` script generates a fresh, unique `client_phone` before every send, so re-running this never 422s with "already taken" — and **Login — Client (phone)**, back in the Authentication folder, reuses that same value.

On success, this request's `test` script saves `subscriber_id` and `invite_token` as collection variables — every other request in the **Clients**, **Health Profile**, and **Body Composition Readings** folders is pre-wired to use them.""",
    body={"name": "Sara Ahmad", "phone": "{{client_phone}}", "goal": "weight_loss"},
    prerequest=GENERATE_CLIENT_PHONE,
    tests=SAVE_CLIENT_ADD_RESULT,
    examples=[
        ("201 Created", "Created", 201,
         {"client": CLIENT_OBJECT, "invite_token": "GHCnFz3xzIQspdcvJxX8uyYJXUGpS9WYYMh1NQeh",
          "invite_expires_at": "2026-09-13T09:54:30+00:00"}, JSON_RESP_HEADER),
        ("422 Duplicate phone", "Unprocessable Content", 422,
         {"message": "The phone has already been taken.",
          "errors": {"phone": ["The phone has already been taken."]}}, JSON_RESP_HEADER),
    ],
)

list_clients_req = make_request(
    name="List Clients",
    method="GET",
    path="clients",
    description="""FR-06. Filterable, searchable, paginated client list — scoped to the calling nutritionist's own clients only (`clients.manage`; enforced server-side, not just a UI filter — BR-2/NFR-12). Standard Laravel `data` / `links` / `meta` pagination envelope.

All query parameters are optional and start **disabled** in this request — enable whichever ones you want to test in Postman's Params tab.

`search` matches the client's name or code as a **prefix**, e.g. `sar` matches "Sara", not "Ansara" — see the collection description for why (index-friendly prefix scan, not `LIKE '%...%'`).""",
    query=[
        ("status", "active", "pending | active", True),
        ("adherence", "on_track", "on_track | needs_attention | late", True),
        ("search", "sar", "Prefix match on client name or code", True),
        ("per_page", "20", "1–100, default 20", True),
    ],
    examples=[
        ("200 OK", "OK", 200,
         {"data": [CLIENT_OBJECT],
          "links": {"first": "{{base_url}}/clients?page=1", "last": "{{base_url}}/clients?page=1",
                     "prev": None, "next": None},
          "meta": {"current_page": 1, "from": 1, "last_page": 1, "path": "{{base_url}}/clients",
                    "per_page": 20, "to": 1, "total": 1}},
         JSON_RESP_HEADER),
    ],
)

get_client_req = make_request(
    name="Get Client",
    method="GET",
    path="clients/{{subscriber_id}}",
    description="""Single client, same shape as the object embedded in **Add Client**'s response.

Route-scoped to the calling nutritionist: another nutritionist's client ID returns **404**, never `403` — the response never confirms the ID even belongs to someone else.""",
    examples=[
        ("200 OK", "OK", 200, CLIENT_OBJECT, JSON_RESP_HEADER),
        ("404 Not found / not yours", "Not Found", 404, {"message": "No query results for model [App\\\\Models\\\\Subscriber] 999"}, JSON_RESP_HEADER),
    ],
)

activate_invite_req = make_request(
    name="Activate Invite (Client)",
    method="POST",
    path="invites/{{invite_token}}/activate",
    description="""FR-03 / BR-3. **Public — no `Authorization` header; the invite token itself is the credential.** The client sets their password and is logged in immediately (same token shape as Register/Login) — Milestones US-02's acceptance criteria: "the client sets a password ... and gains access."

Run **Add Client** first so `{{invite_token}}` is populated. Sets the password to `{{client_password}}` (default `ClientPass1!`, a plain collection variable — change it in one place if you want) — **Login — Client (phone)** back in Authentication expects this exact value. On success, saves `client_access_token` / `client_refresh_token` (not the shared `access_token`, so this doesn't disturb the rest of the collection) and flips the client's `status` to `active` — re-run **Get Client** afterward to see it.

**422** — the token doesn't exist, is expired (default 7-day TTL), or was already used (single-use).""",
    body={"password": "{{client_password}}", "password_confirmation": "{{client_password}}"},
    noauth=True,
    tests=SAVE_CLIENT_TOKENS,
    examples=[
        ("200 OK", "OK", 200, {"user": USER_CLIENT, **TOKEN_PAIR}, JSON_RESP_HEADER),
        ("422 Already used", "Unprocessable Content", 422,
         {"message": "This invite link has already been used."}, JSON_RESP_HEADER),
    ],
)

clients_folder = {
    "name": "Clients",
    "description": "Sprint 2 (S2-09). Adding a client, listing/searching/filtering the roster, reading one client, and the public invite-activation endpoint a client uses once to set their password. Every list/read/write here is scoped to the calling nutritionist's own clients — see the collection description's \"Data isolation\" note.",
    "item": [add_client_req, list_clients_req, get_client_req, activate_invite_req],
}


# ---------------------------------------------------------------------------
# Folder: Health Profile (Sprint 2)
# ---------------------------------------------------------------------------

get_health_profile_req = make_request(
    name="Get Health Profile",
    method="GET",
    path="clients/{{subscriber_id}}/health-profile",
    description="""FR-07/FR-09. One profile per client. Returns **204 No Content** (not an error) if the client doesn't have one on file yet — i.e. the form hasn't been filled in.

Run **Add Client** first so `{{subscriber_id}}` is populated.""",
    examples=[
        ("200 OK", "OK", 200, HEALTH_PROFILE_RESPONSE, JSON_RESP_HEADER),
        ("204 No profile yet", "No Content", 204, None, []),
    ],
)

save_health_profile_req = make_request(
    name="Save Health Profile",
    method="PUT",
    path="clients/{{subscriber_id}}/health-profile",
    description="""FR-09/FR-11. **Upsert** — the first call for a client creates the profile (`201`), every later call updates it (`200`); identical request/response shape either way. Every field is required on **every** save, including updates — it's edited as one whole form (PRD F-3), not partially patched.

`daily_calorie_needs` is recalculated on every save from Mifflin-St Jeor × a clinical activity-tier multiplier — never a stale cached number.

| Field | Notes |
|---|---|
| `gender` | `male` \\| `female` — Mifflin-St Jeor's binary constant |
| `activity_level` | `sedentary` \\| `light` \\| `moderate` \\| `active` \\| `very_active` |
| `health_conditions` / `allergies` / `food_preferences` | arrays of plain strings |
| `medications` | array of `{ name, dose?, schedule? }` — only `name` required per entry |

**422** — bad `gender`/`activity_level` enum value, `weight_kg`/`height_cm`/`age` out of range, or a malformed `medications` entry.""",
    body=HEALTH_PROFILE_REQUEST,
    examples=[
        ("201 Created (first save)", "Created", 201, HEALTH_PROFILE_RESPONSE, JSON_RESP_HEADER),
        ("200 OK (update)", "OK", 200, HEALTH_PROFILE_RESPONSE, JSON_RESP_HEADER),
        ("422 Validation error", "Unprocessable Content", 422,
         {"message": "The selected gender is invalid.", "errors": {"gender": ["The selected gender is invalid."]}},
         JSON_RESP_HEADER),
    ],
)

health_profile_folder = {
    "name": "Health Profile",
    "description": "Sprint 2 (S2-09). The client's health profile — body data, conditions, medications, allergies, preferences — plus the Mifflin-St Jeor daily-calorie-needs calculation. One profile per client (1:1); PUT is an upsert.",
    "item": [get_health_profile_req, save_health_profile_req],
}

# ---------------------------------------------------------------------------
# Folder: Body Composition Readings (Sprint 2)
# ---------------------------------------------------------------------------

list_readings_req = make_request(
    name="List Body Composition Readings",
    method="GET",
    path="clients/{{subscriber_id}}/body-composition-readings",
    description="""FR-10: one row per visit — a history, never overwritten (unlike the Health Profile, which is a single current snapshot). Returns a **plain array**, newest first — not paginated, since a client's full history stays small.""",
    examples=[
        ("200 OK", "OK", 200, [BODY_COMPOSITION_READING], JSON_RESP_HEADER),
    ],
)

add_reading_req = make_request(
    name="Add Body Composition Reading",
    method="POST",
    path="clients/{{subscriber_id}}/body-composition-readings",
    description="""Logs one dated reading. Only `recorded_at` and `weight_kg` are required — the rest are whatever the device/visit actually captured, so a nutritionist without a body-composition scale can still log weight alone.

`recorded_at` must not be in the future.""",
    body={"recorded_at": "2026-09-06", "weight_kg": 82, "body_fat_percent": 30.5, "waist_cm": 85},
    examples=[
        ("201 Created", "Created", 201,
         {"id": 15, "recorded_at": "2026-09-06", "weight_kg": 82, "body_fat_percent": 30.5,
          "muscle_mass_kg": None, "water_percent": None, "waist_cm": 85}, JSON_RESP_HEADER),
        ("422 Future date", "Unprocessable Content", 422,
         {"message": "The recorded at must be a date before or equal to today.",
          "errors": {"recorded_at": ["The recorded at must be a date before or equal to today."]}},
         JSON_RESP_HEADER),
    ],
)

body_composition_folder = {
    "name": "Body Composition Readings",
    "description": "Sprint 2 (S2-09). FR-10 — structured body-composition measurements (fat %, muscle mass, water %, waist) preserved as a history across visits, plotted as the weight-trend chart on the client profile screen.",
    "item": [list_readings_req, add_reading_req],
}

# ---------------------------------------------------------------------------
# Folder: Food Search (Sprint 2)
# ---------------------------------------------------------------------------

search_foods_req = make_request(
    name="Search Foods",
    method="GET",
    path="foods/search",
    description="""FR-25. Searches both `name_en` and `name_ar` as a **prefix** match (`chick` matches "Chicken Breast", not "Sandwich with Chicken"; `حمص` matches "حمص بطحينة") — not a substring/`LIKE '%...%'` scan, so it can use a plain index (see the collection description).

Only `status = "approved"` foods are returned — a nutritionist's own pending submission never appears in search results for anyone, themselves included, until an admin approves it (BR-5). See **Food Submission & Approval** (Sprint 3) for that endpoint.

This request's `test` script saves the first result's `id` as `{{food_id}}` — every **Meal Plans** request below builds its items around a real, currently-approved food rather than a hardcoded id that may not exist on whatever database this runs against.

Requires only `Authorization: Bearer {{access_token}}` — any authenticated role, no specific permission (it's read-only reference data, not client data).

**422** — `q` missing or shorter than 2 characters.""",
    query=[
        ("q", "hummus", "Required, 2–255 chars", False),
        ("per_page", "20", "1–50, default 20", True),
    ],
    tests=SAVE_FOOD_ID_FROM_SEARCH,
    examples=[
        ("200 OK", "OK", 200,
         {"data": [FOOD_ITEM],
          "links": {"first": "{{base_url}}/foods/search?page=1", "last": "{{base_url}}/foods/search?page=1",
                     "prev": None, "next": None},
          "meta": {"current_page": 1, "from": 1, "last_page": 1, "path": "{{base_url}}/foods/search",
                    "per_page": 20, "to": 1, "total": 1}},
         JSON_RESP_HEADER),
        ("422 Query too short", "Unprocessable Content", 422,
         {"message": "The q field must be at least 2 characters.",
          "errors": {"q": ["The q field must be at least 2 characters."]}}, JSON_RESP_HEADER),
    ],
)

food_search_folder = {
    "name": "Food Search",
    "description": "Sprint 2 (S2-09). Read-only search over the food database — USDA SR Legacy import (English), the admin-curated Arabic layer, and (once approved) nutritionist submissions.",
    "item": [search_foods_req],
}

# ---------------------------------------------------------------------------
# Folder: Dashboard (Sprint 2)
# ---------------------------------------------------------------------------

dashboard_overview_req = make_request(
    name="Get Dashboard Overview",
    method="GET",
    path="dashboard/overview",
    description="""F-2's stat-card row, for the **calling nutritionist only** (`clients.manage`).

`on_track` / `needs_attention` / `late` / `not_logged_today` read low or zero until a later sprint's logging feature starts populating real adherence data from plan-vs-actual comparisons — that's an accurate reflection of the current data, not a bug or a placeholder to be replaced.""",
    examples=[
        ("200 OK", "OK", 200, DASHBOARD_OVERVIEW, JSON_RESP_HEADER),
    ],
)

dashboard_folder = {
    "name": "Dashboard",
    "description": "Sprint 2 (S2-09). The client-list overview stat cards (F-2) — one aggregate query, not six separate counts.",
    "item": [dashboard_overview_req],
}

# ---------------------------------------------------------------------------
# Folder: System (Operational)
# ---------------------------------------------------------------------------

AI_STATUS = {
    "configured": True,
    "provider_host": "api.groq.com",
    "model": "openai/gpt-oss-120b",
    "timeout_seconds": 12,
}

ai_status_req = make_request(
    name="AI Provider Status",
    method="GET",
    path="system/ai-status",
    description="""Whether **the environment you are pointed at** has an AI provider configured for Meal Plans -> Generate AI Draft.

Run this first when a draft looks rule-based. The AI draft falls back to its rule-based generator silently and by design, so the draft itself never tells you which path ran:

- `configured: false` -> this environment never even attempts an LLM call. Set `OPENAI_BASE_URL`, `OPENAI_API_KEY` and `OPENAI_MODEL`, then **rebuild** (not just restart) so the running process actually receives them.
- `configured: true` but drafts still come back rule-based -> the call or its validation is failing. A `429` (free-tier rate limit) is the most common cause; check the app log for `AI draft:`.

Never returns the API key, and makes no call to the provider.""",
    examples=[
        ("200 OK", "OK", 200, AI_STATUS, JSON_RESP_HEADER),
    ],
)

system_folder = {
    "name": "System",
    "description": "Read-only operational checks. Authenticated, no permission gate - they report no client data.",
    "item": [ai_status_req],
}

# ---------------------------------------------------------------------------
# Folder: Meal Plans (Sprint 3)
# ---------------------------------------------------------------------------

create_meal_plan_req = make_request(
    name="Create Meal Plan",
    method="POST",
    path="clients/{{subscriber_id}}/meal-plans",
    description="""S3-01/S3-02 / FR-12–FR-14, BR-4. Builds a plan's meals/items in one call — a plan is authored as a whole, not item-by-item. Always created as `status: "draft"` (BR-6/BR-10) regardless of what's in the body; only **Activate Meal Plan** below ever moves it to `active`.

**BR-4 alternatives**: nested under the planned item they belong to (`items[].alternatives`), not a flat list with a `parent_item_id` you supply yourself — there's no real item id to point at yet when you're creating one from scratch. Uses `{{food_id}}` (saved by **Food Search → Search Foods**, which must run first) for both the planned item and its alternative — a real, currently-approved food either way, not two arbitrary different ones, so this collection stays runnable regardless of how many foods exist on whatever database it's pointed at.

`food_id` must resolve to an **approved** food — a pending or rejected one is rejected with `422`, same as a nonexistent id.

Saves the created plan's `id` as `{{meal_plan_id}}` — every other request in this folder below uses it.""",
    body=MEAL_PLAN_REQUEST_BODY,
    tests=SAVE_MEAL_PLAN_ID,
    examples=[
        ("201 Created", "Created", 201, MEAL_PLAN_OBJECT, JSON_RESP_HEADER),
        ("422 Food not approved", "Unprocessable Content", 422,
         {"message": "The selected meals.0.items.0.food_id is invalid.",
          "errors": {"meals.0.items.0.food_id": ["The selected meals.0.items.0.food_id is invalid."]}},
         JSON_RESP_HEADER),
    ],
)

list_meal_plans_req = make_request(
    name="List Meal Plans",
    method="GET",
    path="clients/{{subscriber_id}}/meal-plans",
    description="""Every plan (any status — draft/active/archived) for this client, newest first. **404** if `{{subscriber_id}}` isn't the calling nutritionist's own client — same isolation rule as every other Clients/* endpoint.""",
    examples=[
        ("200 OK", "OK", 200, [MEAL_PLAN_OBJECT], JSON_RESP_HEADER),
    ],
)

get_meal_plan_req = make_request(
    name="Get Meal Plan",
    method="GET",
    path="clients/{{subscriber_id}}/meal-plans/{{meal_plan_id}}",
    description="""Single plan, full detail — same shape Create/List return. **404** if `{{meal_plan_id}}` doesn't belong to `{{subscriber_id}}`, or `{{subscriber_id}}` isn't the caller's own client.""",
    examples=[
        ("200 OK", "OK", 200, MEAL_PLAN_OBJECT, JSON_RESP_HEADER),
    ],
)

update_meal_plan_req = make_request(
    name="Update Meal Plan",
    method="PUT",
    path="clients/{{subscriber_id}}/meal-plans/{{meal_plan_id}}",
    description="""Same request/response shape as **Create**. **Full replace, not a patch** (PRD F-4: edited as one whole form) — omitting a meal that existed before deletes it. This is also how an AI draft gets edited before it's approved (S3-07) — there is no separate "edit a draft" endpoint.""",
    body=MEAL_PLAN_REQUEST_BODY,
    examples=[
        ("200 OK", "OK", 200, MEAL_PLAN_OBJECT, JSON_RESP_HEADER),
    ],
)

activate_meal_plan_req = make_request(
    name="Activate Meal Plan",
    method="POST",
    path="clients/{{subscriber_id}}/meal-plans/{{meal_plan_id}}/activate",
    description="""BR-6/BR-10: the **only** way a plan (hand-built or AI draft) ever becomes what the client sees. Archives whatever plan was previously `active` for this client — a client has exactly one active plan at a time. No request body.""",
    body=None,
    examples=[
        ("200 OK", "OK", 200, {**MEAL_PLAN_OBJECT, "status": "active"}, JSON_RESP_HEADER),
    ],
)

ai_draft_req = make_request(
    name="Generate AI Draft",
    method="POST",
    path="clients/{{subscriber_id}}/meal-plans/ai-draft",
    description="""S3-06/S3-07 / F-5 (PRD, P1) — "Suggest a starting plan." No request body; the client's `HealthProfile` (calorie target, allergies — saved earlier by **Health Profile → Save Health Profile**, which must run first) drives it entirely.

Rule-based, not a real LLM call — no LLM provider is wired into this backend, and F-5 itself is still an unconfirmed-wanted feature per the PRD's own validation note. Splits the daily calorie target across breakfast/snack/lunch/dinner by a standard clinical rule of thumb, picks the best calorie-matching **approved** food per slot plus up to two alternatives, and excludes any food whose name contains one of the client's allergy terms (case-insensitive substring match — a real safety net, explicitly not a certified allergen system).

Always `is_ai_draft: true`, `status: "draft"` — saved separately as `{{ai_draft_plan_id}}`, not overwriting `{{meal_plan_id}}` from **Create Meal Plan** above, so both plans stay independently addressable. **Activate AI Draft** below is how a nutritionist approves it (BR-6/BR-10) — the exact same action as approving any hand-built plan, not a special case.

**422** — the client has no `HealthProfile` yet, or every approved food conflicts with a listed allergy.""",
    body=None,
    tests=SAVE_AI_DRAFT_PLAN_ID,
    examples=[
        ("201 Created", "Created", 201, MEAL_PLAN_AI_DRAFT_OBJECT, JSON_RESP_HEADER),
        ("422 No health profile", "Unprocessable Content", 422,
         {"message": "This client needs a health profile (calorie needs, allergies) before an AI draft can be generated."},
         JSON_RESP_HEADER),
    ],
)

activate_ai_draft_req = make_request(
    name="Activate AI Draft",
    method="POST",
    path="clients/{{subscriber_id}}/meal-plans/{{ai_draft_plan_id}}/activate",
    description="""Same endpoint as **Activate Meal Plan**, called on the AI draft instead — approving it (BR-6/BR-10). Archives the plan activated earlier in this folder; `is_ai_draft` flips to `false` on activation (an activated draft has, by definition, now been reviewed). No request body.

Run **Client's Own Plan → Get My Plan** afterward to see this exact plan from the client's side.""",
    body=None,
    examples=[
        ("200 OK", "OK", 200, {**MEAL_PLAN_AI_DRAFT_OBJECT, "status": "active", "is_ai_draft": False}, JSON_RESP_HEADER),
    ],
)

meal_plans_folder = {
    "name": "Meal Plans",
    "description": "Sprint 3 (S3-01/S3-02/S3-04/S3-07). Building a client's plan with alternatives (BR-4), live per-item/meal/day macros (FR-14), and the draft -> active -> archived lifecycle that keeps BR-6/BR-10 (\"an AI draft is never auto-sent to a client\") true structurally, not just by convention.",
    "item": [create_meal_plan_req, list_meal_plans_req, get_meal_plan_req, update_meal_plan_req,
             activate_meal_plan_req, ai_draft_req, activate_ai_draft_req],
}

# ---------------------------------------------------------------------------
# Folder: Meal Plan Templates (Sprint 3)
# ---------------------------------------------------------------------------

save_as_template_req = make_request(
    name="Save As Template",
    method="POST",
    path="clients/{{subscriber_id}}/meal-plans/{{meal_plan_id}}/save-as-template",
    description="""S3-03 / FR-15. Clones `{{meal_plan_id}}`'s meals/items into a new template the nutritionist owns directly (`is_template: true`, no `subscriber_id`) — a **snapshot**: editing the original client's plan afterward never changes the template. No request body.

Saves the new template's `id` as `{{meal_plan_template_id}}`.""",
    body=None,
    tests=SAVE_TEMPLATE_ID,
    examples=[
        ("201 Created", "Created", 201, MEAL_PLAN_TEMPLATE_OBJECT, JSON_RESP_HEADER),
    ],
)

list_templates_req = make_request(
    name="List Templates",
    method="GET",
    path="meal-plan-templates",
    description="""This nutritionist's own templates only — never another nutritionist's.""",
    examples=[
        ("200 OK", "OK", 200, [MEAL_PLAN_TEMPLATE_OBJECT], JSON_RESP_HEADER),
    ],
)

apply_template_req = make_request(
    name="Apply Template",
    method="POST",
    path="meal-plan-templates/{{meal_plan_template_id}}/apply/{{subscriber_id}}",
    description="""Clones the template's meals/items into a brand-new `draft` plan for `{{subscriber_id}}` — still needs its own **Activate Meal Plan** call, exactly like any other draft (BR-6/BR-10 makes no exception for "came from a template"). No request body.

**404** if `{{meal_plan_template_id}}` isn't this nutritionist's own template, or `{{subscriber_id}}` isn't their own client.""",
    body=None,
    examples=[
        ("201 Created", "Created", 201, {**MEAL_PLAN_OBJECT, "id": 32}, JSON_RESP_HEADER),
    ],
)

meal_plan_templates_folder = {
    "name": "Meal Plan Templates",
    "description": "Sprint 3 (S3-03 / FR-15). Save a plan as a reusable template, and apply one to a (possibly different) client as a new draft.",
    "item": [save_as_template_req, list_templates_req, apply_template_req],
}

# ---------------------------------------------------------------------------
# Folder: Food Submission & Approval (Sprint 3)
# ---------------------------------------------------------------------------

submit_food_req = make_request(
    name="Submit Food",
    method="POST",
    path="foods",
    description="""S3-05 / FR-24, BR-5. `permission:foods.suggest` (nutritionist). Enters `status: "pending"` — invisible to **Food Search** (yours included) until an admin approves it below.

At least one of `name_en` / `name_ar` is required, not both. `source`/`status`/`submitted_by` aren't request fields — the server sets `source: "nutritionist"`, `status: "pending"`, and attributes the submission to the caller.

Saves the created food's `id` as `{{pending_food_id}}` — **List Pending Foods**, **Approve Food**, and **Reject Food** below all use it.""",
    body=FOOD_SUBMISSION_REQUEST,
    tests=SAVE_PENDING_FOOD_ID,
    examples=[
        ("201 Created", "Created", 201, FOOD_SUBMISSION_RESPONSE, JSON_RESP_HEADER),
    ],
)

list_pending_foods_req = make_request(
    name="List Pending Foods",
    method="GET",
    path="foods/pending",
    description="""`permission:foods.approve` (**admin** — not the collection's default nutritionist token; see the Authorization tab on this request, set to `{{admin_access_token}}`). The review queue — without this endpoint, nothing else surfaces a pending submission to act on.

⚠️ **No self-serve admin account exists** (admin is a system operator per PRD §2.1, not something anyone registers into) — `{{admin_access_token}}` starts empty and this request (and the two below it) will `401`/`403` until you set it. Create one locally once and set the variable yourself:
```
php artisan tinker --execute="$u = App\\Models\\User::factory()->create(['email' => 'admin@example.com', 'password' => 'password']); $u->assignRole('admin');"
```
then run **Authentication → Login — Nutritionist (email)** with that email/password in the Body tab temporarily (or call `/auth/login` directly) and paste the resulting `access_token` into `{{admin_access_token}}`.""",
    examples=[
        ("200 OK", "OK", 200,
         {"data": [FOOD_SUBMISSION_RESPONSE],
          "links": {"first": "{{base_url}}/foods/pending?page=1", "last": "{{base_url}}/foods/pending?page=1",
                     "prev": None, "next": None},
          "meta": {"current_page": 1, "from": 1, "last_page": 1, "path": "{{base_url}}/foods/pending",
                    "per_page": 20, "to": 1, "total": 1}},
         JSON_RESP_HEADER),
    ],
)
list_pending_foods_req["request"]["auth"] = {"type": "bearer", "bearer": [{"key": "token", "value": "{{admin_access_token}}", "type": "string"}]}

approve_food_req = make_request(
    name="Approve Food",
    method="POST",
    path="foods/{{pending_food_id}}/approve",
    description="""`permission:foods.approve` (**admin** — same `{{admin_access_token}}` note as **List Pending Foods** above). No request body. **403** for a nutritionist, including on their own submission — approval is admin-only, never self-service.""",
    body=None,
    examples=[
        ("200 OK", "OK", 200, {**FOOD_SUBMISSION_RESPONSE, "status": "approved"}, JSON_RESP_HEADER),
    ],
)
approve_food_req["request"]["auth"] = {"type": "bearer", "bearer": [{"key": "token", "value": "{{admin_access_token}}", "type": "string"}]}

reject_food_req = make_request(
    name="Reject Food",
    method="POST",
    path="foods/{{pending_food_id}}/reject",
    description="""Same role/permission as **Approve Food**. No request body.

⚠️ Runs against the **same** `{{pending_food_id}}` as **Approve Food** just above it in a top-to-bottom pass — so by the time this fires, that food is already `approved`, and this flips it straight to `rejected`. That's harmless (the endpoint doesn't forbid rejecting an already-approved food) and still proves the endpoint works; it's just not two independent submissions. Run **Submit Food** again first if you want to reject a fresh one instead.""",
    body=None,
    examples=[
        ("200 OK", "OK", 200, {**FOOD_SUBMISSION_RESPONSE, "status": "rejected"}, JSON_RESP_HEADER),
    ],
)
reject_food_req["request"]["auth"] = {"type": "bearer", "bearer": [{"key": "token", "value": "{{admin_access_token}}", "type": "string"}]}

food_submission_folder = {
    "name": "Food Submission & Approval",
    "description": "Sprint 3 (S3-05 / FR-24, BR-5). A nutritionist suggests a local food; an admin reviews, approves, or rejects it. The three admin-only requests here override the collection's default (nutritionist) Bearer auth with {{admin_access_token}} — see List Pending Foods for how to get one, since there's no self-serve admin registration.",
    "item": [submit_food_req, list_pending_foods_req, approve_food_req, reject_food_req],
}

# ---------------------------------------------------------------------------
# Folder: Client's Own Plan (Sprint 3)
# ---------------------------------------------------------------------------

get_my_plan_req = make_request(
    name="Get My Plan",
    method="GET",
    path="me/meal-plan",
    description="""S3-04 / FR-16. `permission:plans.view.own` (**client** role — not the collection's default nutritionist token; this request's Authorization tab is set to `{{client_access_token}}`, saved earlier by **Clients → Activate Invite (Client)**).

No route parameter — the caller's own `Subscriber` row is resolved from their JWT, not supplied by them, so there's no client-supplied id for one client to point at another client's plan with.

Returns this client's current **active** plan only (never a draft or an unapproved AI draft — BR-6/BR-10), same shape as **Meal Plans**. **204 No Content** if there is no active plan yet. A nutritionist or admin token gets **403** here — `plans.view.own` is a client-role permission only (PRD §2.2).""",
    examples=[
        ("200 OK", "OK", 200, {**MEAL_PLAN_AI_DRAFT_OBJECT, "status": "active", "is_ai_draft": False}, JSON_RESP_HEADER),
        ("204 No active plan yet", "No Content", 204, None, []),
    ],
)
get_my_plan_req["request"]["auth"] = {"type": "bearer", "bearer": [{"key": "token", "value": "{{client_access_token}}", "type": "string"}]}

client_own_plan_folder = {
    "name": "Client's Own Plan",
    "description": "Sprint 3 (S3-04 / FR-16). What the client (not the nutritionist) sees — their own current active plan, resolved from their own JWT with no id to isolate.",
    "item": [get_my_plan_req],
}


# ---------------------------------------------------------------------------
# Collection assembly
# ---------------------------------------------------------------------------

COLLECTION_DESCRIPTION = """# HealthyLife AI — API

AI-powered client-management platform for nutritionists. This collection documents every endpoint of the Laravel REST API (`Backend/HealthyLife-Laravel`) implemented through Sprint 3 — for the Frontend (Web Dashboard), Desktop (Electron), and Mobile (Flutter) roles integrating against it. It's the Postman companion to `API_CONTRACT.md` in the backend repo; if the two ever disagree, the code (`app/Http/Controllers/Api/**`) is the tiebreaker for both.

## Getting started

1. **Run the backend locally**: `php artisan serve` (default `http://127.0.0.1:8000`), migrated and seeded (`php artisan migrate --seed`).
2. **Set `base_url`** (collection variable, already defaulted to `http://127.0.0.1:8000/api/v1`) if your server runs elsewhere.
3. **Run `Authentication → Register Nutritionist`** once. Its test script saves `access_token` / `refresh_token` as collection variables automatically — every other authenticated request in this collection is pre-wired to use them via the collection-level Bearer auth, so nothing else needs manual token copy-pasting.
4. **Run `Clients → Add Client`** to populate `subscriber_id` and `invite_token`, which every Clients / Health Profile / Body Composition / Meal Plans request below it references.
5. Run the whole collection top-to-bottom via **Collection Runner** as a working demo of the full core loop: register → add client → fill health profile → activate invite → build a meal plan with an alternative → activate it → generate + approve an AI draft → dashboard and the client's own `/me/meal-plan` both reflect the change. Every request that needs a value from an earlier one reuses a collection variable (see each request's description) — nothing is hardcoded to a stranger's email, phone number, or food id, so a fresh run never collides with "already taken" or 404s on a food that doesn't exist on this particular database. Two exceptions, both documented on the request itself:
   - **Login — Client (phone)** sits in the Authentication folder for discoverability, but a client's password doesn't exist until **Clients → Activate Invite** has run later in the same pass — so on a first top-to-bottom run it correctly `401`s, then succeeds if you run it again by itself afterward.
   - **Food Submission & Approval**'s three admin-only requests (`List Pending Foods`, `Approve Food`, `Reject Food`) need `{{admin_access_token}}`, which starts **empty** — there's no self-serve admin registration (PRD §2.1: admin is a system operator). See **List Pending Foods**'s description for the one-time local setup.

## Authentication model

Every endpoint except `POST /auth/register`, `POST /auth/login`, `POST /auth/refresh`, and `POST /invites/{token}/activate` requires `Authorization: Bearer <access_token>` — set once at the collection level, individual requests inherit it. The four exceptions are marked **No Auth** on the request itself.

**Two layers**, matching the PRD's own security model — permission alone is never treated as security:
- **Role** (Spatie permissions) — e.g. only a `nutritionist`-role token can call the Clients endpoints; a client or admin token gets `403`.
- **Data isolation** — every Clients/Health Profile/Body Composition/Meal Plans query is scoped to the *calling* nutritionist's own data at the database layer. Another nutritionist's client or plan ID returns `404`, never `403` — the response never confirms the ID belongs to someone else. The one exception is **Client's Own Plan**, which has no ID to isolate at all (see that folder).

## Common response shapes

**User object** (`register` / `login` / `activate` / `GET /auth/me`):
```json
{ "id": 1, "name": "Demo Nutritionist", "email": "nutritionist@example.com", "phone": null, "role": "nutritionist", "nutritionist_id": null }
```
`role` is `nutritionist` \\| `client` \\| `admin`. `nutritionist_id` is only non-null for a `client`-role user.

**Token pair** (`register` / `login` / `refresh` / `activate`):
```json
{ "access_token": "eyJ...", "refresh_token": "WpoI...", "token_type": "Bearer", "expires_in": 900 }
```
- `access_token` — short-lived JWT (15 min default). Send as `Authorization: Bearer <access_token>`.
- `refresh_token` — opaque, **single-use, rotates on every `/auth/refresh` call**. Reusing an already-rotated or already-logged-out one revokes *every* session the user holds — deliberate theft detection.

**Validation error** (any endpoint, `422`):
```json
{ "message": "The email has already been taken. (and 1 more error)", "errors": { "email": ["..."] } }
```

**Rate limiting** (`429`): register/login 10 req/min, refresh 20 req/min, per IP — response includes a `Retry-After` header.

## Folders

| Folder | Covers |
|---|---|
| **Authentication** | Register, login (email or phone), refresh, logout, current user — Sprint 1 |
| **Clients** | Add / list / get a client, invite activation — Sprint 2, FR-02/FR-03/FR-06 |
| **Health Profile** | Body data, conditions, medications — Sprint 2, FR-07/FR-09/FR-11 |
| **Body Composition Readings** | Per-visit measurement history — Sprint 2, FR-10 |
| **Food Search** | USDA + Arabic-layer food lookup — Sprint 2, FR-25 |
| **Dashboard** | The client-list overview stat cards — Sprint 2, F-2 |
| **Meal Plans** | Build/edit a plan with alternatives, live macros, activate, AI draft — Sprint 3, FR-12–FR-16/BR-4/BR-6/BR-10 |
| **Meal Plan Templates** | Save a plan as a template; apply one to a client — Sprint 3, FR-15 |
| **Food Submission & Approval** | Nutritionist suggests a food; admin approves/rejects — Sprint 3, FR-24/BR-5 |
| **Client's Own Plan** | The client role's own active plan, no ID to isolate — Sprint 3, FR-16 |

---
Generated for HealthyLife AI · Sprint 1–3 backend · see `Backend/HealthyLife-Laravel/API_CONTRACT.md` for the prose version of this same contract.
"""

collection = {
    "info": {
        "name": "HealthyLife AI — API",
        "description": COLLECTION_DESCRIPTION,
        "schema": "https://schema.getpostman.com/json/collection/v2.1.0/collection.json",
        "_exporter_id": "healthylife-ai",
    },
    "auth": {
        "type": "bearer",
        "bearer": [{"key": "token", "value": "{{access_token}}", "type": "string"}],
    },
    "event": [],
    "variable": [
        {"key": "base_url", "value": "http://127.0.0.1:8000/api/v1", "type": "string",
         "description": "Laravel API base URL (no trailing slash)."},
        {"key": "web_app_url", "value": "http://localhost:3000", "type": "string",
         "description": "Next.js dashboard origin — used only in request descriptions to show how an invite link is built; not called directly by anything in this collection."},
        {"key": "nutritionist_email", "value": "jane.nutri@example.com", "type": "string",
         "description": "Auto-overwritten with a unique value by Register's prerequest script; Login — Nutritionist (email) reuses it."},
        {"key": "nutritionist_password", "value": "Passw0rd!", "type": "string",
         "description": "Used by both Register and Login — Nutritionist. Change in one place, applies everywhere."},
        {"key": "client_phone", "value": "", "type": "string",
         "description": "Auto-overwritten with a unique value by Add Client's prerequest script; Activate Invite / Login — Client (phone) reuse it."},
        {"key": "client_password", "value": "ClientPass1!", "type": "string",
         "description": "Used by both Activate Invite and Login — Client. Change in one place, applies everywhere."},
        {"key": "access_token", "value": "", "type": "string",
         "description": "Nutritionist's current JWT access token. Auto-set by Register/Login/Refresh."},
        {"key": "refresh_token", "value": "", "type": "string",
         "description": "Nutritionist's current refresh token. Auto-set by Register/Login/Refresh; rotates on every use."},
        {"key": "client_access_token", "value": "", "type": "string",
         "description": "Client's JWT access token. Auto-set by Activate Invite / Login — Client (phone)."},
        {"key": "client_refresh_token", "value": "", "type": "string",
         "description": "Client's refresh token. Auto-set alongside client_access_token."},
        {"key": "subscriber_id", "value": "", "type": "string",
         "description": "Most recently created client's id. Auto-set by Add Client."},
        {"key": "invite_token", "value": "", "type": "string",
         "description": "Most recently created client's one-time invite token. Auto-set by Add Client; consumed by Activate Invite."},
        {"key": "food_id", "value": "", "type": "string",
         "description": "An approved food's id. Auto-set by Food Search → Search Foods (its first result) — used by every Meal Plans item."},
        {"key": "meal_plan_id", "value": "", "type": "string",
         "description": "Most recently hand-built plan's id. Auto-set by Meal Plans → Create Meal Plan."},
        {"key": "ai_draft_plan_id", "value": "", "type": "string",
         "description": "Most recently generated AI draft's id. Auto-set by Meal Plans → Generate AI Draft — kept separate from meal_plan_id so both plans stay independently addressable."},
        {"key": "meal_plan_template_id", "value": "", "type": "string",
         "description": "Most recently saved template's id. Auto-set by Meal Plan Templates → Save As Template."},
        {"key": "pending_food_id", "value": "", "type": "string",
         "description": "Most recently submitted food's id. Auto-set by Food Submission & Approval → Submit Food."},
        {"key": "admin_access_token", "value": "", "type": "string",
         "description": "An admin-role JWT. Starts empty — there's no self-serve admin registration; see Food Submission & Approval → List Pending Foods for one-time local setup."},
    ],
    "item": [
        auth_folder,
        clients_folder,
        health_profile_folder,
        body_composition_folder,
        food_search_folder,
        dashboard_folder,
        system_folder,
        meal_plans_folder,
        meal_plan_templates_folder,
        food_submission_folder,
        client_own_plan_folder,
    ],
}

OUT_PATH = POSTMAN_DIR / "HealthyLife-AI.postman_collection.json"
with open(OUT_PATH, "w", encoding="utf-8") as f:
    json.dump(collection, f, ensure_ascii=False, indent=2)

print("WROTE", OUT_PATH)

# ---------------------------------------------------------------------------
# Companion environment file
# ---------------------------------------------------------------------------

def make_environment(env_id, name, base_url, web_app_url):
    return {
        "id": env_id,
        "name": name,
        "values": [
            {"key": "base_url", "value": base_url, "type": "default", "enabled": True},
            {"key": "web_app_url", "value": web_app_url, "type": "default", "enabled": True},
        ],
        "_postman_variable_scope": "environment",
    }


ENVIRONMENTS = [
    (
        "HealthyLife-AI-Local.postman_environment.json",
        make_environment("healthylife-ai-local", "HealthyLife AI — Local",
                          "http://127.0.0.1:8000/api/v1", "http://localhost:3000"),
    ),
    (
        # Temporary team-share deploy (Taqat — see backend README "Deploying
        # to Taqat"). `web_app_url` is deliberately empty: only the API is
        # hosted there, no frontend deploy exists yet to point it at.
        "HealthyLife-AI-Production.postman_environment.json",
        make_environment("healthylife-ai-production", "HealthyLife AI — Production (Taqat)",
                          "https://healthylife.apps.taqat.academy/api/v1", ""),
    ),
]

for filename, environment in ENVIRONMENTS:
    env_path = POSTMAN_DIR / filename
    with open(env_path, "w", encoding="utf-8") as f:
        json.dump(environment, f, ensure_ascii=False, indent=2)
    print("WROTE", env_path)





