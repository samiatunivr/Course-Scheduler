-- Demo / starter data for the University Course Scheduler
SET NAMES utf8mb4;

-- Tenants (institutions). All unprefixed inserts below rely on the
-- tenant_id DEFAULT 1 and belong to Demo University.
INSERT INTO tenants (id, code, name, domain) VALUES
(1, 'DEMO', 'Demo University', 'example.edu'),
(2, 'NSC', 'North State College', 'northstate.edu')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Roles & permissions ---------------------------------------------------
INSERT INTO roles (code, name, description) VALUES
('admin', 'System Administrator', 'Full access to all modules'),
('registrar', 'Registrar', 'University-wide schedule approval and publication'),
('dean', 'Dean', 'College-level review and approval'),
('chair', 'Department Chair', 'Department schedule and workload management'),
('scheduler', 'Department Scheduler', 'Builds department schedules'),
('faculty', 'Faculty', 'Views own schedule and workload'),
('viewer', 'Viewer', 'Read-only access')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO permissions (code, name) VALUES
('schedule.view', 'View schedules'),
('schedule.edit', 'Edit schedules'),
('schedule.generate', 'Run AI schedule generation'),
('schedule.approve', 'Approve schedules'),
('schedule.publish', 'Publish schedules'),
('faculty.view', 'View faculty profiles'),
('faculty.edit', 'Edit faculty profiles'),
('workload.view', 'View workload data'),
('workload.edit', 'Edit workload data'),
('rooms.view', 'View rooms'),
('rooms.edit', 'Edit rooms'),
('reports.view', 'View reports'),
('reports.export', 'Export reports'),
('imports.run', 'Run bulk imports'),
('scenarios.run', 'Run scenario simulations'),
('ai.chat', 'Use AI assistant'),
('admin.users', 'Manage users and roles'),
('admin.policies', 'Manage workload policies'),
('admin.audit', 'View audit logs')
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- admin: everything
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.code = 'admin';
-- registrar / dean: view + approve + publish + reports + ai
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('registrar','dean')
  AND p.code IN ('schedule.view','schedule.approve','schedule.publish','faculty.view','workload.view','rooms.view','reports.view','reports.export','ai.chat');
-- chair / scheduler: build schedules
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('chair','scheduler')
  AND p.code IN ('schedule.view','schedule.edit','schedule.generate','faculty.view','faculty.edit','workload.view','workload.edit','rooms.view','reports.view','reports.export','imports.run','scenarios.run','ai.chat');
-- faculty / viewer: read-only basics
INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r JOIN permissions p
WHERE r.code IN ('faculty','viewer')
  AND p.code IN ('schedule.view','workload.view','rooms.view','reports.view');

-- Default admin user (password: Admin@12345 — change immediately) -------
INSERT INTO users (email, name, password_hash) VALUES
('admin@example.edu', 'System Administrator',
 '$2y$12$wgUs8kn/vGUhXVcCGik4K.TvDTRqnypz3lN6mGqBwhzCDKNsLNSei')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO user_roles (user_id, role_id, department_id)
SELECT u.id, r.id, NULL FROM users u, roles r
WHERE u.email = 'admin@example.edu' AND r.code = 'admin';

-- Institutional structure -----------------------------------------------
INSERT INTO campuses (code, name, timezone) VALUES
('MAIN', 'Main Campus', 'UTC'),
('NORTH', 'North Campus', 'UTC'),
('ONLINE', 'Online Campus', 'UTC')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO buildings (campus_id, code, name)
SELECT c.id, b.code, b.name FROM campuses c
JOIN (SELECT 'SCI' code, 'Science Hall' name UNION ALL
      SELECT 'ENG', 'Engineering Building' UNION ALL
      SELECT 'LIB', 'Library & Learning Center') b
WHERE c.code = 'MAIN';

INSERT INTO colleges (code, name) VALUES
('COE', 'College of Engineering'),
('CAS', 'College of Arts & Sciences'),
('COB', 'College of Business')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO departments (college_id, code, name)
SELECT c.id, d.code, d.name FROM colleges c
JOIN (SELECT 'COE' col, 'CSC' code, 'Computer Science' name UNION ALL
      SELECT 'COE', 'EE',  'Electrical Engineering' UNION ALL
      SELECT 'CAS', 'MTH', 'Mathematics' UNION ALL
      SELECT 'COB', 'BUS', 'Business Administration') d ON d.col = c.code
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Academic terms ---------------------------------------------------------
INSERT INTO academic_years (name, start_date, end_date) VALUES
('2025-2026', '2025-08-15', '2026-06-15'),
('2026-2027', '2026-08-15', '2027-06-15')
ON DUPLICATE KEY UPDATE start_date = VALUES(start_date);

INSERT INTO terms (academic_year_id, code, name, type, start_date, end_date, status)
SELECT ay.id, t.code, t.name, t.type, t.start_date, t.end_date, t.status FROM academic_years ay
JOIN (SELECT '2025-2026' yr, 'FA2025' code, 'Fall 2025' name, 'semester' type, '2025-08-25' start_date, '2025-12-15' end_date, 'archived' status UNION ALL
      SELECT '2025-2026', 'SP2026', 'Spring 2026', 'semester', '2026-01-12', '2026-05-08', 'published' UNION ALL
      SELECT '2026-2027', 'FA2026', 'Fall 2026', 'semester', '2026-08-24', '2026-12-14', 'draft' UNION ALL
      SELECT '2026-2027', 'SU2027', 'Summer 2027', 'summer', '2027-06-01', '2027-07-30', 'planning') t ON t.yr = ay.name
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Meeting patterns ---------------------------------------------------------
INSERT INTO meeting_patterns (code, days, start_time, end_time, minutes_per_week) VALUES
('MWF-0900', 'Mon,Wed,Fri', '09:00', '09:50', 150),
('MWF-1000', 'Mon,Wed,Fri', '10:00', '10:50', 150),
('MWF-1100', 'Mon,Wed,Fri', '11:00', '11:50', 150),
('TR-0900',  'Tue,Thu', '09:00', '10:15', 150),
('TR-1030',  'Tue,Thu', '10:30', '11:45', 150),
('TR-1300',  'Tue,Thu', '13:00', '14:15', 150),
('MW-1400',  'Mon,Wed', '14:00', '15:15', 150),
('F-LAB-1300', 'Fri', '13:00', '15:50', 170)
ON DUPLICATE KEY UPDATE days = VALUES(days);

-- Rooms ---------------------------------------------------------------------
INSERT INTO rooms (building_id, code, name, type, capacity, equipment)
SELECT b.id, r.code, r.name, r.type, r.capacity, r.equipment FROM buildings b
JOIN (SELECT 'SCI' bld, 'SCI-101' code, 'Science 101' name, 'classroom' type, 40 capacity, '["projector","whiteboard"]' equipment UNION ALL
      SELECT 'SCI', 'SCI-205', 'Science 205', 'classroom', 35, '["projector"]' UNION ALL
      SELECT 'SCI', 'SCI-310', 'Computer Lab A', 'laboratory', 28, '["projector","lab-pcs"]' UNION ALL
      SELECT 'ENG', 'ENG-110', 'Engineering 110', 'classroom', 60, '["projector","whiteboard"]' UNION ALL
      SELECT 'ENG', 'ENG-AUD', 'Engineering Auditorium', 'auditorium', 180, '["projector","av-system"]' UNION ALL
      SELECT 'LIB', 'LIB-220', 'Library Seminar 220', 'seminar', 18, '["display"]') r ON r.bld = b.code
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity);

INSERT INTO rooms (building_id, code, name, type, capacity, equipment) VALUES
(NULL, 'ONL-01', 'Online Room 1', 'online', 200, '["lms","zoom"]'),
(NULL, 'ONL-02', 'Online Room 2', 'online', 200, '["lms","zoom"]')
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity);

-- Courses ---------------------------------------------------------------------
INSERT INTO courses (department_id, code, title, credit_hours, contact_hours, default_capacity, requires_lab, is_core)
SELECT d.id, c.code, c.title, c.ch, c.cth, c.cap, c.lab, c.core FROM departments d
JOIN (SELECT 'CSC' dep, 'CSC101' code, 'Introduction to Programming' title, 3.0 ch, 4.0 cth, 60 cap, 1 lab, 1 core UNION ALL
      SELECT 'CSC', 'CSC201', 'Data Structures', 3.0, 3.0, 40, 1, 1 UNION ALL
      SELECT 'CSC', 'CSC301', 'Database Systems', 3.0, 3.0, 35, 1, 0 UNION ALL
      SELECT 'CSC', 'CSC310', 'Operating Systems', 3.0, 3.0, 35, 0, 0 UNION ALL
      SELECT 'CSC', 'CSC401', 'Machine Learning', 3.0, 3.0, 30, 0, 0 UNION ALL
      SELECT 'CSC', 'CSC420', 'Artificial Intelligence', 3.0, 3.0, 30, 0, 0 UNION ALL
      SELECT 'MTH', 'MTH101', 'Calculus I', 4.0, 4.0, 80, 0, 1 UNION ALL
      SELECT 'MTH', 'MTH201', 'Linear Algebra', 3.0, 3.0, 50, 0, 1 UNION ALL
      SELECT 'EE',  'EE205',  'Circuits I', 3.0, 4.0, 40, 1, 1 UNION ALL
      SELECT 'BUS', 'BUS110', 'Principles of Management', 3.0, 3.0, 70, 0, 1) c ON c.dep = d.code
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT IGNORE INTO course_requisites (course_id, requisite_course_id, type)
SELECT a.id, b.id, 'prerequisite' FROM courses a, courses b
WHERE (a.code, b.code) IN (('CSC201','CSC101'),('CSC301','CSC201'),('CSC310','CSC201'),
                           ('CSC401','CSC201'),('CSC420','CSC201'),('MTH201','MTH101'));

-- Programs ---------------------------------------------------------------------
INSERT INTO programs (department_id, code, name)
SELECT d.id, 'BSCS', 'B.Sc. Computer Science' FROM departments d WHERE d.code = 'CSC'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO program_courses (program_id, course_id, plan_semester)
SELECT p.id, c.id, pc.sem FROM programs p
JOIN (SELECT 'CSC101' code, 1 sem UNION ALL SELECT 'MTH101', 1 UNION ALL
      SELECT 'CSC201', 2 UNION ALL SELECT 'MTH201', 2 UNION ALL
      SELECT 'CSC301', 3 UNION ALL SELECT 'CSC310', 3 UNION ALL
      SELECT 'CSC401', 4 UNION ALL SELECT 'CSC420', 4) pc
JOIN courses c ON c.code = pc.code
WHERE p.code = 'BSCS';

-- Faculty ---------------------------------------------------------------------
INSERT INTO faculty (department_id, first_name, last_name, email, rank, contract_type,
                     max_credit_hours, max_contact_hours, research_release_hours, expertise, preferences)
SELECT d.id, f.fn, f.ln, f.email, f.rank, f.ct, f.maxch, f.maxcth, f.rel, f.exp, f.pref FROM departments d
-- Note: term-specific releases/duties are tracked in workload_activities;
-- the release columns are for standing (every-term) releases only.
JOIN (SELECT 'CSC' dep, 'Alice' fn, 'Nguyen' ln, 'a.nguyen@example.edu' email, 'professor' rank, 'full_time' ct, 12.0 maxch, 15.0 maxcth, 0.0 rel, '["machine learning","artificial intelligence","data science"]' exp, '{"preferred_days":["Tue","Thu"],"avoid_early":true}' pref UNION ALL
      SELECT 'CSC', 'Omar', 'Haddad', 'o.haddad@example.edu', 'associate_professor', 'full_time', 12.0, 15.0, 0.0, '["databases","operating systems","distributed systems"]', '{"preferred_days":["Mon","Wed","Fri"]}' UNION ALL
      SELECT 'CSC', 'Maria', 'Rossi', 'm.rossi@example.edu', 'assistant_professor', 'full_time', 12.0, 15.0, 0.0, '["programming","software engineering","databases"]', '{}' UNION ALL
      SELECT 'CSC', 'James', 'Carter', 'j.carter@example.edu', 'adjunct', 'adjunct', 6.0, 8.0, 0.0, '["programming","web development"]', '{"preferred_days":["Tue","Thu"],"evening_only":true}' UNION ALL
      SELECT 'MTH', 'Lina', 'Khoury', 'l.khoury@example.edu', 'professor', 'full_time', 12.0, 15.0, 0.0, '["calculus","linear algebra"]', '{}' UNION ALL
      SELECT 'EE',  'Tomás', 'García', 't.garcia@example.edu', 'senior_lecturer', 'full_time', 12.0, 16.0, 0.0, '["circuits","electronics"]', '{}' UNION ALL
      SELECT 'BUS', 'Sara', 'Ali', 's.ali@example.edu', 'lecturer', 'full_time', 12.0, 15.0, 0.0, '["management","organizational behavior"]', '{}') f ON f.dep = d.code
ON DUPLICATE KEY UPDATE rank = VALUES(rank);

-- Qualifications
INSERT IGNORE INTO faculty_course_qualifications (faculty_id, course_id, level, times_taught)
SELECT f.id, c.id, q.level, q.n FROM faculty f
JOIN (SELECT 'a.nguyen@example.edu' email, 'CSC401' code, 'expert' level, 6 n UNION ALL
      SELECT 'a.nguyen@example.edu', 'CSC420', 'expert', 8 UNION ALL
      SELECT 'a.nguyen@example.edu', 'CSC201', 'qualified', 2 UNION ALL
      SELECT 'o.haddad@example.edu', 'CSC301', 'expert', 10 UNION ALL
      SELECT 'o.haddad@example.edu', 'CSC310', 'expert', 7 UNION ALL
      SELECT 'o.haddad@example.edu', 'CSC201', 'preferred', 4 UNION ALL
      SELECT 'm.rossi@example.edu', 'CSC101', 'expert', 5 UNION ALL
      SELECT 'm.rossi@example.edu', 'CSC201', 'preferred', 3 UNION ALL
      SELECT 'm.rossi@example.edu', 'CSC301', 'qualified', 1 UNION ALL
      SELECT 'j.carter@example.edu', 'CSC101', 'preferred', 4 UNION ALL
      SELECT 'l.khoury@example.edu', 'MTH101', 'expert', 12 UNION ALL
      SELECT 'l.khoury@example.edu', 'MTH201', 'expert', 9 UNION ALL
      SELECT 't.garcia@example.edu', 'EE205', 'expert', 11 UNION ALL
      SELECT 's.ali@example.edu', 'BUS110', 'expert', 10) q
JOIN courses c ON c.code = q.code
WHERE f.email = q.email;

-- Availability (defaults: weekdays 08:00-18:00; Carter evenings only)
INSERT INTO faculty_availability (faculty_id, day, start_time, end_time, preference)
SELECT f.id, d.day, IF(f.email='j.carter@example.edu','16:00','08:00'),
       IF(f.email='j.carter@example.edu','21:00','18:00'), 'available'
FROM faculty f
JOIN (SELECT 'Mon' day UNION ALL SELECT 'Tue' UNION ALL SELECT 'Wed' UNION ALL SELECT 'Thu' UNION ALL SELECT 'Fri') d
WHERE NOT EXISTS (SELECT 1 FROM faculty_availability fa WHERE fa.faculty_id = f.id);

-- Workload activities (Fall 2026)
INSERT INTO workload_activities (faculty_id, term_id, type, description, credit_hour_equivalent)
SELECT f.id, t.id, w.type, w.descr, w.che FROM faculty f
JOIN terms t ON t.code = 'FA2026'
JOIN (SELECT 'a.nguyen@example.edu' email, 'research_release' type, 'NSF Grant — AI for Education' descr, 3.0 che UNION ALL
      SELECT 'o.haddad@example.edu', 'coordination', 'CS Program Coordinator', 3.0 UNION ALL
      SELECT 'm.rossi@example.edu', 'advising', 'Undergraduate Advising (45 students)', 1.5 UNION ALL
      SELECT 'l.khoury@example.edu', 'committee', 'Curriculum Committee', 1.0) w ON w.email = f.email
WHERE NOT EXISTS (SELECT 1 FROM workload_activities wa WHERE wa.faculty_id = f.id AND wa.term_id = t.id);

-- Workload policies (idempotent: skipped when tenant 1 already has any) ----
INSERT INTO workload_policies (department_id, contract_type, name, rule_type, rule_value, severity)
SELECT * FROM (
    SELECT NULL dept, 'full_time' ct, 'Full-time max teaching load' name, 'max_credit_hours' rt, 12.0 rv, 'error' sev UNION ALL
    SELECT NULL, 'full_time', 'Full-time min teaching load', 'min_credit_hours', 6.0, 'warning' UNION ALL
    SELECT NULL, 'full_time', 'Max contact hours', 'max_contact_hours', 16.0, 'error' UNION ALL
    SELECT NULL, 'adjunct',   'Adjunct credit-hour cap', 'adjunct_max_credit_hours', 9.0, 'error' UNION ALL
    SELECT NULL, 'any',       'Max distinct course preps', 'max_preps', 3.0, 'warning' UNION ALL
    SELECT NULL, 'any',       'Max overload hours above contract', 'max_overload_hours', 3.0, 'warning' UNION ALL
    SELECT NULL, 'any',       'Max consecutive teaching hours', 'max_consecutive_hours', 4.0, 'warning'
) p
WHERE NOT EXISTS (SELECT 1 FROM workload_policies WHERE tenant_id = 1);

-- Enrollment history (for forecasting) -------------------------------------
INSERT INTO enrollment_history (course_id, term_id, enrolled, waitlisted, sections_offered)
SELECT c.id, t.id, e.enrolled, e.wl, e.secs FROM courses c
JOIN terms t ON t.code = 'FA2025'
JOIN (SELECT 'CSC101' code, 118 enrolled, 12 wl, 2 secs UNION ALL
      SELECT 'CSC201', 72, 6, 2 UNION ALL
      SELECT 'CSC301', 38, 4, 1 UNION ALL
      SELECT 'CSC310', 31, 0, 1 UNION ALL
      SELECT 'CSC401', 29, 8, 1 UNION ALL
      SELECT 'CSC420', 27, 5, 1 UNION ALL
      SELECT 'MTH101', 152, 10, 2 UNION ALL
      SELECT 'MTH201', 47, 0, 1 UNION ALL
      SELECT 'EE205', 36, 2, 1 UNION ALL
      SELECT 'BUS110', 66, 0, 1) e ON e.code = c.code
ON DUPLICATE KEY UPDATE enrolled = VALUES(enrolled);

-- ---------------------------------------------------------------------
-- Second tenant: North State College — demonstrates tenant isolation.
-- Codes like CSC / CSC101 / FA2026 intentionally duplicate tenant 1's:
-- uniqueness is per tenant.
-- ---------------------------------------------------------------------
INSERT INTO users (tenant_id, email, name, password_hash) VALUES
(2, 'admin@northstate.edu', 'NSC Administrator',
 '$2y$12$wgUs8kn/vGUhXVcCGik4K.TvDTRqnypz3lN6mGqBwhzCDKNsLNSei')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT IGNORE INTO user_roles (user_id, role_id, department_id)
SELECT u.id, r.id, NULL FROM users u, roles r
WHERE u.email = 'admin@northstate.edu' AND r.code = 'admin';

INSERT INTO departments (tenant_id, college_id, code, name)
VALUES (2, NULL, 'CSC', 'Computing & Data Science')
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO academic_years (tenant_id, name, start_date, end_date)
VALUES (2, '2026-2027', '2026-08-15', '2027-06-15')
ON DUPLICATE KEY UPDATE start_date = VALUES(start_date);

INSERT INTO terms (tenant_id, academic_year_id, code, name, type, start_date, end_date, status)
SELECT 2, ay.id, 'FA2026', 'Fall 2026', 'semester', '2026-08-24', '2026-12-14', 'draft'
FROM academic_years ay WHERE ay.tenant_id = 2 AND ay.name = '2026-2027'
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO meeting_patterns (tenant_id, code, days, start_time, end_time, minutes_per_week) VALUES
(2, 'MWF-0900', 'Mon,Wed,Fri', '09:00', '09:50', 150),
(2, 'TR-1030', 'Tue,Thu', '10:30', '11:45', 150)
ON DUPLICATE KEY UPDATE days = VALUES(days);

INSERT INTO rooms (tenant_id, building_id, code, name, type, capacity, equipment) VALUES
(2, NULL, 'NSC-101', 'North Hall 101', 'classroom', 45, '["projector"]')
ON DUPLICATE KEY UPDATE capacity = VALUES(capacity);

INSERT INTO courses (tenant_id, department_id, code, title, credit_hours, contact_hours, default_capacity)
SELECT 2, d.id, 'CSC101', 'Foundations of Computing', 3.0, 3.0, 40
FROM departments d WHERE d.tenant_id = 2 AND d.code = 'CSC'
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO faculty (tenant_id, department_id, first_name, last_name, email, rank, contract_type, max_credit_hours, max_contact_hours)
SELECT 2, d.id, 'Iris', 'Holm', 'i.holm@northstate.edu', 'lecturer', 'full_time', 12.0, 15.0
FROM departments d WHERE d.tenant_id = 2 AND d.code = 'CSC'
ON DUPLICATE KEY UPDATE rank = VALUES(rank);

INSERT IGNORE INTO faculty_course_qualifications (faculty_id, course_id, level, times_taught)
SELECT f.id, c.id, 'expert', 5 FROM faculty f
JOIN courses c ON c.tenant_id = 2 AND c.code = 'CSC101'
WHERE f.email = 'i.holm@northstate.edu';

INSERT INTO faculty_availability (faculty_id, day, start_time, end_time, preference)
SELECT f.id, d.day, '08:00', '18:00', 'available'
FROM faculty f
JOIN (SELECT 'Mon' day UNION ALL SELECT 'Tue' UNION ALL SELECT 'Wed' UNION ALL SELECT 'Thu' UNION ALL SELECT 'Fri') d
WHERE f.email = 'i.holm@northstate.edu'
  AND NOT EXISTS (SELECT 1 FROM faculty_availability fa WHERE fa.faculty_id = f.id);

INSERT INTO workload_policies (tenant_id, department_id, contract_type, name, rule_type, rule_value, severity)
SELECT 2, NULL, 'full_time', 'NSC full-time max teaching load', 'max_credit_hours', 12.0, 'error'
FROM dual
WHERE NOT EXISTS (SELECT 1 FROM workload_policies WHERE tenant_id = 2);
