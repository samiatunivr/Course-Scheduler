# Entity-Relationship Diagram

Full DDL: [`database/schema.sql`](../database/schema.sql). Render the Mermaid diagrams on GitHub
or any Mermaid viewer.

## Core scheduling domain

```mermaid
erDiagram
    CAMPUSES ||--o{ BUILDINGS : contains
    BUILDINGS ||--o{ ROOMS : contains
    COLLEGES ||--o{ DEPARTMENTS : contains
    DEPARTMENTS ||--o{ COURSES : offers
    DEPARTMENTS ||--o{ FACULTY : employs
    DEPARTMENTS ||--o{ PROGRAMS : owns
    PROGRAMS ||--o{ PROGRAM_COURSES : requires
    COURSES ||--o{ PROGRAM_COURSES : "appears in"
    COURSES ||--o{ COURSE_REQUISITES : "pre/co-requisite"
    COURSES ||--o{ COURSE_CROSS_LISTINGS : "cross-listed"
    ACADEMIC_YEARS ||--o{ TERMS : contains
    TERMS ||--o{ CALENDAR_EVENTS : has
    COURSES ||--o{ SECTIONS : "instantiated as"
    TERMS ||--o{ SECTIONS : schedules
    FACULTY |o--o{ SECTIONS : teaches
    CAMPUSES |o--o{ SECTIONS : "hosted at"
    SECTIONS ||--o{ SECTION_MEETINGS : "meets as"
    ROOMS |o--o{ SECTION_MEETINGS : "held in"
    ROOMS ||--o{ ROOM_CLOSURES : "closed by"
    MEETING_PATTERNS }o..o{ SECTION_MEETINGS : templates

    SECTIONS {
        bigint id PK
        bigint course_id FK
        bigint term_id FK
        bigint faculty_id FK "nullable"
        varchar section_no
        enum delivery_mode "on_campus|online_sync|online_async|hybrid"
        smallint capacity
        smallint enrolled
        enum status "draft|scheduled|approved|published|cancelled"
    }
    SECTION_MEETINGS {
        bigint id PK
        bigint section_id FK
        bigint room_id FK "nullable"
        enum day "Mon..Sun"
        time start_time
        time end_time
        enum kind "lecture|lab|seminar|exam|online"
    }
```

## Faculty & workload domain

```mermaid
erDiagram
    FACULTY ||--o{ FACULTY_AVAILABILITY : declares
    FACULTY ||--o{ FACULTY_COURSE_QUALIFICATIONS : "qualified for"
    COURSES ||--o{ FACULTY_COURSE_QUALIFICATIONS : "taught by"
    FACULTY ||--o{ WORKLOAD_ACTIVITIES : performs
    TERMS ||--o{ WORKLOAD_ACTIVITIES : "in term"
    DEPARTMENTS |o--o{ WORKLOAD_POLICIES : "scoped to"

    FACULTY {
        bigint id PK
        bigint department_id FK
        enum rank "professor..adjunct"
        enum contract_type "full_time|part_time|adjunct|visiting|ta"
        enum status "active|sabbatical|leave|retired|resigned"
        decimal max_credit_hours
        decimal max_contact_hours
        decimal research_release_hours "standing release"
        json qualifications
        json expertise
        json preferences "preferred_days, avoid_early, evening_only"
    }
    WORKLOAD_ACTIVITIES {
        bigint id PK
        enum type "research_release|admin_duty|committee|advising|coordination|chair|sabbatical|leave"
        decimal credit_hour_equivalent
    }
    WORKLOAD_POLICIES {
        bigint id PK
        enum rule_type "max_credit_hours|min_credit_hours|max_contact_hours|max_preps|adjunct_max_credit_hours|max_overload_hours|max_consecutive_hours|..."
        decimal rule_value
        enum severity "error|warning|info"
    }
```

## AI, demand & operations domain

```mermaid
erDiagram
    TERMS ||--o{ SCHEDULE_CONFLICTS : "detected in"
    SECTIONS |o--o{ SCHEDULE_CONFLICTS : involves
    TERMS ||--o{ AI_RECOMMENDATIONS : "generated for"
    USERS ||--o{ AI_CHAT_MESSAGES : converses
    COURSES ||--o{ ENROLLMENT_HISTORY : "has demand"
    COURSES ||--o{ ENROLLMENT_FORECASTS : "predicted by"
    TERMS ||--o{ SCENARIOS : simulates
    TERMS ||--o{ APPROVAL_WORKFLOWS : approves
    DEPARTMENTS ||--o{ APPROVAL_WORKFLOWS : "per department"
    APPROVAL_WORKFLOWS ||--o{ APPROVAL_ACTIONS : records
    USERS ||--o{ NOTIFICATIONS : receives
    SCHEDULED_REPORTS ||--o{ GENERATED_REPORTS : produces
    USERS ||--o{ IMPORT_BATCHES : uploads
    USERS ||--o{ CUSTOM_REPORTS : designs
```

## Security domain

```mermaid
erDiagram
    USERS ||--o{ USER_ROLES : holds
    ROLES ||--o{ USER_ROLES : grants
    DEPARTMENTS |o--o{ USER_ROLES : "scopes (optional)"
    ROLES ||--o{ ROLE_PERMISSIONS : includes
    PERMISSIONS ||--o{ ROLE_PERMISSIONS : "granted by"
    USERS ||--o{ API_TOKENS : owns
    USERS |o--o{ AUDIT_LOGS : acted
```

## Data dictionary (key tables)

| Table | Purpose |
|---|---|
| `terms` | Academic terms (semester/quarter/summer/mini) with workflow status |
| `courses` | Catalog: credit/contact hours, capacity, lab & equipment needs, requisites |
| `sections` | The schedulable unit — course × term × section number |
| `section_meetings` | Individual weekly meetings (a section may have lecture + lab) |
| `faculty` | Profiles: rank, contract, caps, releases, expertise (JSON), preferences (JSON) |
| `faculty_availability` | Weekly availability/preference windows used as hard constraints |
| `faculty_course_qualifications` | Qualification level + teaching history per course (drives AI scoring) |
| `workload_activities` | Non-teaching load with credit-hour equivalents |
| `workload_policies` | Configurable rules engine (institution- or department-scoped) |
| `schedule_conflicts` | Persisted conflict detections with AI suggestions and lifecycle |
| `ai_recommendations` | Actionable machine-applicable recommendations (JSON payload) |
| `enrollment_history` / `enrollment_forecasts` | Demand inputs for the generator |
| `scenarios` | What-if simulations with parameters and results (JSON) |
| `approval_workflows` / `approval_actions` | Approval pipeline state + audit trail |
| `import_batches` | Bulk import validation/commit/rollback bookkeeping |
| `audit_logs` | Immutable record of every state-changing action |
