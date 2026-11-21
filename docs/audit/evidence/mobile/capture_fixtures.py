#!/usr/bin/env python3
"""Captures REAL JSON from a HealthyLife API for every endpoint the Flutter
patient app calls, and writes one fixture per call to
Mobile-Flutter/test/audit/fixtures/<name>.json. The Dart contract tests in
test/audit/contract_fixtures_test.dart feed these through the app's real
Dio client and DTOs.

Reusable for any backend branch: it creates a fresh nutritionist (public
register) and fresh patients (nutritionist API) every run, so it never writes
to the database directly and never depends on seeded rows.

  python3 -I capture_fixtures.py [--base http://127.0.0.1:8000/api/v1] \
      [--out /home/user/Mobile-Flutter/test/audit/fixtures]

Tokens and generated passwords are replaced by placeholders before writing.
Never point this at production.
"""
import argparse
import json
import os
import sys
import time
import urllib.error
import urllib.request

ap = argparse.ArgumentParser()
ap.add_argument('--base', default='http://127.0.0.1:8000/api/v1')
ap.add_argument('--out', default='/home/user/Mobile-Flutter/test/audit/fixtures')
args = ap.parse_args()
if 'taqat' in args.base or 'vercel' in args.base:
    sys.exit('refusing to run against production')
BASE = args.base.rstrip('/')
OUT = args.out
os.makedirs(OUT, exist_ok=True)
opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
SECRET_KEYS = {'access_token', 'refresh_token', 'password', 'token'}
index = []


def call(method, path, token=None, body=None, query=None):
    url = f'{BASE}/{path}'
    if query:
        url += '?' + urllib.parse.urlencode(query)
    data = json.dumps(body).encode() if body is not None else None
    req = urllib.request.Request(url, data=data, method=method)
    req.add_header('Accept', 'application/json')
    if data is not None:
        req.add_header('Content-Type', 'application/json')
    if token:
        req.add_header('Authorization', f'Bearer {token}')
    try:
        with opener.open(req, timeout=30) as r:
            status, raw = r.status, r.read()
    except urllib.error.HTTPError as e:
        status, raw = e.code, e.read()
    try:
        parsed = json.loads(raw) if raw else None
    except ValueError:
        parsed = {'_non_json_body_bytes': len(raw)}
    return status, parsed


def redact(v):
    if isinstance(v, dict):
        return {k: (f'<{k}>' if k in SECRET_KEYS and isinstance(v[k], str) else redact(v[k])) for k in v}
    if isinstance(v, list):
        return [redact(x) for x in v]
    return v


def save(name, method, path, status, body, req=None):
    with open(os.path.join(OUT, f'{name}.json'), 'w', encoding='utf-8') as f:
        json.dump({'method': method, 'path': path, 'status': status,
                   'request': redact(req), 'body': redact(body)}, f, ensure_ascii=False, indent=1)
    index.append((name, method, path, status))
    print(f'{status} {method} {path} -> {name}')


def cap(name, method, path, token=None, body=None, query=None):
    st, b = call(method, path, token, body, query)
    save(name, method, path + ('?' + urllib.parse.urlencode(query) if query else ''), st, b, body)
    return st, b


import urllib.parse  # noqa: E402

stamp = str(int(time.time()))
PW = 'Fixture' + stamp[-6:] + 'x'
st, reg = call('POST', 'auth/register', body={'name': 'Fixture Nutritionist', 'email': f'fx{stamp}@example.test',
                                              'password': PW, 'password_confirmation': PW})
assert st in (200, 201), (st, reg)
NT = reg['access_token']


def patient(name, phone):
    st, c = call('POST', 'clients', NT, {'name': name, 'phone': phone, 'goal': 'weight_loss'})
    assert st in (200, 201), (st, c)
    return c['client']['id'], c['credentials']


p1, cred1 = patient('Fixture Patient Rich', f'+97059{stamp[-7:]}')
p2, cred2 = patient('Fixture Patient Empty', f'+96777{stamp[-7:]}')

# ── Nutritionist-side setup for the rich patient ──
call('PUT', f'clients/{p1}/health-profile', NT, {
    'weight_kg': 82.5, 'height_cm': 165, 'age': 34, 'gender': 'female', 'activity_level': 'light',
    'health_conditions': ['مقاومة أنسولين'], 'medications': [{'name': 'Metformin', 'dose': '500mg', 'schedule': 'مساء'}],
    'allergies': ['Peanuts'], 'food_preferences': [], 'surgery_history': None, 'lab_notes': None,
    'nutritionist_notes': 'ملاحظة داخلية'})
plan = {'approved_calories': 1600, 'plan_summary': 'خطة تجريبية', 'patient_instructions': 'اشرب الماء',
        'meals': [
            {'name': 'breakfast', 'items': [{'food_id': 31, 'quantity_grams': 100,
                                             'alternatives': [{'food_id': 56, 'quantity_grams': 150}]},
                                            {'food_id': 36, 'quantity_grams': 60.5}]},
            {'name': 'lunch', 'items': [{'food_id': 4, 'quantity_grams': 250}]},
            {'name': 'snack', 'items': [{'food_id': 56, 'quantity_grams': 150}]},
            {'name': 'dinner', 'items': [{'food_id': 29, 'quantity_grams': 300}]}],
        'activities': [{'title': 'مشي', 'description': 'مشي سريع', 'duration_minutes': 30,
                        'times_per_day': 1, 'days_per_week': 5}]}
st, pl = call('POST', f'clients/{p1}/meal-plans', NT, plan)
plan_id = (pl or {}).get('id')
call('POST', f'clients/{p1}/meal-plans/{plan_id}/activate', NT)
call('PUT', 'me/availability', NT, {'windows': [{'weekday': d, 'start': '08:00', 'end': '20:00'} for d in range(7)],
                                    'days_off': []})
call('POST', f'clients/{p1}/reviews', NT, {'rating': 'on_track', 'note': 'أحسنت', 'key_points': ['الماء'],
                                           'tasks': [{'title': 'امشِ ٣٠ دقيقة'}]})

today_s = time.strftime('%Y-%m-%d', time.gmtime())
call('PUT', f'clients/{p1}/measurement-schedule', NT, {'interval_days': 7, 'next_due_on': today_s})
call('PUT', f'clients/{p1}/checkin-settings', NT, {'interval_days': 7, 'next_due_on': today_s})
st, post = call('POST', 'posts', NT, {'title': 'نصيحة', 'body': 'اشرب الماء قبل الوجبة', 'audience': 'all', 'pinned': True})
if isinstance(post, dict):
    pid_ = post.get('id') or (post.get('data') or {}).get('id')
    call('POST', f'posts/{pid_}/publish', NT)
# ── Patient 1 ──
cap('auth_login', 'POST', 'auth/login', body={'username': cred1['username'], 'password': cred1['password']})
st, lg = call('POST', 'auth/login', body={'username': cred1['username'], 'password': cred1['password']})
T = lg['access_token']
st, rf = call('POST', 'auth/refresh', body={'refresh_token': lg['refresh_token']})
save('auth_refresh', 'POST', 'auth/refresh', st, rf)
T = rf['access_token']
cap('auth_me', 'GET', 'auth/me', T)
cap('error_consent_required_meal_plan', 'GET', 'me/meal-plan', T)
st, cons = cap('me_consent_get', 'GET', 'me/consent', T)
version = (cons or {}).get('current_version') or '1'
cap('me_consent_post', 'POST', 'me/consent', T, {'version': version})
cap('auth_me_after_consent', 'GET', 'auth/me', T)
st, mp = cap('me_meal_plan', 'GET', 'me/meal-plan', T)
cap('me_nutritionist', 'GET', 'me/nutritionist', T)
cap('me_health_profile', 'GET', 'me/health-profile', T)
cap('me_measurement_schedule', 'GET', 'me/measurement-schedule', T)
cap('me_today_before_logs', 'GET', 'me/today', T)

# meal logs: planned item, off-plan, replay of the same key, too old, patch
items = []
for meal in (mp or {}).get('meals', []) if isinstance(mp, dict) else []:
    for it in meal.get('items', []):
        items.append(it)
mi = items[0] if items else None
now = time.strftime('%Y-%m-%dT%H:%M:%S+00:00', time.gmtime())
key1 = f'00000000-0000-4000-8000-{stamp[-6:]}000001'
if mi:
    fid = (mi.get('food') or {}).get('id') or mi.get('food_id')
    cap('me_meal_logs_post_planned', 'POST', 'me/meal-logs', T,
        {'food_id': fid, 'meal_item_id': mi['id'], 'quantity_grams': mi.get('quantity_grams', 100),
         'logged_at': now, 'idempotency_key': key1})
    cap('me_meal_logs_post_replay', 'POST', 'me/meal-logs', T,
        {'food_id': fid, 'meal_item_id': mi['id'], 'quantity_grams': mi.get('quantity_grams', 100),
         'logged_at': now, 'idempotency_key': key1})
st, off = cap('me_meal_logs_post_offplan', 'POST', 'me/meal-logs', T,
              {'food_id': 36, 'quantity_grams': 80, 'logged_at': now,
               'idempotency_key': f'00000000-0000-4000-8000-{stamp[-6:]}000002'})
cap('error_meal_logs_too_old', 'POST', 'me/meal-logs', T,
    {'food_id': 36, 'quantity_grams': 80, 'logged_at': '2025-01-01T10:00:00+00:00',
     'idempotency_key': f'00000000-0000-4000-8000-{stamp[-6:]}000003'})
cap('error_meal_logs_future', 'POST', 'me/meal-logs', T,
    {'food_id': 36, 'quantity_grams': 80, 'logged_at': '2030-01-01T10:00:00+00:00',
     'idempotency_key': f'00000000-0000-4000-8000-{stamp[-6:]}000004'})
if isinstance(off, dict) and off.get('id'):
    cap('me_meal_logs_patch', 'PATCH', f'me/meal-logs/{off["id"]}', T, {'quantity_grams': 120})
today = time.strftime('%Y-%m-%d', time.gmtime())
yday = time.strftime('%Y-%m-%d', time.gmtime(time.time() - 86400))
tmrw = time.strftime('%Y-%m-%d', time.gmtime(time.time() + 86400))
cap('me_meal_logs_index', 'GET', 'me/meal-logs', T, query={'from': yday, 'to': tmrw, 'page': '1'})

cap('me_measurements_post', 'POST', 'me/measurements', T,
    {'weight_kg': 82.4, 'waist_cm': 90.5, 'recorded_at': today})
cap('me_measurements_get', 'GET', 'me/measurements', T)
cap('me_progress', 'GET', 'me/progress', T, query={'from': yday, 'to': today})
cap('me_goal_projection', 'GET', 'me/goal-projection', T)
cap('me_weight_insight', 'GET', 'me/weight-insight', T)
cap('me_today', 'GET', 'me/today', T)
cap('me_posts', 'GET', 'me/posts', T, query={'page': 1})
cap('me_posts_unread_count', 'GET', 'me/posts/unread-count', T)
st, pp = call('GET', 'me/posts', T, query={'page': 1})
try:
    cap('me_post_show', 'GET', f"me/posts/{pp['data'][0]['id']}", T)
except (KeyError, IndexError, TypeError):
    pass
cap('me_reports_consent_get', 'GET', 'me/consent', T, query={'type': 'medical_reports'})
cap('me_reports', 'GET', 'me/reports', T)
cap('me_wellbeing_put', 'PUT', f'me/wellbeing/{today}', T,
    {'sleep_hours': 7.5, 'sleep_quality': 'good', 'stress': 'low', 'mood': 'good', 'note': 'جيد'})
cap('me_wellbeing_get', 'GET', 'me/wellbeing', T, query={'from': yday, 'to': today})
cap('me_food_preferences_get', 'GET', 'me/food-preferences', T)
cap('me_food_preferences_put', 'PUT', 'me/food-preferences', T, {'likes': [36], 'dislikes': [29]})
cap('me_checkins_current', 'GET', 'me/checkins/current', T)
cap('me_quick_requests_post', 'POST', 'me/quick-requests', T, {'reason': 'food_unavailable', 'note': 'أريد تغيير العشاء'})
cap('me_quick_requests_get', 'GET', 'me/quick-requests', T)
cap('me_proposals_post', 'POST', 'me/proposals', T,
    {'kind': 'allergy', 'action': 'add', 'data': {'group': 'sesame', 'class': 'confirmed_allergy'}})
cap('me_health_profile_after_proposal', 'GET', 'me/health-profile', T)
st, slots = cap('me_appointments_slots', 'GET', 'me/appointments/slots', T, query={'type': 'follow_up'})
start = None
try:
    for d in slots.get('days', []):
        for s in d.get('slots', d.get('times', [])):
            start = s.get('starts_at') if isinstance(s, dict) else s
            if start:
                break
        if start:
            break
except AttributeError:
    pass
if start:
    cap('me_appointments_post', 'POST', 'me/appointments', T,
        {'type': 'follow_up', 'starts_at': start, 'channel': 'phone', 'topics': ['meals']})
cap('me_appointments_upcoming', 'GET', 'me/appointments', T, query={'scope': 'upcoming'})
cap('me_appointments_past', 'GET', 'me/appointments', T, query={'scope': 'past'})
st, rv = cap('me_reviews', 'GET', 'me/reviews', T)
try:
    r0 = rv[0] if isinstance(rv, list) else rv['data'][0]
    cap('me_reviews_acknowledge', 'POST', f'me/reviews/{r0["id"]}/acknowledge', T)
    cap('me_tasks_done', 'POST', f'me/tasks/{r0["tasks"][0]["id"]}/done', T)
except (KeyError, IndexError, TypeError):
    pass
st, nt = cap('me_notifications', 'GET', 'me/notifications', T, query={'page': '1'})
cap('me_notifications_unread_count', 'GET', 'me/notifications/unread-count', T)
cap('me_notification_preferences_get', 'GET', 'me/notification-preferences', T)
cap('me_notification_preferences_put', 'PUT', 'me/notification-preferences', T,
    {'locale': 'ar', 'timezone': 'Asia/Gaza'})
cap('me_fcm_token_put', 'PUT', 'me/fcm-token', T, {'fcm_token': 'fixture-fcm-token-not-real'})
cap('foods_search', 'GET', 'foods/search', T, query={'q': 'خبز', 'per_page': 50})
cap('error_unauthenticated', 'GET', 'auth/me', 'not-a-token')

# ── Patient 2: an empty account ──
st, lg2 = call('POST', 'auth/login', body={'username': cred2['username'], 'password': cred2['password']})
T2 = lg2['access_token']
st, c2 = call('GET', 'me/consent', T2)
call('POST', 'me/consent', T2, {'version': (c2 or {}).get('current_version', '1')})
for name, path, q in [('me_meal_plan', 'me/meal-plan', None), ('me_meal_logs_index', 'me/meal-logs', {'from': yday, 'to': tmrw, 'page': '1'}),
                      ('me_measurements_get', 'me/measurements', None), ('me_progress', 'me/progress', {'from': yday, 'to': today}),
                      ('me_today', 'me/today', None), ('me_health_profile', 'me/health-profile', None),
                      ('me_measurement_schedule', 'me/measurement-schedule', None), ('me_goal_projection', 'me/goal-projection', None),
                      ('me_weight_insight', 'me/weight-insight', None), ('me_reviews', 'me/reviews', None),
                      ('me_notifications', 'me/notifications', {'page': '1'}), ('me_checkins_current', 'me/checkins/current', None),
                      ('me_appointments_upcoming', 'me/appointments', {'scope': 'upcoming'}), ('me_nutritionist', 'me/nutritionist', None),
                      ('me_food_preferences_get', 'me/food-preferences', None), ('me_wellbeing_get', 'me/wellbeing', {'from': yday, 'to': today}),
                      ('me_quick_requests_get', 'me/quick-requests', None), ('me_posts', 'me/posts', {'page': 1})]:
    cap('empty_' + name, 'GET', path, T2, query=q)

# follow-up ended (archive the empty patient, then read as it)
call('POST', f'clients/{p2}/archive', NT)
cap('error_follow_up_ended', 'GET', 'me/meal-plan', T2)
cap('error_follow_up_ended_meal_log_post', 'POST', 'me/meal-logs', T2,
    {'food_id': 36, 'quantity_grams': 80, 'idempotency_key': f'00000000-0000-4000-8000-{stamp[-6:]}000009'})

with open(os.path.join(OUT, '_index.json'), 'w') as f:
    json.dump({'base': BASE, 'captured_at': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
               'fixtures': [dict(name=n, method=m, path=p, status=s) for n, m, p, s in index]}, f, indent=1)
print(f'{len(index)} fixtures in {OUT}')
