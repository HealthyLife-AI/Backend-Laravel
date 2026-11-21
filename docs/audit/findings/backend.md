# Backend audit findings (agent `backend`, prefix BE-)

Audited: Backend-Laravel working tree on `audit/pre-launch-2026-11`, 2026-11-21. Tests: `tests/Feature/Audit/*`.
MySQL runs use `phpunit.audit-be.xml` (DB `hl_audit_be`). Evidence scripts/outputs: `docs/audit/evidence/backend/`.

## I1 Isolation — HTTP matrix (status: SQLite done, MySQL pending)

Generated matrix: `tests/Feature/Audit/Isolation/RouteIsolationMatrixTest.php` + fixture `IsolationWorld.php`.
Routes are read from the router in the data provider (187 `api/v1` routes today; new routes are picked up automatically).
Actors: guest, other-clinic nutritionist (victim ids; and own `{subscriber}` + victim child id), same-clinic patient,
other-clinic patient, archived patient with pre-archive token, deleted patient with pre-deletion token, admin, plus an
owner control row (owner GET must be 2xx, proving the victim ids resolve). Victim free text carries the marker `ZQVX`;
any body containing it fails. 1,519 cases.

Result on SQLite: `OK (1519 tests, 4121 assertions)` after exempting admin on admin routes (food moderation shows
every clinic's pending custom foods to the admin — by design). Status mix for hostile actors: other-clinic
nutritionist 86×404 / 70×403 / 16×200 (lists, no marker); same-clinic patient 119×403 / 17×404; archived token
179×403; deleted token 179×401; guest 179×401. 422s were checked: all on public/auth routes or the actor's own
`me/*` writes, except `PATCH appointments/{id}` (validation before ownership; body now includes `starts_at`).
No HTTP cross-clinic or cross-patient leak found. Compared with `tests/Feature/Security/CrossNutritionistIsolationTest.php`
(15 hand-picked nutritionist-B cases, no patient actors, no child-id-swap, no body inspection), the matrix is a superset.

