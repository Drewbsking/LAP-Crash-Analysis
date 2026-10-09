# Verification — October 9, 2026

The portal was implemented and verified locally. No production deployment or actual staff email delivery was performed.

## Completed checks

- **68 backend checks passed on SQLite 3.49.2.** The suite used a newly generated disposable database, separate from the local preview.
- **The same 68 checks passed on MariaDB 11.4.8 / InnoDB**, using PDO's MySQL driver and a dedicated empty test database. This covered the production schema, real row locks, concurrent PHP processes, notification transactions, and recovery after an uncertain SMTP delivery receipt.
- A separate initialization check verified that an unrelated existing table is preserved and portal setup refuses the occupied database.
- **34 Chromium browser checks passed** across Design, Analyst, and Administrator sessions. They covered form submission, conditional inputs, markup escaping, forbidden pages and actions, CSRF rejection, folder copying, each workflow milestone, first-login enforcement, account creation, and saved records in a fresh browser session.
- Dashboard, request, and detail pages fit **320, 390, 768, and 1024px** viewports without page overflow. Administration pages also fit a 390px viewport. Desktop and mobile screenshots were visually reviewed. A narrow-screen deadline overflow was fixed during verification.
- All application PHP files passed syntax checks. Composer reported no dependency security advisories at verification time.
- The local preview database contains **six fictional projects and four demo accounts**. All email is simulated. No operational project records or actual stakeholder email addresses were imported.

Backend scenarios include mandatory password changes, reset token hashing/expiry/single use, revoked sessions, disabled accounts, role enforcement, conditional required fields, unique identifiers preserving leading zeros, duplicate submission replay, stale edits, exact folder paths, milestone order, closed-record locking, before/after history, overdue boundaries, recipient deduplication, SMTP failure, safe retry, atomic rollback, and concurrent submissions/transitions.

The MariaDB ZIP runtime came from the [official MariaDB archive](https://archive.mariadb.org/mariadb-11.4.8/winx64-packages/) and its SHA-256 matched the published checksum. It ran as a temporary localhost-only process, not a Windows service. Existing databases were not modified. PHP execution used 8.5.0; the application's minimum is PHP 8.3. This does not constitute testing every supported PHP/MySQL version.

## Launch checks requiring the hosting environment

- Set verified account and stakeholder addresses, production `APP_URL`, database credentials, and SMTP settings.
- Verify the host's PHP extensions, Argon2id support, database permissions, private document-root layout, and HTTPS behavior.
- Test real SMTP delivery to a controlled recipient and confirm inbox receipt. Local log delivery and SMTP acceptance are not evidence of inbox delivery.
- Configure pending-mail processing, log monitoring, database backups, and a successful restore test.
- Repeat a controlled end-to-end workflow on the deployed hostname, including login, first-login change, role restrictions, and protected email links.

The in-app browser surface was unavailable in this session. Automated browser verification used an isolated Chromium instance and local test server. Screenshots and machine-readable results are in ignored `test-results/`; they are not included in the deployment package.

## Reproduce

See the [README verification commands](../README.md#verification). Tests reject a populated test database rather than deleting or resetting it. A new empty database is required for each MariaDB run.
