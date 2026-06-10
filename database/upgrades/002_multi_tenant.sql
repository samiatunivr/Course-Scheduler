-- Upgrade for installs created before multi-tenancy.
-- Fresh installs get all of this from schema.sql. On existing databases
-- run the statements in order; all current data is assigned to tenant 1.

CREATE TABLE IF NOT EXISTS tenants (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code       VARCHAR(30) NOT NULL UNIQUE,
    name       VARCHAR(200) NOT NULL,
    domain     VARCHAR(255) NULL UNIQUE,
    timezone   VARCHAR(64) NOT NULL DEFAULT 'UTC',
    logo_url   VARCHAR(255) NULL,
    settings   JSON NULL,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO tenants (id, code, name) VALUES (1, 'DEFAULT', 'Default Institution')
ON DUPLICATE KEY UPDATE name = name;

-- Add tenant_id (existing rows default to tenant 1)
ALTER TABLE users             ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD INDEX idx_users_tenant (tenant_id), ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE campuses          ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE colleges          ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE departments       ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE academic_years    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE terms             ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE meeting_patterns  ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE courses           ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE programs          ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE faculty           ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE rooms             ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE sections          ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE workload_policies ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id, ADD FOREIGN KEY (tenant_id) REFERENCES tenants(id);
ALTER TABLE scheduled_reports ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE import_batches    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE audit_logs        ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id, ADD INDEX idx_audit_tenant (tenant_id, created_at);
UPDATE audit_logs SET tenant_id = 1 WHERE tenant_id IS NULL;

-- Convert global unique keys to per-tenant composites
ALTER TABLE campuses         DROP INDEX code, ADD UNIQUE KEY uq_campus (tenant_id, code);
ALTER TABLE colleges         DROP INDEX code, ADD UNIQUE KEY uq_college (tenant_id, code);
ALTER TABLE departments      DROP INDEX code, ADD UNIQUE KEY uq_department (tenant_id, code);
ALTER TABLE academic_years   DROP INDEX name, ADD UNIQUE KEY uq_academic_year (tenant_id, name);
ALTER TABLE terms            DROP INDEX code, ADD UNIQUE KEY uq_term (tenant_id, code);
ALTER TABLE meeting_patterns DROP INDEX code, ADD UNIQUE KEY uq_pattern (tenant_id, code);
ALTER TABLE courses          DROP INDEX code, ADD UNIQUE KEY uq_course (tenant_id, code);
ALTER TABLE programs         DROP INDEX code, ADD UNIQUE KEY uq_program (tenant_id, code);
ALTER TABLE rooms            DROP INDEX code, ADD UNIQUE KEY uq_room (tenant_id, code);
ALTER TABLE faculty          DROP INDEX email, ADD UNIQUE KEY uq_faculty_email (tenant_id, email);
ALTER TABLE faculty          DROP INDEX employee_no, ADD UNIQUE KEY uq_faculty_empno (tenant_id, employee_no);
