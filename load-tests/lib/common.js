// Shared pieces of the k6 load tests (see docs/load-test.md).
//
// Only the demo accounts made by `php artisan demo:seed` are used, and the credentials come from the
// environment (DEMO_PATIENT_PASSWORD, DEMO_NUTRITIONIST_PASSWORD): nothing secret is in this repo.
// demo.review is NEVER used: it must keep its consent screen untouched for the store reviewers.

import http from 'k6/http';
import { sleep } from 'k6';
import { Counter } from 'k6/metrics';

export const BASE_URL = (__ENV.BASE_URL || '').replace(/\/+$/, '');

if (!BASE_URL) {
  throw new Error('BASE_URL is required: the API origin, e.g. BASE_URL=https://api.example.com');
}

export const API = `${BASE_URL}/api/v1`;

// An allow-list, not a deny-list: nothing else can be signed in as.
export const PATIENT_USERNAMES = ['demo.huda', 'demo.samer', 'demo.lin'];
export const NUTRITIONIST_EMAIL = 'demo.nutritionist@healthylife.test';

const MAX_VUS = 100;

export const HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

/** Logins refused by the login rate limit (10 a minute per account, 60 per IP): waited out, counted here. */
export const loginThrottled = new Counter('login_throttled');

export function patientPassword() {
  const password = __ENV.DEMO_PATIENT_PASSWORD;
  if (!password) {
    throw new Error('DEMO_PATIENT_PASSWORD is required (the password printed by demo:seed for the demo patients)');
  }
  return password;
}

export function nutritionistPassword() {
  const password = __ENV.DEMO_NUTRITIONIST_PASSWORD;
  if (!password) {
    throw new Error('DEMO_NUTRITIONIST_PASSWORD is required (the password printed by demo:seed for the demo nutritionist)');
  }
  return password;
}

/** Ramp up to each target in turn and hold it: VUS_1 -> VUS_2 -> VUS_3 (default 10 -> 25 -> 50). */
export function stagesFromEnv() {
  const targets = [__ENV.VUS_1 || 10, __ENV.VUS_2 || 25, __ENV.VUS_3 || 50].map(Number);
  const hold = __ENV.STAGE_DURATION || '2m';
  const ramp = __ENV.RAMP_DURATION || '30s';

  targets.forEach((n) => {
    if (!(n > 0) || n > MAX_VUS) {
      throw new Error(`VU targets must be between 1 and ${MAX_VUS}; got ${targets.join(', ')}`);
    }
  });

  const stages = [];
  targets.forEach((target) => {
    stages.push({ duration: ramp, target });
    stages.push({ duration: hold, target });
  });
  stages.push({ duration: ramp, target: 0 });

  return stages;
}

// The pass/fail rules. The second failure threshold stops the run early: past 5% errors there is
// nothing more to learn and every further request only hurts the platform.
export const thresholds = {
  http_req_failed: [
    { threshold: 'rate<0.01' },
    { threshold: 'rate<0.05', abortOnFail: true, delayAbortEval: '30s' },
  ],
  http_req_duration: ['p(95)<1000'],
};

/** Seconds a person takes between taps: 3 to 10. */
export function think(min = 3, max = 10) {
  sleep(min + Math.random() * (max - min));
}

export function uuid() {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });
}

function sessionFrom(body) {
  const now = Date.now();
  return {
    access: body.access_token,
    refresh: body.refresh_token,
    expiresAt: now + Number(body.expires_in || 900) * 1000,
    refreshedAt: now,
  };
}

function jsonOf(res) {
  try {
    return res.json();
  } catch (e) {
    return null;
  }
}

/**
 * Signs in. A 429 from the login limiter is waited out (and counted), not treated as a failure of the
 * platform. Returns a session, or null when the sign-in failed for another reason.
 */
export function login(credentials) {
  for (let attempt = 0; attempt < 8; attempt++) {
    const res = http.post(`${API}/auth/login`, JSON.stringify(credentials), {
      headers: HEADERS,
      tags: { name: 'POST /auth/login' },
      responseCallback: http.expectedStatuses(200, 429),
    });

    if (res.status === 200) {
      const body = jsonOf(res);
      return body ? sessionFrom(body) : null;
    }

    if (res.status === 429) {
      loginThrottled.add(1);
      sleep(Math.min(60, Number(res.headers['Retry-After']) || 10) + Math.random() * 3);
      continue;
    }

    return null;
  }

  return null;
}

/**
 * What the app does: it refreshes shortly before the access token expires (15 minutes), and the test
 * also refreshes every REFRESH_EVERY_S seconds (default 300) so the rotation path is exercised in a
 * short run. Returns the new session, the same one when nothing is due, or null if the refresh failed.
 */
export function ensureFresh(session) {
  const everyMs = Number(__ENV.REFRESH_EVERY_S || 300) * 1000;
  const now = Date.now();

  if (now < session.expiresAt - 60000 && now - session.refreshedAt < everyMs) {
    return session;
  }

  const res = http.post(`${API}/auth/refresh`, JSON.stringify({ refresh_token: session.refresh }), {
    headers: HEADERS,
    tags: { name: 'POST /auth/refresh' },
  });

  if (res.status !== 200) {
    return null;
  }

  const body = jsonOf(res);
  return body ? sessionFrom(body) : null;
}

function authed(session) {
  return { ...HEADERS, Authorization: `Bearer ${session.access}` };
}

/** `name` groups URLs with ids in them into one metric. */
export function get(session, path, name) {
  return http.get(`${API}${path}`, { headers: authed(session), tags: { name: `GET ${name || path}` } });
}

export function post(session, path, body, name) {
  return http.post(`${API}${path}`, JSON.stringify(body), { headers: authed(session), tags: { name: `POST ${name || path}` } });
}

export function jsonBody(res) {
  return jsonOf(res);
}

/** The clinic's "today" is not UTC's, but a plain date is enough here: the API accepts today and the past. */
export function today() {
  return new Date().toISOString().slice(0, 10);
}
