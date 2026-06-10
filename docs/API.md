# REST API v1 Specification

Base URL: `https://<host>/api/v1`. All endpoints return JSON
(`Content-Type: application/json`). Errors: `{"error": "...", "details": ...}` with
appropriate HTTP status (401, 403, 404, 410, 422, 500).

## Authentication

Two interchangeable mechanisms:

1. **Session cookie** — browser UI, established via the `/login` form.
2. **Bearer token** — `Authorization: Bearer <token>`, obtained from:

```
POST /auth/login
{"email": "...", "password": "..."}
→ 200 {"token": "<64-hex>", "user": {...}, "permissions": ["schedule.view", ...]}
```

Tokens are stored hashed (SHA-256) and expire after 30 days. Each route requires a
permission code (listed below); a missing permission yields `403`.

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/me` | schedule.view | Current user + permissions |

## Reference data

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/terms` | schedule.view | All academic terms |
| GET | `/departments` | schedule.view | Active departments |
| GET | `/courses?department_id=` | schedule.view | Course catalog |
| GET | `/rooms` | rooms.view | Rooms incl. building |
| GET | `/meeting-patterns` | schedule.view | Reusable meeting patterns |

## Faculty

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/faculty?department_id=` | faculty.view | List profiles |
| GET | `/faculty/{id}` | faculty.view | Profile + availability + qualifications + teaching history |
| POST | `/faculty` | faculty.edit | Create profile |

## Sections & schedule

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/terms/{termId}/sections` | schedule.view | Flattened sections × meetings for a term |
| POST | `/sections` | schedule.edit | Create section with `meetings: [{day,start_time,end_time,room_id,kind}]` |
| PUT | `/sections/{id}` | schedule.edit | Update (faculty_id, capacity, status, …). Assigning an instructor sends them a notification |
| PUT | `/meetings/{id}` | schedule.edit | Move a meeting (drag-and-drop); re-runs conflict detection, returns `open_conflicts` |
| DELETE | `/sections/{id}` | schedule.edit | Cancel section (soft delete) |

## AI engine

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/terms/{termId}/generate` | schedule.generate | Generate draft schedule. Body: `{replace, dry_run, department_id, enrollment_delta_pct}` → created sections + unassigned list |
| POST | `/terms/{termId}/detect-conflicts` | schedule.view | Run all conflict checks, persist & return them |
| GET | `/terms/{termId}/conflicts` | schedule.view | Open conflicts |
| POST | `/terms/{termId}/recommendations` | schedule.generate | Regenerate AI recommendations (workload, sections, rooms, staffing) |
| GET | `/terms/{termId}/recommendations` | schedule.view | Open recommendations |
| POST | `/terms/{termId}/forecast` | schedule.generate | Run enrollment forecasting (weighted trend incl. waitlists) |
| POST | `/ai/chat` | ai.chat | Conversational assistant. Body: `{message, session_id, term_id}` → `{reply, context_used}` |

## Workload

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/terms/{termId}/workloads?department_id=` | workload.view | Per-faculty load, utilization %, status, policy violations |
| GET | `/terms/{termId}/workloads/analytics` | workload.view | Department averages, std-dev, equity index, over/underload counts |
| GET | `/terms/{termId}/workloads/rebalance` | workload.view | Concrete reassignment suggestions |
| POST | `/workload-activities` | workload.edit | Record committee/advising/release/etc. |

## Analytics

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/terms/{termId}/kpis` | reports.view | Scheduling KPIs + room utilization + enrollment + heatmap |
| GET | `/enrollment/trends?course_id=` | reports.view | Historical enrollment by term |

## Exports

```
GET /export/{report}/{format}?term_id=&department_id=&faculty_id=
```

- `report`: `faculty-schedule` | `department-schedule` | `workload` | `utilization` | `conflicts`
- `format`: `csv` | `json` | `xml` | `xlsx` (native with PhpSpreadsheet, Excel-compatible
  SpreadsheetML otherwise) | `pdf` (print-ready branded HTML; native PDF with dompdf)
- Permission: `reports.export`. Every export is audit-logged.

## Imports

| Method | Path | Description |
|---|---|---|
| POST | `/import/{entity}/validate` | multipart upload (`file`); entity ∈ faculty, courses, rooms, enrollment, availability. Returns batch id, per-row error report, 10-row preview |
| POST | `/import/batches/{id}/commit` | Insert validated rows; records created ids |
| POST | `/import/batches/{id}/rollback` | Delete everything a committed batch created |

Permission: `imports.run`.

## Scenarios

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/terms/{termId}/scenarios` | scenarios.run | Simulate: `{name, parameters: {enrollment_delta_pct, exclude_faculty[], exclude_rooms[], department_id}}` → feasibility + alternative schedule (dry-run, no live changes) |
| GET | `/terms/{termId}/scenarios` | scenarios.run | Past scenarios |
| POST | `/scenarios/{id}/apply` | schedule.generate | Regenerate draft sections per scenario parameters |

## Approval workflow

| Method | Path | Permission | Description |
|---|---|---|---|
| GET | `/terms/{termId}/workflow/{deptId}` | schedule.view | State + steps + audit history |
| POST | `/terms/{termId}/workflow/{deptId}/advance` | schedule.edit¹ | Advance one step; entering chair review re-runs AI validation and refuses while error conflicts exist; publishing flips section statuses |
| POST | `/terms/{termId}/workflow/{deptId}/reject` | schedule.approve | Return to draft with comment |

¹ Later steps additionally require `schedule.approve` / `schedule.publish` (checked per step).

## Notifications

| Method | Path | Description |
|---|---|---|
| GET | `/notifications` | Current user's notifications |
| POST | `/notifications/{id}/read` | Mark read |

Channels per notification: in-app (always), email, SMS, Microsoft Teams, Slack
(webhooks configured in `.env`).
