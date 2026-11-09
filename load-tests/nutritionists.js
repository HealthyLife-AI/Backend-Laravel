// Scenario B: nutritionists using the dashboard. Everything is read-only except what a person would do
// anyway; no AI draft is ever requested (it costs money and holds a worker for up to 20 seconds).
//
//   k6 run -e BASE_URL=https://api.example.com -e DEMO_NUTRITIONIST_PASSWORD=... load-tests/nutritionists.js
//
// The login limiter allows 10 sign-ins a minute for one account, so the virtual users do not sign in one
// by one: the test signs in ONCE in setup() and every virtual user shares that access token (it is a
// stateless JWT; the dashboard's reads do not depend on whose token it is). The token lasts 15 minutes;
// a run longer than that signs in again on the first 401.

import { check } from 'k6';
import {
  NUTRITIONIST_EMAIL,
  get,
  jsonBody,
  login,
  nutritionistPassword,
  stagesFromEnv,
  think,
  thresholds,
} from './lib/common.js';

export const options = {
  scenarios: {
    nutritionists: { executor: 'ramping-vus', startVUs: 0, stages: stagesFromEnv(), gracefulRampDown: '30s' },
  },
  thresholds,
  tags: { test: 'nutritionists' },
  setupTimeout: '2m',
};

const SEARCH_TERMS = ['رز', 'دجاج', 'عدس', 'خبز', 'لبن', 'تمر', 'حمص', 'سلطة'];

export function setup() {
  const session = login({ email: NUTRITIONIST_EMAIL, password: nutritionistPassword() });

  if (session === null) {
    throw new Error('Could not sign in as the demo nutritionist: check BASE_URL and DEMO_NUTRITIONIST_PASSWORD.');
  }

  return { session };
}

// Per virtual user.
let session = null;

export default function (data) {
  if (session === null) {
    session = data.session;
  }

  // Dashboard home.
  const home = get(session, '/dashboard/overview');

  if (home.status === 401) {
    // The shared token expired: one new sign-in (waits out the login limiter if many users do it at once).
    session = login({ email: NUTRITIONIST_EMAIL, password: nutritionistPassword() });
    return;
  }

  check(home, { 'dashboard: 200': (r) => r.status === 200 });
  get(session, '/alerts');
  think(1, 3);

  // The patients list, then one patient's plan.
  const list = get(session, '/clients');
  check(list, { 'patients list: 200': (r) => r.status === 200 });

  const body = jsonBody(list);
  const rows = (body && (body.data || body)) || [];
  const patient = Array.isArray(rows) && rows.length > 0 ? rows[Math.floor(Math.random() * rows.length)] : null;

  if (patient && patient.id) {
    think(1, 3);
    const plans = get(session, `/clients/${patient.id}/meal-plans`, '/clients/{id}/meal-plans');
    check(plans, { 'patient plans: 200': (r) => r.status === 200 });

    const planRows = (jsonBody(plans) || {}).data || [];
    if (planRows.length > 0) {
      const plan = get(session, `/clients/${patient.id}/meal-plans/${planRows[0].id}`, '/clients/{id}/meal-plans/{plan}');
      check(plan, { 'one plan: 200': (r) => r.status === 200 });
    }

    get(session, `/clients/${patient.id}/today`, '/clients/{id}/today');
  }

  think(2, 5);

  // Food search while building a plan.
  const term = SEARCH_TERMS[Math.floor(Math.random() * SEARCH_TERMS.length)];
  const search = get(session, `/foods/search?q=${encodeURIComponent(term)}`, '/foods/search');
  check(search, { 'food search: 200': (r) => r.status === 200 });

  think();
}
