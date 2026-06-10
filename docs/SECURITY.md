# Security Architecture & Compliance

## Authentication

- **Local accounts**: bcrypt (cost 12) password hashes; sessions are HTTP-only, SameSite=Lax,
  Secure when served over HTTPS; session id regenerated on login (fixation protection).
- **API tokens**: 32-byte random tokens issued at `/api/v1/auth/login`, stored as SHA-256
  hashes only, with expiry; sent via `Authorization: Bearer`.
- **MFA**: `users.mfa_secret` / `mfa_enabled` columns are provisioned for TOTP; enable by
  wiring a TOTP library (e.g. `spomky-labs/otphp`) into the login flow.
- **SSO-ready**: `users.sso_provider` / `sso_subject` support Google, Microsoft, SAML and
  LDAP/Active Directory identities; configure providers in `.env`
  (`SSO_GOOGLE_*`, `SSO_MICROSOFT_*`, `SSO_SAML_ENABLED`, `LDAP_*`). Production SAML/OIDC
  should use a maintained library (e.g. `onelogin/php-saml`, `league/oauth2-client`).

## Authorization (RBAC)

Roles → permissions → routes. Every route declares a required permission; `user_roles`
optionally scopes a role to a department (department-level permissions).

| Role | Typical permissions |
|---|---|
| admin | everything, incl. `admin.users`, `admin.policies`, `admin.audit` |
| registrar / dean | view + `schedule.approve`, `schedule.publish`, reports |
| chair / scheduler | `schedule.edit`, `schedule.generate`, faculty/workload edit, imports, scenarios |
| faculty / viewer | read-only schedule, workload, rooms, reports |

Workflow steps enforce step-specific permissions (publishing requires `schedule.publish`).

## Audit & activity tracking

`audit_logs` records every state-changing action (create/update/cancel/approve/export/import/
login) with user, entity, old/new values (JSON), IP and user agent. Approval workflows keep
their own per-step trail in `approval_actions`. Audit writes never block the main flow.

## Application hardening

- 100% prepared statements (PDO, emulation off) — no string-interpolated SQL with user input.
- Output escaping helper `e()` (htmlspecialchars) in all server templates; client-side
  rendering escapes via `textContent`/manual escapers.
- Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`.
- `.htaccess` denies direct access to `.env`, `.sql`, `.md`, `.json`, `.lock`; directory
  listing disabled; only `public/` is web-exposed.
- Bulk-import table names are whitelisted; rollback deletes only ids the batch created.
- LLM safety: the model receives only aggregated, permission-filtered context — never raw
  database access or credentials; replies are rendered as escaped text.

## Data protection (GDPR / FERPA)

- **Minimization**: no student-identifying records are stored — enrollment is aggregate
  (counts per course/term), which keeps the system outside most FERPA disclosure scenarios.
- **Encryption in transit**: enforce HTTPS (free Let's Encrypt on Hostinger).
- **Encryption at rest**: enable MySQL tablespace encryption / encrypted backups on the host.
- **Right of access/erasure**: faculty/user personal data is consolidated in `users` and
  `faculty`; deleting a user cascades or nullifies dependents via FKs, while audit history
  retains action records (legitimate-interest basis — document in your retention policy).
- **Access logging**: exports and views of personal data are auditable via `audit_logs`.
- Configure data-retention jobs (cron) to purge stale chat logs and notifications.

## Operational guidance

- Change the seeded admin password immediately; set a strong 64-char `APP_KEY`.
- Use a least-privilege MySQL account (no `GRANT`, no other schemas).
- Keep `APP_DEBUG=false` in production (errors are logged, not displayed).
- Rotate API tokens; tokens expire after 30 days by default.
