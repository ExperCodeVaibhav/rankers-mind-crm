# Rankers Mind CRM

[![Quality checks](https://github.com/ExperCodeVaibhav/rankers-mind-crm/actions/workflows/ci.yml/badge.svg)](https://github.com/ExperCodeVaibhav/rankers-mind-crm/actions/workflows/ci.yml)

A database-driven CRM for Rankers Mind covering sales, follow-ups, clients, projects, tasks, staff, photo/geolocation attendance, leave, payroll, invoicing, support tickets, notifications, portals, reports, audit logs and administration.

The project uses a custom PHP MVC architecture, PDO prepared statements and MariaDB/MySQL. It does not require Node.js or a JavaScript build step.

Start with [architecture](docs/architecture.md), [testing](docs/testing.md),
[contributing](CONTRIBUTING.md), [security](SECURITY.md) and [change history](CHANGELOG.md).
The [quality report](docs/quality-report.md) records the external score, actual CI
results and the scorer's PHP/GitHub Actions detection limitations.
The source remains proprietary as declared in `composer.json`; publication does not change its license.

## Docker setup

Docker Engine and Docker Compose v2 can run Apache/PHP and MariaDB together.
Copy `.env.example` to `.env.docker` and set unique `APP_KEY`, `DB_PASSWORD`,
`INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` values. Set
`APP_URL=http://localhost:8080`; the initial password must have at least 12 characters.

```sh
docker compose --env-file .env.docker up --build -d --wait
docker compose --env-file .env.docker exec --user www-data app php install.php
```

Open `http://localhost:8080`. Named volumes preserve the database and private files.
`docker compose --env-file .env.docker down` stops the stack without deleting them.
The database has no published port. Apache serves only `public/` and the app binds
to loopback for local use. A production reverse proxy must provide HTTPS; configure
`APP_URL` and `SESSION_SECURE=true` accordingly. Installation/migrations remain an
explicit command so container restarts do not change the database unexpectedly.

`GET /health` returns HTTP 200 with `{"status":"ok"}` when the database responds,
or 503 with `{"status":"unavailable"}`. It does not expose credentials or errors.
This tests connectivity, not schema installation or every business workflow.

## Runtime requirements

- Windows with XAMPP, Apache `mod_rewrite`, PHP and MySQL/MariaDB
- PHP 8.0 or newer (the local XAMPP PHP 8.0.30 runtime is verified)
- MySQL 8 or MariaDB 10.4+
- PHP extensions: `curl`, `fileinfo`, `gd`, `mbstring`, `openssl`, `pdo_mysql`, `session`
- Composer 2 (the verified `tools/composer.phar` is included for local setup)
- A current Chrome, Edge, Firefox or Safari browser

Runtime libraries include Bootstrap 5.3.8 (self-hosted), Dompdf, PHPMailer, Chart.js, FullCalendar, Font Awesome and Inter.

## XAMPP local instance

Place the checkout, or create your local directory link, at:

```text
C:\xampp\htdocs\rankers-mind-crm
```

After following the installation steps below, the application is available at:

```text
http://localhost/rankers-mind-crm/public/
```

Start Apache and MySQL from the XAMPP Control Panel before opening the URL.

## Fresh XAMPP installation

1. Copy this project to `C:\xampp\htdocs\rankers-mind-crm`.
2. Start Apache and MySQL in XAMPP.
3. Copy `.env.example` to `.env`, set the application URL and database credentials, and provide a unique `INITIAL_ADMIN_EMAIL` and `INITIAL_ADMIN_PASSWORD` (minimum 12 characters).
4. Install PHP dependencies:

   ```powershell
   cd C:\xampp\htdocs\rankers-mind-crm
   C:\xampp\php\php.exe tools\composer.phar install --no-dev --optimize-autoloader
   ```

5. Create the database, load system defaults and create the first Super Admin with the idempotent CLI installer:

   ```powershell
   C:\xampp\php\php.exe install.php
   ```

6. Open `http://localhost/rankers-mind-crm/public/`.

The root `.htaccess` redirects only the project root to `public/` and denies web access to `.env`, Composer metadata, SQL, source code, vendor code and private storage. The `public/` directory is the production document root.

## Manual database import

If the CLI installer cannot be used for the schema import, create a UTF-8 database in phpMyAdmin named `rankers_mind_crm`, then import in this order:

1. `database/schema.sql`
2. `database/seed.sql`

From a terminal, the equivalent commands are:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE IF NOT EXISTS rankers_mind_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
C:\xampp\mysql\bin\mysql.exe -u root rankers_mind_crm -e "source C:/xampp/htdocs/rankers-mind-crm/database/schema.sql"
C:\xampp\mysql\bin\mysql.exe -u root rankers_mind_crm -e "source C:/xampp/htdocs/rankers-mind-crm/database/seed.sql"
```

`database/seed.sql` contains only roles, permissions and required system defaults; it does not create login accounts or mock business records. After a manual import, run `php install.php` once with the `INITIAL_ADMIN_*` values configured so the first Super Admin is created securely and all migrations are applied.

## Environment configuration

The important `.env` values are:

```dotenv
APP_ENV=development
APP_DEBUG=true
APP_URL=http://localhost/rankers-mind-crm/public
APP_TIMEZONE=Asia/Kolkata
APP_KEY=replace-with-a-long-random-secret

DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=rankers_mind_crm
DB_USERNAME=root
DB_PASSWORD=
```

Generate a production `APP_KEY` with:

```powershell
C:\xampp\php\php.exe -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
```

The application uses Asia/Kolkata application time and server-generated attendance timestamps. Keep the web server and database time zones synchronized in production.

## Initial administrator

No development credentials are displayed on the sign-in page or stored in the production seed. On an empty database, `install.php` creates the first Super Admin from `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, `INITIAL_ADMIN_PASSWORD` and optional `INITIAL_ADMIN_PHONE` in `.env`. The password is stored only as a `password_hash()` value and must be changed after the first sign-in.

For an older database that still contains the former development seed, the installer rotates the legacy administrator to the configured production identity before removing the remaining mock users and business records.

## Completed modules

- Secure login, logout, remember-me, password reset, first-login change, throttling and session expiry
- Editable roles and permission matrix for Owner, executive, sales, HR, accounts, project, staff and client access
- Live executive dashboard with linked metrics, operational lists and Chart.js reporting
- Leads with scoring, classifications, duplicate detection, bulk assignment/stage/classification actions, pipeline board, calendar, CSV import/export, tags, secure attachments, activity history, follow-ups and transactional client conversion
- Follow-ups with due/overdue status, recurrence data, reminders, calendar and notifications
- Clients with an assigned project manager and support staff, admin-managed separate login IDs, hashed temporary passwords, password resets, account enable/disable controls, contacts/documents schema, financial terms, source lead link and protected one-client ownership
- Projects with teams, roles, milestones, internal/client-visible updates, secure shared/private files, board/calendar and profitability-ready data
- Tasks with prominent manual admin creation, standalone or project-linked work, multiple assignees, comments, daily updates, checklists, time logs, secure files, board/calendar, progress, approval protection and staff scoping
- Staff directory, login creation, hierarchy, departments, designations, shifts, restricted banking/KYC data and encrypted Aadhaar storage
- Live photo/geolocation check-in and checkout using server time, geofence accuracy/distance, duplicate punch prevention and protected photos
- Leave, salary advance and attendance correction requests
- Attendance-based payroll generation, configurable late deductions, advances, reconciliation, approval workflow and salary-slip PDF
- Quotations/estimates with line calculations, acceptance flow, PDF and transactional invoice conversion
- Tax/proforma/credit-note-capable invoicing, line calculations, GST, discounts, charges, partial payments, pending balances, print/PDF, payment history and protected receipt PDFs
- Responsive client portal with isolated projects, milestones, client-visible updates, team roles, protected files, document upload, readable invoice views/PDFs, receipts, company profile and client-scoped ticket conversations
- Staff portal for attendance, assigned work, deadlines, salary estimate, leave, requests and notifications
- Support tickets with automatic client-team assignment, project-manager/support-staff CRM alerts, SLA due time, internal notes, client-visible replies, resolution and reopen support
- In-app notification bell, unread counts, linked records, mark-read operations, opt-in browser alerts and reminder cron
- Reports centre with date/staff/client/project/status filters, dashboards, CSV export, printable reports and Dompdf output
- Global search, quick-create menu, saved filters, favourites, recent records, self-service portal profiles, settings, numbering, office/geofence rules, audit/activity logs, soft delete and database backups

## Attendance camera and location

1. Sign in with a staff account and open **Punch Attendance**.
2. Allow camera and location access when the browser asks.
3. Capture a live image, wait for the displayed geolocation accuracy/distance and submit the punch.
4. Check-out repeats the same validation.

Camera and geolocation APIs work on `localhost`. A deployed site must use HTTPS; browsers normally deny these APIs on insecure non-localhost origins. The displayed accuracy is the browser-reported accuracy, not a claim of exact GPS precision. Office coordinates, geofence radius, shift times, grace period and photo requirements are editable in Settings.

Attendance photos are compressed in the browser and validated again on the server for MIME type and size. They are served only through an authenticated, permission-checked controller.

## Reminder scheduler

Due and overdue reminders are calculated during normal use, so the CRM remains useful without the scheduler. For proactive generation, run:

```powershell
C:\xampp\php\php.exe C:\xampp\htdocs\rankers-mind-crm\cron\reminders.php
```

For Windows Task Scheduler:

1. Open **Task Scheduler** and choose **Create Task**.
2. Trigger it every 5 or 10 minutes.
3. Use `C:\xampp\php\php.exe` as the program.
4. Use `C:\xampp\htdocs\rankers-mind-crm\cron\reminders.php` as the argument.
5. Set **Start in** to `C:\xampp\htdocs\rankers-mind-crm`.
6. Run it once manually and confirm the task returns exit code `0`.

Email and WhatsApp settings are intentionally provider-neutral. PHPMailer is installed, but no fake SMTP or WhatsApp credentials are embedded.

## Backup and restore

An authorized administrator can create a SQL backup from **Settings > Database backups**. Files are written to `storage/backups` and backup metadata is recorded in `database_backups`.

To make a manual backup:

```powershell
C:\xampp\mysql\bin\mysqldump.exe -u root --single-transaction --routines --triggers rankers_mind_crm > C:\xampp\htdocs\rankers-mind-crm\storage\backups\rankers_mind_crm_manual.sql
```

To restore into an empty database:

```powershell
C:\xampp\mysql\bin\mysql.exe -u root -e "CREATE DATABASE rankers_mind_crm CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
C:\xampp\mysql\bin\mysql.exe -u root rankers_mind_crm -e "source C:/xampp/htdocs/rankers-mind-crm/storage/backups/rankers_mind_crm_manual.sql"
```

Always test a restore on a separate database before relying on a backup. Keep off-server encrypted copies and define a retention policy.

## Automated verification

Install development dependencies and run checks that need no database or `.env`:

```sh
php tools/composer.phar install
php tools/composer.phar check
php tools/composer.phar audit --locked
```

These commands enforce PHP syntax, PHP_CodeSniffer correctness rules, PHPStan level 1
on application logic, and PHPUnit regression tests (validation, environment handling,
routing, health checks and PDF rendering). Templates are syntax-checked but excluded
from PHPStan because their variables are injected by the view renderer.

GitHub Actions runs the checks on PHP 8.0, 8.3 and 8.4, scans Git history with Gitleaks,
and builds a disposable Apache/MariaDB stack for HTTP and leave-authorization checks.
PHP 8.0 is checked for compatibility; use a supported PHP release for deployment.

The older acceptance scripts below require a **disposable database ending in `_test`**
and `APP_ENV=testing` in the process environment. They must never target a live CRM.
Except for `leave-authorization.php`, they also require the former demo fixture data,
which the current production installer deliberately does not create. See
[testing details](docs/testing.md) before running them.

```powershell
$files = rg --files app routes public tests database -g '*.php'
foreach ($file in $files) { C:\xampp\php\php.exe -l $file }
C:\xampp\php\php.exe tests\smoke.php
C:\xampp\php\php.exe tests\ticket-assignment.php
C:\xampp\php\php.exe tests\ticket-resolution.php
C:\xampp\php\php.exe tests\workflow.php
```

`tests/smoke.php` verifies live authentication, CSRF rejection, seeded RBAC, financial reconciliation, invoice/estimate/report/receipt PDFs, assignment retention and staff/client IDOR restrictions. `tests/ticket-assignment.php` creates and cleans a real client ticket, then verifies automatic staff ownership, project-manager/support-staff notification feeds and recipient access. `tests/ticket-resolution.php` verifies both admin and concerned-project-staff resolution, the client Yes/No prompt, No-to-reopen notifications, and client-only final closure. `tests/workflow.php` creates and cleans a full acceptance path covering staff creation and self-service, admin-created client credentials, hashed passwords and Client role assignment, cross-client project/ticket denial, manual admin tasks, attendance, lead activity/tags/bulk actions/follow-up/conversion, projects/tasks/checklists/time logs/upload security, favourites/saved views, estimate conversion, invoices/partial payment, tickets and payroll.

## Directory structure

```text
rankers-mind-crm/
|-- app/
|   |-- Config/          application and entity configuration
|   |-- Controllers/     HTTP workflow controllers
|   |-- Core/            router, controller, database and validation
|   |-- Helpers/         global view/application helpers
|   |-- Middleware/      authentication, guest, CSRF and permission checks
|   |-- Models/          model base
|   |-- Services/        domain transactions, uploads, audit, payroll and billing
|   `-- Views/           layouts, modules, portals and PDF documents
|-- cron/reminders.php
|-- database/
|   |-- migrations/      idempotent upgrades for existing installations
|   |-- schema.sql
|   `-- seed.sql
|-- public/
|   |-- assets/css, js, images and vendor
|   |-- assets/images/favicon.svg and site.webmanifest
|   |-- uploads/
|   |-- .htaccess
|   `-- index.php
|-- routes/web.php
|-- storage/
|   |-- attendance_photos, backups, invoices, logs
|   |-- private_uploads and salary_slips
|-- tests/smoke.php, workflow.php and browser-visual.mjs
|-- tools/composer.phar
|-- .env.example
|-- composer.json
|-- install.php
`-- README.md
```

## Database tables

The normalized utf8mb4 schema contains these 66 tables:

```text
activity_logs, announcements, attendance, attendance_adjustments,
attendance_correction_requests, attendance_rules, audit_logs, branches,
client_contacts, client_documents, clients, database_backups, departments,
designations, estimate_items, estimates, favourites, file_uploads, holidays,
invoice_items, invoices, lead_activities, lead_attachments, lead_followups,
lead_tags, leads, leave_balances, leave_requests, leave_types, notifications,
office_locations, password_resets, payment_receipts, payments,
payroll_adjustments, payroll_entries, payroll_periods, permissions,
project_files, project_members, project_milestones, project_updates, projects,
recent_records, reminders, role_permissions, roles, salary_advances,
salary_slips, saved_filters, settings, shifts, staff_documents, staff_profiles,
tags, task_assignees, task_checklists, task_comments, task_time_logs,
task_updates, tasks, ticket_messages, tickets, user_roles, user_sessions, users
```

Foreign keys, unique business codes, DECIMAL financial fields, useful status/date/contact indexes, audit history and soft-delete columns are included. Multiple assignees and tags use junction tables rather than comma-separated relational IDs.

## Rankers Mind logo

The supplied Rankers Mind logo is installed at:

```text
public/assets/images/rankers-mind-logo.png
```

The original 1080 x 1920 PNG is preserved. Its centred 210 x 100 logo region is presented through a responsive CSS viewport so the artwork is not stretched or redrawn. If the file is removed, the interface automatically returns to the compact `RM` fallback mark.

## Production security checklist

- Keep the initial administrator credentials private and rotate them whenever access may have been exposed.
- Use PHP 8.2+ with current security updates.
- Set `APP_ENV=production`, `APP_DEBUG=false`, a new `APP_KEY` and HTTPS `APP_URL`.
- Use a least-privilege database user with a strong password; never deploy the root DB account.
- Enable HTTPS, secure cookies, HSTS and an appropriate Content Security Policy.
- Keep `.env`, SQL, logs, private uploads, attendance photos and backups outside the public document root or verify HTTP 403 responses.
- Confirm `storage` permissions and disable directory indexing.
- Configure SMTP/notification providers with secrets stored outside source control.
- Review role permissions and test staff/client ownership boundaries after every change.
- Configure backup retention, encrypted off-server copies and restore drills.
- Rotate logs and monitor authentication throttling, audit logs and web-server errors.
- Keep PHP, Composer dependencies and browser libraries patched.
- Run all database migrations before deployment; the cleanup migration removes only identifiers from the former development seed.

## Known deployment dependencies

- The bundled logo can be replaced at `public/assets/images/rankers-mind-logo.png`.
- SMTP and WhatsApp delivery require real provider credentials; the in-app notification system works without them.
- Chart.js, FullCalendar, Font Awesome and Inter currently load from established CDNs. Self-host them before deploying to an offline or strict-CSP environment. Bootstrap is already self-hosted.
- Browser notifications still require the user to grant permission; in-app due/overdue reminders remain available regardless.
