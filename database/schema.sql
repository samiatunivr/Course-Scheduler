-- =====================================================================
-- University Course Scheduler & Faculty Workload Management System
-- MySQL 8+ schema
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Identity, security & RBAC
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS users (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email           VARCHAR(255) NOT NULL UNIQUE,
    name            VARCHAR(150) NOT NULL,
    password_hash   VARCHAR(255) NULL,
    sso_provider    ENUM('local','google','microsoft','saml','ldap') NOT NULL DEFAULT 'local',
    sso_subject     VARCHAR(255) NULL,
    mfa_secret      VARCHAR(64)  NULL,
    mfa_enabled     TINYINT(1) NOT NULL DEFAULT 0,
    is_active       TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at   DATETIME NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS roles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(50) NOT NULL UNIQUE,        -- admin, registrar, dean, chair, faculty, scheduler, viewer
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS permissions (
    id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code  VARCHAR(80) NOT NULL UNIQUE,              -- e.g. schedule.edit, workload.view, report.export
    name  VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id       INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Role assignment, optionally scoped to a department (department-level permissions)
CREATE TABLE IF NOT EXISTS user_roles (
    user_id       BIGINT UNSIGNED NOT NULL,
    role_id       INT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (user_id, role_id, department_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS api_tokens (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    abilities  JSON NULL,
    expires_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     BIGINT UNSIGNED NULL,
    action      VARCHAR(80) NOT NULL,               -- create / update / delete / login / approve / export ...
    entity_type VARCHAR(80) NOT NULL,
    entity_id   BIGINT UNSIGNED NULL,
    old_values  JSON NULL,
    new_values  JSON NULL,
    ip_address  VARCHAR(45) NULL,
    user_agent  VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Institutional structure
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS campuses (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code      VARCHAR(20) NOT NULL UNIQUE,
    name      VARCHAR(150) NOT NULL,
    address   VARCHAR(255) NULL,
    timezone  VARCHAR(64) NOT NULL DEFAULT 'UTC',
    is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS buildings (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campus_id BIGINT UNSIGNED NOT NULL,
    code      VARCHAR(20) NOT NULL,
    name      VARCHAR(150) NOT NULL,
    UNIQUE KEY uq_building (campus_id, code),
    FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS colleges (
    id   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(20) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS departments (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    college_id BIGINT UNSIGNED NULL,
    code       VARCHAR(20) NOT NULL UNIQUE,
    name       VARCHAR(150) NOT NULL,
    chair_user_id BIGINT UNSIGNED NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (college_id) REFERENCES colleges(id) ON DELETE SET NULL,
    FOREIGN KEY (chair_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Academic terms & calendar
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS academic_years (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(20) NOT NULL UNIQUE,         -- e.g. 2026-2027
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS terms (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year_id BIGINT UNSIGNED NOT NULL,
    code             VARCHAR(20) NOT NULL UNIQUE,   -- e.g. FA2026
    name             VARCHAR(100) NOT NULL,         -- Fall 2026
    type             ENUM('semester','quarter','summer','mini','intersession') NOT NULL DEFAULT 'semester',
    start_date       DATE NOT NULL,
    end_date         DATE NOT NULL,
    status           ENUM('planning','draft','review','approved','published','archived') NOT NULL DEFAULT 'planning',
    FOREIGN KEY (academic_year_id) REFERENCES academic_years(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS calendar_events (
    id       BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id  BIGINT UNSIGNED NOT NULL,
    name     VARCHAR(150) NOT NULL,                 -- holidays, exam weeks, no-class days
    type     ENUM('holiday','exam','break','deadline','event') NOT NULL DEFAULT 'event',
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Reusable meeting patterns, e.g. MWF 09:00-09:50, TR 13:00-14:15
CREATE TABLE IF NOT EXISTS meeting_patterns (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(30) NOT NULL UNIQUE,
    days       SET('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    minutes_per_week SMALLINT UNSIGNED NOT NULL DEFAULT 150
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Course catalog
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS courses (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NOT NULL,
    code          VARCHAR(20) NOT NULL UNIQUE,      -- CSC301
    title         VARCHAR(200) NOT NULL,
    description   TEXT NULL,
    credit_hours  DECIMAL(4,1) NOT NULL DEFAULT 3.0,
    contact_hours DECIMAL(4,1) NOT NULL DEFAULT 3.0,
    level         ENUM('undergraduate','graduate','doctoral') NOT NULL DEFAULT 'undergraduate',
    default_capacity SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    requires_lab  TINYINT(1) NOT NULL DEFAULT 0,
    required_equipment JSON NULL,                   -- ["projector","lab-pcs"]
    is_core       TINYINT(1) NOT NULL DEFAULT 0,    -- core curriculum flag
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS course_requisites (
    course_id           BIGINT UNSIGNED NOT NULL,
    requisite_course_id BIGINT UNSIGNED NOT NULL,
    type                ENUM('prerequisite','corequisite') NOT NULL DEFAULT 'prerequisite',
    PRIMARY KEY (course_id, requisite_course_id, type),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (requisite_course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS course_cross_listings (
    course_id        BIGINT UNSIGNED NOT NULL,
    cross_listed_with BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (course_id, cross_listed_with),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (cross_listed_with) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Academic programs / majors (for student pathway conflict checking)
CREATE TABLE IF NOT EXISTS programs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NOT NULL,
    code          VARCHAR(20) NOT NULL UNIQUE,
    name          VARCHAR(200) NOT NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Courses required by a program in a given study-plan semester; sections of
-- courses sharing (program, plan_semester) must not overlap in time.
CREATE TABLE IF NOT EXISTS program_courses (
    program_id    BIGINT UNSIGNED NOT NULL,
    course_id     BIGINT UNSIGNED NOT NULL,
    plan_semester TINYINT UNSIGNED NOT NULL DEFAULT 1,
    is_required   TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (program_id, course_id),
    FOREIGN KEY (program_id) REFERENCES programs(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Faculty
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS faculty (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       BIGINT UNSIGNED NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    employee_no   VARCHAR(30) NULL UNIQUE,
    first_name    VARCHAR(80) NOT NULL,
    last_name     VARCHAR(80) NOT NULL,
    email         VARCHAR(255) NOT NULL UNIQUE,
    rank          ENUM('professor','associate_professor','assistant_professor','senior_lecturer','lecturer','instructor','adjunct','emeritus','ta') NOT NULL DEFAULT 'lecturer',
    contract_type ENUM('full_time','part_time','adjunct','visiting','ta') NOT NULL DEFAULT 'full_time',
    status        ENUM('active','sabbatical','leave','retired','resigned') NOT NULL DEFAULT 'active',
    max_credit_hours   DECIMAL(4,1) NOT NULL DEFAULT 12.0,
    min_credit_hours   DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    max_contact_hours  DECIMAL(4,1) NOT NULL DEFAULT 15.0,
    research_release_hours DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    admin_release_hours    DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    qualifications  JSON NULL,                      -- degrees, certifications
    expertise       JSON NULL,                      -- ["machine learning","databases"]
    research_interests JSON NULL,
    preferences     JSON NULL,                      -- {"preferred_days":["Mon","Wed"],"avoid_early":true}
    hired_at      DATE NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    INDEX idx_faculty_dept (department_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Weekly availability windows
CREATE TABLE IF NOT EXISTS faculty_availability (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id BIGINT UNSIGNED NOT NULL,
    day        ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    preference ENUM('available','preferred','unavailable') NOT NULL DEFAULT 'available',
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    INDEX idx_avail (faculty_id, day)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Which courses a faculty member is qualified / preferred to teach
CREATE TABLE IF NOT EXISTS faculty_course_qualifications (
    faculty_id BIGINT UNSIGNED NOT NULL,
    course_id  BIGINT UNSIGNED NOT NULL,
    level      ENUM('qualified','preferred','expert') NOT NULL DEFAULT 'qualified',
    last_taught_term_id BIGINT UNSIGNED NULL,
    times_taught SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (faculty_id, course_id),
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Non-teaching workload: committees, advising, coordination, chairing, releases
CREATE TABLE IF NOT EXISTS workload_activities (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    faculty_id  BIGINT UNSIGNED NOT NULL,
    term_id     BIGINT UNSIGNED NOT NULL,
    type        ENUM('research_release','admin_duty','committee','advising','coordination','chair','sabbatical','leave','other') NOT NULL,
    description VARCHAR(255) NOT NULL,
    credit_hour_equivalent DECIMAL(4,1) NOT NULL DEFAULT 0.0,
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
    INDEX idx_workload (faculty_id, term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Rooms & resources
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rooms (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    building_id BIGINT UNSIGNED NULL,               -- NULL for virtual/online rooms
    code        VARCHAR(30) NOT NULL UNIQUE,
    name        VARCHAR(150) NOT NULL,
    type        ENUM('classroom','laboratory','auditorium','seminar','online','hybrid') NOT NULL DEFAULT 'classroom',
    capacity    SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    equipment   JSON NULL,                          -- ["projector","whiteboard","lab-pcs"]
    accessibility JSON NULL,                        -- ["wheelchair","hearing-loop"]
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_closures (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    room_id   BIGINT UNSIGNED NOT NULL,
    term_id   BIGINT UNSIGNED NULL,
    start_date DATE NOT NULL,
    end_date   DATE NOT NULL,
    reason     VARCHAR(255) NULL,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Sections (the scheduled unit) & meetings
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS sections (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id     BIGINT UNSIGNED NOT NULL,
    term_id       BIGINT UNSIGNED NOT NULL,
    campus_id     BIGINT UNSIGNED NULL,
    section_no    VARCHAR(10) NOT NULL DEFAULT '01',
    faculty_id    BIGINT UNSIGNED NULL,
    delivery_mode ENUM('on_campus','online_sync','online_async','hybrid') NOT NULL DEFAULT 'on_campus',
    capacity      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    enrolled      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    waitlisted    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    status        ENUM('draft','scheduled','approved','published','cancelled') NOT NULL DEFAULT 'draft',
    notes         VARCHAR(500) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_section (course_id, term_id, section_no),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
    FOREIGN KEY (campus_id) REFERENCES campuses(id) ON DELETE SET NULL,
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE SET NULL,
    INDEX idx_sections_term (term_id, status),
    INDEX idx_sections_faculty (faculty_id, term_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- A section can meet multiple times per week (lecture + lab, etc.)
CREATE TABLE IF NOT EXISTS section_meetings (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    section_id BIGINT UNSIGNED NOT NULL,
    room_id    BIGINT UNSIGNED NULL,
    day        ENUM('Mon','Tue','Wed','Thu','Fri','Sat','Sun') NOT NULL,
    start_time TIME NOT NULL,
    end_time   TIME NOT NULL,
    kind       ENUM('lecture','lab','seminar','exam','online') NOT NULL DEFAULT 'lecture',
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (room_id) REFERENCES rooms(id) ON DELETE SET NULL,
    INDEX idx_meeting_room (room_id, day, start_time),
    INDEX idx_meeting_section (section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Enrollment & demand
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS enrollment_history (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NOT NULL,
    term_id   BIGINT UNSIGNED NOT NULL,
    enrolled  INT UNSIGNED NOT NULL DEFAULT 0,
    waitlisted INT UNSIGNED NOT NULL DEFAULT 0,
    sections_offered SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    UNIQUE KEY uq_enrollment (course_id, term_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS enrollment_forecasts (
    id        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    course_id BIGINT UNSIGNED NOT NULL,
    term_id   BIGINT UNSIGNED NOT NULL,
    predicted_enrollment INT UNSIGNED NOT NULL,
    method    VARCHAR(50) NOT NULL DEFAULT 'trend', -- trend | ai | manual
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_forecast (course_id, term_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Policy / rules engine
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS workload_policies (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NULL,             -- NULL = institution-wide
    contract_type ENUM('full_time','part_time','adjunct','visiting','ta','any') NOT NULL DEFAULT 'any',
    name          VARCHAR(150) NOT NULL,
    rule_type     ENUM('max_credit_hours','min_credit_hours','max_contact_hours','max_sections','max_preps','max_overload_hours','adjunct_max_credit_hours','max_consecutive_hours','accreditation','union') NOT NULL,
    rule_value    DECIMAL(6,1) NOT NULL,
    severity      ENUM('error','warning','info') NOT NULL DEFAULT 'error',
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Conflicts (detected), AI recommendations & chat
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS schedule_conflicts (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id     BIGINT UNSIGNED NOT NULL,
    type        ENUM('faculty_time','room_time','room_capacity','room_equipment','faculty_unavailable','duplicate_assignment','pathway','workload','requisite','other') NOT NULL,
    severity    ENUM('error','warning','info') NOT NULL DEFAULT 'error',
    section_id  BIGINT UNSIGNED NULL,
    conflicting_section_id BIGINT UNSIGNED NULL,
    description VARCHAR(500) NOT NULL,
    suggestion  VARCHAR(500) NULL,
    status      ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
    detected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
    FOREIGN KEY (section_id) REFERENCES sections(id) ON DELETE CASCADE,
    FOREIGN KEY (conflicting_section_id) REFERENCES sections(id) ON DELETE CASCADE,
    INDEX idx_conflicts (term_id, status, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_recommendations (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id     BIGINT UNSIGNED NULL,
    category    ENUM('instructor','room','workload','section','enrollment','staffing','conflict_resolution','other') NOT NULL,
    title       VARCHAR(200) NOT NULL,
    detail      TEXT NOT NULL,
    payload     JSON NULL,                          -- machine-actionable suggestion
    status      ENUM('open','applied','dismissed') NOT NULL DEFAULT 'open',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ai_chat_messages (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    session_id CHAR(36) NOT NULL,
    role       ENUM('user','assistant','system','tool') NOT NULL,
    content    MEDIUMTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_chat (user_id, session_id, id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Scenario planning
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS scenarios (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id     BIGINT UNSIGNED NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    parameters  JSON NOT NULL,                      -- {"enrollment_delta_pct":20,"remove_faculty":[3],"close_rooms":[7]}
    result      JSON NULL,                          -- generated schedule summary & metrics
    status      ENUM('draft','simulated','applied','archived') NOT NULL DEFAULT 'draft',
    created_by  BIGINT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Workflow & approvals
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS approval_workflows (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    term_id       BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NOT NULL,
    current_step  ENUM('draft','ai_validation','chair_review','dean_review','registrar_approval','academic_affairs','published') NOT NULL DEFAULT 'draft',
    status        ENUM('in_progress','approved','rejected','published') NOT NULL DEFAULT 'in_progress',
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workflow (term_id, department_id),
    FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE,
    FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS approval_actions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id BIGINT UNSIGNED NOT NULL,
    step        VARCHAR(50) NOT NULL,
    action      ENUM('submitted','approved','rejected','commented','published') NOT NULL,
    user_id     BIGINT UNSIGNED NULL,
    comment     VARCHAR(1000) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (workflow_id) REFERENCES approval_workflows(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------------------------------------------------------------------
-- Notifications & scheduled reports
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    type       VARCHAR(60) NOT NULL,                -- assignment, schedule_change, conflict, approval, workload, room_change
    title      VARCHAR(200) NOT NULL,
    body       TEXT NULL,
    channels   SET('inapp','email','sms','teams','slack') NOT NULL DEFAULT 'inapp',
    read_at    DATETIME NULL,
    sent_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notif (user_id, read_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS scheduled_reports (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(150) NOT NULL,
    report_key VARCHAR(80) NOT NULL,                -- conflicts | workload | utilization | executive | forecast | changes
    frequency  ENUM('daily','weekly','monthly') NOT NULL,
    format     ENUM('pdf','xlsx','csv') NOT NULL DEFAULT 'pdf',
    delivery   SET('email','teams','download') NOT NULL DEFAULT 'download',
    recipients JSON NULL,
    filters    JSON NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    next_run_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS generated_reports (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    scheduled_report_id BIGINT UNSIGNED NULL,
    name        VARCHAR(200) NOT NULL,
    format      VARCHAR(10) NOT NULL,
    file_path   VARCHAR(255) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (scheduled_report_id) REFERENCES scheduled_reports(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Custom report builder definitions
CREATE TABLE IF NOT EXISTS custom_reports (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    BIGINT UNSIGNED NOT NULL,
    name       VARCHAR(150) NOT NULL,
    definition JSON NOT NULL,                       -- {source, columns, filters, group_by, aggregates, chart}
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Bulk import tracking (validation, preview, rollback)
CREATE TABLE IF NOT EXISTS import_batches (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      BIGINT UNSIGNED NOT NULL,
    entity_type  VARCHAR(50) NOT NULL,              -- faculty | courses | rooms | schedules | enrollment
    file_name    VARCHAR(255) NOT NULL,
    total_rows   INT UNSIGNED NOT NULL DEFAULT 0,
    valid_rows   INT UNSIGNED NOT NULL DEFAULT 0,
    error_rows   INT UNSIGNED NOT NULL DEFAULT 0,
    errors       JSON NULL,
    status       ENUM('validated','committed','rolled_back','failed') NOT NULL DEFAULT 'validated',
    created_ids  JSON NULL,                         -- for rollback
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
