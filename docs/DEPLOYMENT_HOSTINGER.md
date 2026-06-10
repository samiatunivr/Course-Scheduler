# Deployment Guide — Hostinger Premium Hosting

The application is designed for Hostinger Premium shared hosting: PHP 8.4, MySQL,
Apache + `.htaccess`, cron jobs — no Docker, no long-running processes.

## 1. Prepare the database

1. hPanel → **Databases → MySQL Databases**.
2. Create database (e.g. `u123456_scheduler`), user, and a strong password; note the values.
3. Open **phpMyAdmin** for the new database and import, in order:
   - `database/schema.sql`
   - `database/seed.sql` (optional demo data — recommended for first run)

## 2. Upload the application

Recommended layout (keeps code outside the web root):

```
/home/u123456/
├── scheduler/                 ← upload the whole repository here
│   ├── bin/  config/  database/  docs/  routes/  src/  storage/
│   ├── public/                ← only this folder is web-served
│   └── .env
└── domains/yourdomain.edu/public_html  → point to scheduler/public (see below)
```

1. hPanel → **Files → File Manager** (or SFTP) → upload the repo to `~/scheduler`.
2. Point the site at `public/`. Two options:
   - **Subdomain document root**: hPanel → Domains → your domain → change document root to
     `scheduler/public` (available on Premium for subdomains), or
   - **public_html passthrough**: copy the contents of `public/` into `public_html/` and edit
     `public_html/index.php` so `$root = '/home/u123456/scheduler';`.
3. Ensure `storage/reports` is writable (755/775).

## 3. Configure the environment

Create `~/scheduler/.env` (copy from `.env.example`):

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.edu
APP_KEY=<64 random characters>
DB_HOST=localhost
DB_DATABASE=u123456_scheduler
DB_USERNAME=u123456_scheduser
DB_PASSWORD=<password>
AI_API_KEY=<OpenAI or compatible key>     # optional but recommended
AI_MODEL=gpt-4o
MAIL_FROM=scheduler@yourdomain.edu
```

hPanel → **Advanced → PHP Configuration**: select **PHP 8.4**, confirm extensions
`pdo_mysql`, `mbstring`, `curl`, `json` are enabled (default on Hostinger).

## 4. Optional Composer packages

SSH (hPanel → Advanced → SSH Access):

```bash
cd ~/scheduler
composer install --no-dev          # autoloader
composer require phpoffice/phpspreadsheet dompdf/dompdf   # native .xlsx / .pdf exports
```

The app runs fully without these; exports fall back to Excel-compatible and print-ready
formats.

## 5. HTTPS

hPanel → **Security → SSL** → install the free Let's Encrypt certificate and enable
**Force HTTPS**.

## 6. Cron jobs (scheduled reports & forecasts)

hPanel → **Advanced → Cron Jobs**:

```
# Daily 06:00 — scheduled reports (conflicts, changes; weekly/monthly are self-gated)
0 6 * * *  php /home/u123456/scheduler/bin/scheduled_reports.php
```

Insert rows into `scheduled_reports` (or via the Reports page/SQL) to define what runs
daily/weekly/monthly, the format, and email/Teams recipients.

## 7. First login & hardening

1. Visit `https://yourdomain.edu` → login `admin@example.edu` / `Admin@12345`.
2. **Immediately** change the admin email/password (`users` table or profile).
3. Create real users and assign roles (`user_roles`), scoping chairs/schedulers to their
   departments.
4. Review `workload_policies` and adjust to your institutional/union/accreditation rules.
5. Bulk-import your faculty, courses, rooms, availability and enrollment history
   (Imports page — validation, preview, rollback supported).

## 8. Updating

Upload changed files, then re-run any new SQL from `database/` via phpMyAdmin. The schema
uses `CREATE TABLE IF NOT EXISTS`, so re-importing `schema.sql` is safe for new tables.

## Troubleshooting

| Symptom | Fix |
|---|---|
| 503 "Database connection failed" | Check `.env` DB credentials; DB host on Hostinger is usually `localhost` |
| 404 on all routes | `.htaccess` not uploaded or `mod_rewrite` path wrong — confirm `public/.htaccess` exists |
| API returns 401 with valid token | Ensure the Authorization-header rewrite block in `public/.htaccess` is present |
| Exports download as `.xls` not `.xlsx` | Install `phpoffice/phpspreadsheet` (step 4) |
| Emails not delivered | Configure SMTP_* in `.env` or use Hostinger's default mail with a domain-matching MAIL_FROM |
