# LAP Crash Analysis Portal

Staff portal for the Road Commission for Oakland County. Each RCOC project has one analysis, with an auditable **Entered → Started → Ready for Review → Complete** workflow. The needed-by date is the deadline for the signed, completed analysis.

## Local preview

Requirements: PHP 8.3+ with PDO, `pdo_sqlite` for local trials, `pdo_mysql` for hosting, `mbstring`, OpenSSL, Argon2id password support, and Composer. Production uses MySQL/MariaDB and SMTP. No Node build or frontend service is required.

The current workspace has an ignored `.env` and a fictional demo database. Start the preview from the repository root:

```powershell
php -S 127.0.0.1:8090 -t public
```

Open **http://127.0.0.1:8090**. Demo usernames are `bates`, `jeremy`, `peters`, and `mayich`; all use **`Demo-Only-2026!`**. These accounts and sample project names are for local demonstration only. Email delivery is simulated and the preview displays a banner saying so.

To set up a fresh checkout:

```powershell
composer install
```

Create an ignored `.env` with:

```ini
APP_ENV=local
APP_URL=http://127.0.0.1:8090
APP_TIMEZONE=America/New_York
APP_DSN="sqlite:storage/lap-demo.sqlite"
MAIL_TRANSPORT=log
```

Then run `php bin/console.php init --demo` once. Initialization rejects databases already containing portal tables; it does not replace existing projects. To start another trial, change `APP_DSN` to a new local filename. Demo accounts have already completed their password changes; all newly created accounts require a change at first sign-in.

## Using the portal

| Role | Access |
|---|---|
| Design | View all projects; submit requests; edit own active requests |
| Analyst | View all projects; enter/update folders; mark Ready for Review or Complete |
| Administrator | All Design and Analyst actions; edit any active request; manage accounts and notifications |

- **Andrew Peters / Jacob Mayich:** Design. **Jeremy Miller:** Analyst. **Andrew Bates:** Administrator.
- Saving the first Windows folder path starts the analysis. Paths such as `G:\A TOC` are copied and pasted into Windows File Explorer; the web server never opens them.
- Mark Ready for Review when the analysis is prepared for Bates. Review comments are handled verbally while the record remains at this stage.
- Jeremy or Bates confirms Complete after external review/signing. Complete is final and the project becomes read-only.
- All milestones notify the four configured stakeholders and the requester, with duplicate addresses removed. Ordinary detail/folder edits appear in history without milestone emails.
- Enter all four stakeholder email addresses under **Notifications** before the first request. Missing addresses prevent a milestone save, so a request cannot silently omit a required stakeholder.
- Failed mail does **not** undo a saved request. Administrators can retry it in Notifications. This page reports SMTP acceptance, not proof of inbox delivery.
- Users can be deactivated, not deleted. Account role/deactivation changes and password resets revoke existing sessions. Reset links expire after one hour and are single-use.
- New temporary passwords appear once in administration. Share them directly with the account owner; they are not emailed or written to application logs.

Project search covers names and both project identifiers. The dashboard initially shows active work in needed-by order; status and construction-year filters also expose completed work. Details and milestones use optimistic versions to reject stale edits. Every saved change records its actor and UTC timestamp; the interface displays Eastern time.

## Production deployment

An upload-ready archive can be built in PowerShell with `.\bin\package.ps1`. It creates `dist/lap-crash-analysis.zip`, including Composer dependencies and excluding local configuration, databases, logs, and tests. Extract it to the private application directory on your PHP host and follow the setup below.

Use a **separate, empty database** and a dedicated HTTPS hostname or subdirectory on your existing PHP host. A hosting administrator must provide the actual database connection, SMTP credentials, site URL, and stakeholder addresses. No live host or email account is configured in this repository.

1. Select PHP 8.3 or later. Verify `PDO`, `pdo_mysql`, `mbstring`, OpenSSL, and `argon2id` in `password_algos()`; enable Composer or build dependencies locally for upload.
2. Upload application source plus `vendor/`, or run `composer install --no-dev --prefer-dist --optimize-autoloader` on the host. Include `composer.lock`. **Do not upload the local `.env`, demo database, tests, or test-results.**
3. Point the document root to **`public/`**. Keep `app/`, `bin/`, `database/`, `.env`, `vendor/`, and `storage/` outside the web root. Only the PHP service account should have access to private configuration; give it write access to `storage/` and its private PHP session directory.
4. Copy `.env.example` to `.env` on the host and configure `APP_ENV=production`, the HTTPS `APP_URL`, a dedicated MySQL/MariaDB account and database, and SMTP settings. Process environment variables override `.env`. Keep PHP error display disabled. If HTTPS terminates at a proxy, production session cookies remain Secure; configure the host/proxy to enforce HTTPS.
5. Run `php bin/console.php init` against the empty dedicated database. Alternatively import `database/schema.sql` once in phpMyAdmin. This is initial schema version 1; there is no existing-data migration.
6. Run `php bin/console.php create-admin bates your-work-email "Andrew Bates"`. The command creates only the first administrator and displays a temporary password once. Sign in and change it.
7. In **People**, create Jeremy Miller as Analyst, and Andrew Peters and Jacob Mayich as Design, using verified work email addresses. In **Notifications**, enter the four stakeholder addresses. These notification addresses are maintained separately from account email addresses.
8. Run `php bin/console.php check`. Configure a cron job every five minutes to run `/absolute/path/to/php /absolute/path/to/portal/bin/console.php mail:work` with the repository as the working directory. Milestone requests attempt immediate delivery; cron handles pending messages left after an interrupted request. Failed messages remain visible for manual retry.
9. Run `php bin/console.php mail:test controlled-test-recipient@example.org` using an address you control. Confirm inbox arrival and the configured sender. This command intentionally sends one test message when SMTP is configured.
10. Verify HTTPS, unauthenticated redirects, first-login changes, all role permissions, a complete test workflow, and protected project links on the actual host. Confirm backups and perform a restore test before entering operational data.

SMTP supports authenticated TLS (`SMTP_ENCRYPTION=tls`, normally port 587) or implicit TLS (`ssl`, normally port 465). Password reset emails use `APP_URL`; verify that it is correct before launch. For a host subdirectory, include it in `APP_URL` and map that URL to `public/`.

The notification queue atomically saves each milestone and its recipients. A worker claims a message before sending it, so parallel requests cannot normally send it twice. If a process stops after SMTP accepts a message but before the sent status is saved, delivery is uncertain; after ten minutes the administrator may explicitly retry. The interface warns that such a retry can duplicate the email. Sent messages are never automatically retried. Recipient addresses are snapshots from the event; updating stakeholder settings affects future events.

Back up the MySQL/MariaDB database on the hosting provider's schedule and protect backups as staff data. Keep private configuration and recovery instructions in your normal credential-management process. Files in `storage/` are runtime logs, not project attachments. No files or signed documents are uploaded to this application.

## Verification

Backend tests create a unique disposable SQLite database in ignored `test-results/`:

```powershell
php tests/run.php
php tests/initialization.php
```

The same suite supports an **empty dedicated test** MySQL/MariaDB database. It rejects an already initialized database, and never clears one:

```powershell
$env:TEST_MYSQL_DSN = 'mysql:host=127.0.0.1;port=3306;dbname=lap_test;charset=utf8mb4'
$env:TEST_MYSQL_USER = 'lap_test'
$env:TEST_MYSQL_PASSWORD = 'your-test-password'
php tests/run.php
```

Browser tests create their own database, start and stop a local server, and exercise Design, Analyst, and Administrator sessions. Python and Playwright are development tools only:

```powershell
py -3.13 -m pip install playwright
py -3.13 -m playwright install chromium
py -3.13 tests/browser.py
```

If an appropriate Chromium is already installed, set `PLAYWRIGHT_CHROMIUM` to its executable instead. Screenshots and browser logs go to `test-results/`. See [verification notes](docs/verification.md) for the checks completed in this workspace and launch checks that still require hosting access.

## Structure

- `app/`: authentication, validation, transactions, project lifecycle, and mail delivery.
- `public/`: server-rendered pages and locally served CSS/JavaScript.
- `templates/`: shared navigation and layout.
- `database/schema.sql`: production MySQL/MariaDB schema, also adapted for disposable SQLite trials.
- `bin/`: setup, fictional demo seed, configuration checks, and mail commands.
- `tests/`: domain/concurrency checks and browser workflow verification.

Version one has no uploads, comment threads, deadline reminder emails, external-agency accounts, reopening, or OneSpan integration.
