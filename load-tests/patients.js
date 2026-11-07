// Scenario A: patients using the app. Each virtual user signs in once as one of the three demo patients,
// refreshes the token the way the app does, and loops: open the app (today, plan, notifications), think,
// log a meal, log a weight, think.
//
//   k6 run -e BASE_URL=https://api.example.com -e DEMO_PATIENT_PASSWORD=... load-tests/patients.js
//
// More in docs/load-test.md.

import { check, sleep } from 'k6';
import exec from 'k6/execution';
import {
  PATIENT_USERNAMES,
  ensureFresh,
  get,
  jsonBody,
  login,
  patientPassword,
  post,
  stagesFromEnv,
  think,
  thresholds,
  today,
  uuid,
} from './lib/common.js';

export const options = {
  scenarios: {
    patients: { executor: 'ramping-vus', startVUs: 0, stages: stagesFromEnv(), gracefulRampDown: '30s' },
  },
  thresholds,
  tags: { test: 'patients' },
};

// Module-level state is per virtual user in k6.
let session = null;

function ok(res, label) {
  return check(res, { [`${label}: 200`]: (r) => r.status === 200 });
}

export default function () {
  if (session === null) {
    sleep(Math.random() * 20);   // spread the first sign-ins: the login limiter allows 10 a minute per account
    const username = PATIENT_USERNAMES[(exec.vu.idInTest - 1) % PATIENT_USERNAMES.length];
    session = login({ username, password: patientPassword() });

    if (session === null) {
      sleep(10);
      return;
    }
  }

  const fresh = ensureFresh(session);
  if (fresh === null) {
    session = null;   // the refresh was refused: sign in again next time round
    return;
  }
  session = fresh;

  // Open the app.
  const todayRes = get(session, '/me/today');
  const planRes = get(session, '/me/meal-plan');
  const notificationsRes = get(session, '/me/notifications');
  get(session, '/me/notifications/unread-count');

  if ([todayRes, planRes, notificationsRes].some((r) => r.status === 401)) {
    session = null;
    return;
  }

  ok(todayRes, 'today');
  ok(planRes, 'meal plan');
  ok(notificationsRes, 'notifications');

  think();

  // Log a meal: the first item of the plan, at its planned quantity.
  const plan = jsonBody(planRes);
  const meal = plan && plan.meals && plan.meals[0];
  const item = meal && meal.items && meal.items[0];

  if (item && item.food && Math.random() < Number(__ENV.MEAL_LOG_PROBABILITY || 0.5)) {
    const res = post(
      session,
      '/me/meal-logs',
      { food_id: item.food.id, quantity_grams: item.quantity_grams || 100, idempotency_key: uuid() },
      '/me/meal-logs',
    );
    check(res, { 'meal log: 200/201': (r) => r.status === 200 || r.status === 201 });
  }

  // Log a weight (one reading a day: a second one the same day replaces the first).
  const weight = Math.round((60 + Math.random() * 40) * 10) / 10;
  const weightRes = post(session, '/me/measurements', { weight_kg: weight, recorded_at: today() }, '/me/measurements');
  check(weightRes, { 'weight: 200/201': (r) => r.status === 200 || r.status === 201 });

  think();
}
