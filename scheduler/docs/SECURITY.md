# Security Architecture & Compliance

## Multi-tenancy & isolation

The platform is multi-tenant: each institution is a row in `tenants`, and every root
entity (users, campuses, colleges, departments, terms, courses, programs, faculty, rooms,
sections, meeting patterns, policies, imports, scheduled reports, audit logs) carries a
`tenant_id` with per-tenant unique keys (two institutions can both have a `CSC101`).

Isolation is enforced server-side on every path:

- The tenant context (`App\Core\Tenancy`) derives **only from the authenticated user** —
  never from client input, hostnames or request parameters.
- Every list/aggregate query filters by `tenant_id`; every id taken from a URL or request
  body is checked with `Tenancy::assertOwns(table, id)` before use. Cross-tenant ids
  return **404** (indistinguishable from non-existent) and the attempt is recorded in the
  audit log as `cross_tenant_denied`.
- The AI assistant, scheduling engine, analytics, exports and imports all operate inside
  the tenant context; generated sections, imported rows and reports are stamped with the
  tenant id explicitly (never relying on column defaults).
- CLI jobs (scheduled reports) iterate tenants explicitly via `Tenancy::actAs()`, which is
  restricted to `php-cli`.
- SAML just-in-time provisioning maps users to a tenant by email domain
  (`tenants.domain`); unknown domains are refused.

## Authentication

- **Local accounts**: bcrypt (cost 12) password hashes; sessions are HTTP-only, SameSite=Lax,
  Secure when served over HTTPS; session id regenerated on login (fixation protection).
- **API tokens**: 32-byte random tokens issued at `/api/v1/auth/login`, stored as SHA-256
  hashes only, with expiry; sent via `Authorization: Bearer`.
- **MFA (TOTP)** — built in, dependency-free (`src/Services/TotpService.php`, RFC 6238,
  SHA-1/6-digit/30 s, compatible with Google/Microsoft Authenticator, Authy, 1Password):
  - Self-service enrollment at `/security`: QR provisioning, code confirmation before
    activation, eight one-time recovery codes (bcrypt-hashed at rest, shown once).
  - Login: password → `/mfa` challenge (web) or `otp` field on `POST /api/v1/auth/login`
    (the API replies `401 {"details":{"code":"mfa_required"}}` when a code is needed).
  - Replay protection: the last accepted TOTP time step is persisted
    (`users.mfa_last_counter`) and codes at or before it are rejected; ±1 step drift window.
  - Disabling MFA requires password re-confirmation; enable/disable/failed attempts and
    recovery-code use are audit-logged.
- **SAML 2.0 SSO** — built in, dependency-free SP (`src/Services/SamlService.php`):
  - SP-initiated HTTP-Redirect AuthnRequest, HTTP-POST assertion consumption, SP metadata
    at `/auth/saml/metadata`; tested patterns match Azure AD/Entra ID, Google Workspace,
    Okta and ADFS defaults.
  - Validation: XML-DSig (RSA-SHA256/384/512/SHA-1, exclusive C14N) of response and/or
    assertion against the configured IdP certificate, reference-digest check, reference-URI
    scope check, issuer, audience restriction, NotBefore/NotOnOrAfter (±120 s skew),
    recipient and `InResponseTo` correlation; DTDs rejected (XXE hardening); encrypted
    assertions are rejected with a clear error (disable encryption at the IdP or install
    `onelogin/php-saml` for that case).
  - Optional just-in-time provisioning (`SAML_AUTO_PROVISION`) with a configurable default
    role; unknown users are otherwise refused with a clear message.
  - Configure via `.env`: `SSO_SAML_ENABLED`, `SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`,
    `SAML_IDP_X509_CERT` (PEM or bare base64). For high-assurance deployments or exotic IdP
    features, `onelogin/php-saml` can be swapped in behind the same interface.
- **OAuth (Google/Microsoft) & LDAP**: `users.sso_provider`/`sso_subject` and `.env` keys
  are provisioned; wire an OAuth client (`league/oauth2-client`) or LDAP bind as needed.

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

`audit_logs` records every state-changing action with tenant, user, entity, old/new values
(JSON), IP address and user agent. Recorded events include:

- authentication: `login` (with method: password / password+mfa / saml), `login_failed`,
  `logout`, `mfa_enabled`, `mfa_disabled`, `mfa_failed`, `mfa_recovery_code_used`
- scheduling: section create/update/cancel, meeting moves (drag-and-drop),
  `generate_schedule`, `detect_conflicts`, scenario simulate/apply
- governance: workflow advance/reject/publish, workload activity changes
- data: `export` (report name, format, row count), `import_commit`, `import_rollback`
- security: `cross_tenant_denied` access attempts

Administrators view the trail at `/audit` (filter by action/entity) or
`GET /api/v1/audit-logs` — both strictly tenant-scoped behind the `admin.audit`
permission. Approval workflows additionally keep a per-step trail in `approval_actions`.
Audit writes never block the main flow.

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
