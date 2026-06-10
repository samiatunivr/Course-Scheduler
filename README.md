# University Course Scheduler & Faculty Workload Management System

Enterprise AI-powered course scheduling and faculty workload management for multi-department,
multi-campus higher-education institutions. PHP 8.4 · MySQL 8+ · Bootstrap 5 · REST API ·
OpenAI-compatible LLM integration. Runs on shared hosting (Hostinger Premium) — no Docker required.

## Highlights

- **AI schedule generation** — one click builds a full term draft respecting faculty
  qualifications, availability windows, preferences, workload caps, room capacity/equipment,
  and enrollment demand forecasts. Hard constraints are enforced deterministically in code;
  the LLM enriches recommendations and conversation, never overrides constraints.
- **Real-time conflict detection** — faculty/room double-booking, room capacity & equipment,
  availability violations, duplicate assignments, student pathway (program plan) collisions,
  co-requisite sequencing — each with an actionable resolution suggestion.
- **Workload rules engine** — configurable institutional policies (max/min credit hours,
  contact hours, preps, adjunct caps, overload, consecutive-hours, union/accreditation rules),
  utilization & equity analytics, and one-click AI rebalancing suggestions.
- **Drag-and-drop scheduler** — weekly board with instant re-validation on drop.
- **Scenario planning** — simulate enrollment surges, resignations, room closures; compare
  against baseline; apply with one click.
- **Approval workflow** — draft → AI validation → chair → dean → registrar → academic
  affairs → published, with full audit trail. AI validation blocks advancement while
  error-level conflicts exist.
- **Enterprise reporting** — one-click PDF / Excel / CSV / JSON / XML exports for schedules,
  workloads, utilization and conflicts; scheduled daily/weekly/monthly reports via cron;
  bulk CSV import with validation, preview, error reports and rollback.
- **Conversational AI assistant** — grounded in live institutional data with
  permission-checked context; works in deterministic analytics mode without an API key.
- **Multi-tenant** — host multiple institutions on one deployment: row-level tenant
  isolation enforced on every query and id parameter (cross-tenant access returns 404 and
  is audit-logged), per-tenant unique codes, tenant-aware AI/exports/imports/cron.
- **Security & auditability** — RBAC with department-scoped roles, built-in TOTP MFA and
  SAML 2.0 SSO, bcrypt, session + API token auth, tenant-scoped immutable audit trail
  (logins incl. failures, schedule changes, exports, imports, approvals) with an admin
  viewer at `/audit`, prepared statements everywhere, security headers.

## Quick start (development)

```bash
cp .env.example .env            # set DB credentials (and AI_API_KEY for full LLM mode)
php bin/migrate.php --seed      # creates schema + demo data
php -S 0.0.0.0:8080 -t public public/router.php
```

Open http://localhost:8080 — demo login `admin@example.edu` / `Admin@12345`
(change immediately).

Composer is optional: the app runs dependency-free; installing
`phpoffice/phpspreadsheet` and `dompdf/dompdf` upgrades Excel/PDF exports to native formats.

## Project layout

```
bin/                 CLI: migrations, scheduled report runner (cron)
config/              Environment-driven configuration
database/            schema.sql (DDL), seed.sql (demo data)
docs/                ERD, API spec, architecture, security, deployment guide
public/              Web root: front controller, .htaccess, JS/CSS assets
routes/              web.php (UI pages), api.php (REST API v1)
src/Core/            Micro-framework: router, DB, auth/RBAC, audit, views
src/Services/        Scheduling engine, workload rules, AI, forecasting,
                     exports, imports, notifications, workflow, scenarios
src/Views/           Bootstrap 5 server-rendered templates
storage/reports/     Generated scheduled reports (download center)
```

## Documentation

| Document | Contents |
|---|---|
| [docs/ERD.md](docs/ERD.md) | Entity-relationship diagram (Mermaid) + data dictionary |
| [docs/API.md](docs/API.md) | REST API v1 specification |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Backend, AI service & reporting architecture, UI map |
| [docs/SECURITY.md](docs/SECURITY.md) | Security architecture, RBAC matrix, GDPR/FERPA notes |
| [docs/DEPLOYMENT_HOSTINGER.md](docs/DEPLOYMENT_HOSTINGER.md) | Step-by-step Hostinger Premium deployment |

## Testing the engine quickly

```bash
# Login → token
curl -s -X POST localhost:8080/api/v1/auth/login -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.edu","password":"Admin@12345"}'

# Generate Fall 2026 draft schedule (term id from /api/v1/terms)
curl -s -X POST localhost:8080/api/v1/terms/4/generate \
  -H "Authorization: Bearer $TOKEN" -d '{"replace":true}'

# Detect conflicts / export
curl -s -X POST localhost:8080/api/v1/terms/4/detect-conflicts -H "Authorization: Bearer $TOKEN"
curl -s "localhost:8080/api/v1/export/workload/csv?term_id=4" -H "Authorization: Bearer $TOKEN"
```
