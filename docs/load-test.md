# Load test

A repeatable [k6](https://k6.io) test that shows how the platform behaves under many users at once, and in
particular under the 07:30 burst. It is built to be run **by a person, on purpose**; nothing runs it automatically.

## What it tests

| Script | Who | What they do |
|---|---|---|
| `load-tests/patients.js` | demo patients | sign in once, refresh the token as the app does, open the app (`/me/today`, `/me/meal-plan`, notifications), log a meal, log a weight, 3–10 s of thinking between steps |
| `load-tests/nutritionists.js` | the demo nutritionist | dashboard home, alerts, patients list, one patient's plans and day, food search. **No AI draft is ever requested** (it costs money and holds a worker for up to 20 s) |
| `load-tests/burst.js` | demo patients | N patients open the app at random moments within 2 minutes: refresh the token, then today, plan, notifications and unread count together |

Only these accounts are used, from an allow-list in `load-tests/lib/common.js`: **`demo.huda`, `demo.samer`,
`demo.lin`** and `demo.nutritionist@healthylife.test`. **`demo.review` is never used**: it must keep its consent
screen for the app-store reviewers. Passwords come from environment variables; nothing is committed.

## Before you run it: three things to know

1. **Per-account rate limit.** The API allows 120 requests a minute per account. Three demo patients would be
   throttled at about 6 requests a second in total, so the test would measure the limiter, not the server.
   Set `LOAD_TEST_DEMO_API_RATE_PER_MINUTE=3000` in the app's environment for the night of the test and **unset it
   afterwards**. It applies to accounts flagged `is_demo` only; every real user keeps 120.
2. **Login limits.** Logins are limited to 10 a minute per account and 60 a minute per IP. The scripts sign in
   slowly on purpose (`burst.js` takes about 2.2 s per patient in its setup: about 2 minutes for 50) and wait out
   a 429 on login; they never hammer it. The nutritionist scenario signs in once and shares the token.
3. **The data it writes.** The patients scenario logs meals and weights for the demo patients. That is
   demo data; `php artisan demo:seed --reset` rebuilds it clean afterwards. Real data is never touched.

## Run it from your machine

```sh
# k6: https://grafana.com/docs/k6/latest/set-up/install-k6/
export BASE_URL=https://<api origin>
export DEMO_PATIENT_PASSWORD=...        # the demo patients' password
export DEMO_NUTRITIONIST_PASSWORD=...   # the demo nutritionist's password

k6 run load-tests/patients.js                       # 10 -> 25 -> 50 virtual users, 2 minutes each
VUS_1=5 VUS_2=10 VUS_3=15 STAGE_DURATION=1m k6 run load-tests/patients.js   # smaller
BURST_PATIENTS=30 k6 run load-tests/burst.js
```

Settings (environment variables): `VUS_1`, `VUS_2`, `VUS_3` (1–100), `STAGE_DURATION` (default `2m`),
`RAMP_DURATION` (`30s`), `BURST_PATIENTS` (1–100, default 50), `BURST_WINDOW_S` (120),
`REFRESH_EVERY_S` (300), `MEAL_LOG_PROBABILITY` (0.5).

## Run it from GitHub

Actions → **Load test** → *Run workflow*. Pick the scenario and the stages (defaults 10 → 25 → 50), type `YES`
in *confirm* (it checks you read the checklist below). It needs three repository secrets:
`LOAD_TEST_BASE_URL` (`https://…`), `LOAD_TEST_PATIENT_PASSWORD`, `LOAD_TEST_NUTRITIONIST_PASSWORD`. The k6
output and `summary.json` are uploaded as an artifact (30 days), and the end of the output is on the run page.

The workflow runs only when started by hand, one run at a time, and stops after 45 minutes at the latest.

## Reading the result

The run **passes** only if both hold:

- `http_req_failed` under **1%**: failed requests (5xx, timeouts, and unexpected 4xx such as a 401 or a 429 on the API).
- `http_req_duration` **p(95) under 1000 ms**.

It **stops by itself** when `http_req_failed` goes over **5%** (after the first 30 s): past that point there is
nothing more to learn, and every further request only hurts the platform.

In the k6 summary:

| Line | What to look at |
|---|---|
| `http_req_duration{name:GET /me/today}` etc. | one line per endpoint (the scripts name them): the slow endpoint is the one to fix |
| `http_req_failed` | the error rate, and which `checks` failed above it |
| `checks` | `today: 200`, `meal log: 200/201`, …: which step broke |
| `login_throttled` | sign-ins that waited for the login limiter. A few are normal in `burst.js`'s setup |
| `iteration_duration` | much longer than the think time added up means the server was slow |

What the numbers usually mean:

- **p95 climbs with the number of users, errors are zero**: the workers are queueing. Compare with
  `PHP_CLI_SERVER_WORKERS` (`docs/production.md`); look at CPU on the host while the test runs.
- **429 on API endpoints**: `LOAD_TEST_DEMO_API_RATE_PER_MINUTE` is not set; you are measuring the limiter.
- **401 in the middle of a run**: the 15-minute access token of the nutritionist scenario expired and the
  re-login was throttled; a run of 15+ minutes should use `STAGE_DURATION` short enough, or accept a few.
- **5xx or timeouts only during `burst.js`**: the burst is the problem. Check
  `GET /api/v1/system/scheduler-status` → `queue.pending` right after: it should drain within a minute or two.
- **Slow only on the first minute after a deploy**: OPcache and the route cache warming; run the test again.

Keep each run's `summary.json` (the artifact) so a later run can be compared with it.

## Safety checklist

- [ ] **Night time**, when no clinic is using the platform. The platform is shared (production, real patients).
- [ ] **Taqat has been told** the day before: date, time window, and that the traffic comes from a GitHub runner.
- [ ] `LOAD_TEST_DEMO_API_RATE_PER_MINUTE` is set for tonight (and you will unset it tomorrow).
- [ ] A **fresh backup** exists (`php artisan db:backup`, or last night's is in Telegram).
- [ ] **Start small**: `5 → 10 → 15` for the first run, then the defaults, then higher. Never jump straight to 50+.
- [ ] Someone is **watching**: the k6 output, the host's CPU and memory if you can see them, and
      `GET /api/v1/system/scheduler-status`.
- [ ] **Stop on errors**: cancel the workflow run (or Ctrl+C k6) at the first sign of real errors or of a clinic
      user reporting slowness. The test already aborts itself above 5% errors; do not wait for it.
- [ ] After the test: **unset `LOAD_TEST_DEMO_API_RATE_PER_MINUTE`**, check `queue.pending` is back to 0 and
      `queue.failed` did not grow, and `demo:seed --reset` if you want the demo data clean again.
- [ ] `burst.js` only *simulates* patients opening the app after a push; it sends no push itself. To see the real
      fan-out (rows written, sends queued) publish a post as the demo nutritionist during a quiet window and watch
      `queue.pending` drain.
