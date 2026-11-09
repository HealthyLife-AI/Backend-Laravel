// Scenario C: the 07:30 burst. The morning push reaches every patient at once and they open the app within
// a couple of minutes: N patients, each opening it once at a random moment of a window (default 2 minutes).
//
// Opening the app after a night is: refresh the token (the access token has expired), then ask for the day,
// the plan, the notifications and the unread counts together.
//
// Each patient needs a session of their own, because a refresh token works once: sharing one between
// virtual users would look like theft and end every session of that account. setup() therefore signs in N
// times first, slowly (the login limiter allows 10 a minute per account, 60 per IP), which takes about
// 2.2 seconds per patient: ~2 minutes for 50. Only the three demo patients exist, so each account carries
// about N/3 sessions.
//
//   k6 run -e BASE_URL=https://api.example.com -e DEMO_PATIENT_PASSWORD=... -e BURST_PATIENTS=50 load-tests/burst.js

import http from 'k6/http';
import { check, sleep } from 'k6';
import exec from 'k6/execution';
import { API, HEADERS, PATIENT_USERNAMES, jsonBody, login, patientPassword, thresholds, think } from './lib/common.js';

const PATIENTS = Number(__ENV.BURST_PATIENTS || 50);
const WINDOW_SECONDS = Number(__ENV.BURST_WINDOW_S || 120);

if (!(PATIENTS > 0) || PATIENTS > 100) {
  throw new Error(`BURST_PATIENTS must be between 1 and 100; got ${__ENV.BURST_PATIENTS}`);
}

export const options = {
  scenarios: {
    burst: { executor: 'per-vu-iterations', vus: PATIENTS, iterations: 1, maxDuration: '15m' },
  },
  thresholds,
  tags: { test: 'burst' },
  setupTimeout: '10m',
};

export function setup() {
  const password = patientPassword();
  const sessions = [];

  for (let i = 0; i < PATIENTS; i++) {
    const session = login({ username: PATIENT_USERNAMES[i % PATIENT_USERNAMES.length], password });

    if (session !== null) {
      sessions.push({ refresh: session.refresh });
    }

    sleep(2.2);   // one sign-in every 2.2 s over three accounts = one per account every 6.6 s, under 10 a minute
  }

  if (sessions.length < PATIENTS / 2) {
    throw new Error(`Only ${sessions.length} of ${PATIENTS} sessions could be created: check BASE_URL and the password.`);
  }

  return { sessions };
}

export default function (data) {
  const mine = data.sessions[exec.vu.idInTest - 1];

  if (!mine) {
    return;   // setup could not create a session for this user: never reuse another's
  }

  // The patient opens the app at a random moment of the window.
  sleep(Math.random() * WINDOW_SECONDS);

  const refreshed = http.post(`${API}/auth/refresh`, JSON.stringify({ refresh_token: mine.refresh }), {
    headers: HEADERS,
    tags: { name: 'POST /auth/refresh' },
  });

  if (!check(refreshed, { 'refresh: 200': (r) => r.status === 200 })) {
    return;
  }

  const headers = { ...HEADERS, Authorization: `Bearer ${jsonBody(refreshed).access_token}` };
  const request = (path, name) => ['GET', `${API}${path}`, null, { headers, tags: { name: `GET ${name}` } }];

  const [today, plan, notifications, unread] = http.batch([
    request('/me/today', '/me/today'),
    request('/me/meal-plan', '/me/meal-plan'),
    request('/me/notifications', '/me/notifications'),
    request('/me/notifications/unread-count', '/me/notifications/unread-count'),
  ]);

  check(today, { 'today: 200': (r) => r.status === 200 });
  check(plan, { 'plan: 200': (r) => r.status === 200 });
  check(notifications, { 'notifications: 200': (r) => r.status === 200 });
  check(unread, { 'unread count: 200': (r) => r.status === 200 });

  think(2, 5);

  // A second look: the post the push was about.
  http.get(`${API}/me/posts`, { headers, tags: { name: 'GET /me/posts' } });
}
