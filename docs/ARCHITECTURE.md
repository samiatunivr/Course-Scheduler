# System Architecture

## Overview

```
┌────────────────────────────────────────────────────────────────────┐
│  Browser (Bootstrap 5 UI, Chart.js, drag-and-drop scheduler)       │
└───────────────▲───────────────────────────────▲────────────────────┘
                │ server-rendered HTML          │ JSON (fetch)
┌───────────────┴───────────────────────────────┴────────────────────┐
│  public/index.php — front controller                               │
│   • session/security headers • routing • auth/RBAC middleware      │
├──────────────────────┬─────────────────────────────────────────────┤
│  routes/web.php      │  routes/api.php (REST API v1)               │
├──────────────────────┴─────────────────────────────────────────────┤
│  Service layer (src/Services)                                      │
│   SchedulingEngine   WorkloadService    AnalyticsService           │
│   AIService          ForecastService    ScenarioService            │
│   ExportService      ImportService      WorkflowService            │
│   NotificationService                                              │
├────────────────────────────────────────────────────────────────────┤
│  Core (src/Core): Router · Database(PDO) · Auth · Audit · View     │
├────────────────────────────────────────────────────────────────────┤
│  MySQL 8+ (utf8mb4, InnoDB, FK integrity)                          │
└────────────────────────────────────────────────────────────────────┘
        │ outbound HTTPS                        │ cron
        ▼                                       ▼
  OpenAI-compatible LLM, SMTP,          bin/scheduled_reports.php
  Slack/Teams webhooks, SMS gateway     (daily/weekly/monthly)
```

Design constraints honored: shared hosting (Hostinger Premium), no Docker, no mandatory
Composer dependencies, PHP 8.4 + MySQL 8 only.

## AI service architecture

The AI layer follows a **deterministic-core, LLM-enrichment** design:

1. **Hard constraints live in code** (`SchedulingEngine`): time overlaps, room
   capacity/equipment, faculty availability, credit/contact caps. A generated or LLM-suggested
   schedule can never violate them, because suggestions are applied through the same engine.
2. **Schedule generation** is a greedy constructive heuristic:
   - demand per course = forecast ▸ latest history ▸ default capacity (scaled by scenario delta);
   - sections needed = ⌈demand / capacity⌉, largest demand placed first;
   - candidate (instructor, pattern, room) tuples are filtered by hard constraints, then scored:
     qualification level (expert 10 / preferred 7 / qualified 4) + teaching-history familiarity
     + remaining-capacity load balancing − new-prep penalty + day/time preference fit −
     wasted-seat penalty;
   - each placement updates in-memory occupancy so later placements respect earlier ones.
3. **Conflict detection** re-runs the full rule set on demand (and automatically after
   drag-and-drop moves and before workflow advancement) and persists results with
   human-readable resolution suggestions.
4. **Recommendations** (`AIService::generateRecommendations`) are rule-derived and
   machine-actionable (JSON payload): workload rebalancing, demand-driven extra sections,
   underutilized rooms, staffing gaps.
5. **Conversational assistant** (`AIService::chat`):
   - intent classification routes the question to whitelisted, permission-checked queries;
   - only aggregated results are passed to the LLM as grounded context (the model has no
     SQL/database access);
   - chat history (last 10 turns) is included per session;
   - without `AI_API_KEY` the assistant falls back to deterministic replies from the same
     context, so the feature degrades gracefully.
6. **Forecasting** (`ForecastService`): weighted least-squares trend over enrollment history
   (recent terms weighted higher), waitlists counted as latent demand.

## Reporting engine architecture

- **Dataset builders** (ExportService) produce flat row arrays per report (faculty schedule,
  department schedule, workload, utilization, conflicts).
- **Renderers** stream CSV (UTF-8 BOM), JSON, XML, Excel (native `.xlsx` when PhpSpreadsheet is
  installed, SpreadsheetML `.xls` fallback) and print-ready branded HTML (logo, institution,
  term, timestamp, page numbers via CSS paged media, executive summary) which browsers/dompdf
  convert to PDF.
- **Scheduled reports** (`bin/scheduled_reports.php`, cron): processes due rows in
  `scheduled_reports`, writes files to `storage/reports/` (internal download center), records
  them in `generated_reports`, and notifies recipients via email/Teams.
- **Custom reports**: `custom_reports` stores user-defined definitions (source, columns,
  filters, grouping, aggregates, chart) executed against the same dataset builders.
- Every export is recorded in `audit_logs`.

## Request lifecycle

1. `.htaccess`/router script funnels everything to `public/index.php`.
2. Front controller boots config (+ `.env`), session, security headers, PDO connection.
3. `Router` matches method + path (`{param}` placeholders), then enforces the route's
   permission via `Auth` (session user or hashed Bearer token → RBAC permission set).
4. Handlers call services; services use `Database` helpers (prepared statements only) and
   write `Audit` entries for state changes.
5. `Response` emits JSON, HTML (layout + view), redirects, or file downloads.

## UI map (wireframe summary)

| Page | Content |
|---|---|
| `/` Dashboard | KPI cards, room-utilization & workload charts, weekly heat map, AI recommendations, enrollment fill-rate table |
| `/scheduler` | Drag-and-drop weekly board + section list, conflict banner, AI generate / re-validate buttons, add-section modal |
| `/faculty` | Profile table with expertise badges; modal with availability, qualifications, teaching history |
| `/workload` | Per-faculty load + utilization bars + policy violations; department equity cards; one-click rebalancing |
| `/rooms` | Inventory with equipment/accessibility, utilization % and fill rates |
| `/scenarios` | Simulation form (enrollment delta, faculty out, rooms closed), baseline-vs-simulated comparison, apply |
| `/workflow` | Per-department stepper (7 stages), advance/reject, audit trail |
| `/reports` | One-click export cards (PDF/Excel/CSV/JSON), filtered exports, scheduled-report list |
| `/imports` | Upload → validation report + preview → commit → rollback |
| `/assistant` | Chat UI with quick prompts, grounded in live data |

## Scalability notes

- Conflict detection is O(n²) over a term's meetings within MySQL-indexed scopes; for very
  large institutions, partition by department/campus (the API already accepts department
  filters) or move pairwise checks into SQL self-joins.
- Heavy endpoints (generation, KPIs) are stateless → horizontal scaling behind a load
  balancer requires only shared sessions (database/redis session handler drop-in).
- Add `opcache` (enabled by default on Hostinger) and the provided indexes; the schema uses
  covering indexes on the hot paths (sections by term/faculty, meetings by room/day).
