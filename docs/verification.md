# Verification

## Per-project recipients and requester filter — October 16, 2026

Milestones and two-week reminders now go to the requester and two individual project contact accounts, initially Jeremy Miller and Andrew Bates. The old stakeholder address form is removed. A requester filter combines with dashboard search, status, construction year, and pagination; project viewing permissions remain unchanged.

Verified locally on PHP **8.2.34**:

- **24 recipient/filter checks passed on SQLite and MariaDB 11.4.8**. They cover exact recipients for Peters/Mayich requests, reviewer/requester deduplication, exclusion of additional administrators/analysts, retired address-list independence, cancellation of obsolete queued recipients, current account emails, stable contact IDs, administrative permissions, migration preservation, invalid filters, inactive requesters, and pagination.
- The existing **68 backend, 53 invitation/account, and 35 reminder checks passed on both databases**, with notification expectations updated to the new recipient rule. The initialization-preservation check passed.
- **61 Chromium browser checks passed**, including requester filter keyboard access, combining and resetting filters, persistence through pagination, unchanged visibility of other requesters' projects, removal of the address form, contact selection/CSRF checks, reminder recipients, and mobile layouts. No PHP runtime or JavaScript errors were reported.
- All **32 PHP files passed syntax checks**. Composer validation passed with only the existing missing-license metadata warning. Mobile dashboard and People screenshots were visually reviewed.

No live accounts, projects, or email were modified during verification. Run `migrate` after deployment, even if reminder tables were already installed, and verify Jeremy/Bates under **People → Project contacts**. Previously sent email cannot be recalled; pending and failed project messages are rechecked against the current recipients before delivery.

## Two-week status reminders — October 16, 2026

The existing `mail:work` cron command now queues status reminders at 14-day anniversaries of submission until Complete. A repeatable migration adds two tables for reminder periods and their notification records. It does not rewrite existing application records.

Verified locally on PHP **8.2.34**:

- **35 reminder checks passed on SQLite and MariaDB 11.4.8**, using separate disposable databases. Coverage includes adding tables to a populated original schema, repeated migration, delivery before migration, day-14/day-28 boundaries, Eastern daylight saving changes, all active statuses, overdue deadlines, recipient deduplication, HTML escaping, missing addresses, SMTP retry, transactional rollback, missed intervals, overlapping cron workers, and simultaneous completion.
- The existing **68 backend checks and 53 invitation/account checks passed on both databases**. The separate initialization-preservation check passed on SQLite.
- **52 Chromium browser checks passed**, including invocation of the actual cron command, reminder delivery through the simulated transport, cancellation on completion, project links in notification history, and removal of Retry for cancelled messages. Desktop/mobile checks passed without PHP runtime or JavaScript errors.
- All **30 PHP files passed syntax checks**. Composer configuration validation passed with only the existing missing-license metadata warning.

No production emails were sent or live database records modified during verification. SMTP failure and success were simulated. Reminders already in flight cannot be recalled; pending and failed reminders are cancelled when a project is completed. See the README for the required `migrate` deployment step and the existing cron command.

## Email sign-in update — October 16, 2026

Staff now sign in with their saved email address. Account forms no longer ask for a separate username, and welcome emails identify the sign-in email. Existing usernames and passwords continue to work. The original username column is retained for compatibility; no database migration is required.

Verified locally on PHP **8.2.34**:

- **53 invitation/account checks and 68 existing backend checks passed on both SQLite and MariaDB 11.4.8**, using new disposable databases. Added coverage includes email case/whitespace normalization, plus addresses, duplicate and invalid addresses, addresses longer than 100 characters, existing account access, email edits, revoked sessions, deactivation, and a shared attempt limit for email/legacy-username sign-in.
- **49 Chromium browser checks passed**, including email sign-in, account creation without a username, welcome messages, password setup, account email changes, legacy username compatibility, and desktop/mobile layouts. No PHP runtime or JavaScript errors were reported.
- Email-only and legacy initial-administrator command formats were checked against separate disposable databases. Neither sent email.
- All **27 PHP files passed syntax checks**. Desktop and mobile sign-in screenshots were visually reviewed.

No production accounts, passwords, or database records were changed during these checks. Deploy the updated source and test sign-in with the administrator's saved account email and current password.

## Welcome email update — October 16, 2026

The welcome email update was verified locally using PHP **8.2.34**:

- **38 invitation checks passed on SQLite and on MariaDB 11.4.8**, each using a separate disposable database. They cover administrator permissions, atomic account/token/email creation, recipient selection, portal-purpose copy, escaped names, 24-hour setup links, one-hour recovery links, expiry, single use, password policy, SMTP failure/retry, email changes, deactivation, resending, rollback, and simultaneous password setup.
- The existing **68 backend checks passed on both databases**, and the initialization-preservation check passed.
- **47 Chromium browser checks passed**, including welcome delivery through the simulated transport, resending, invalidation of older links, password setup, CSRF rejection, first-login recovery, workflow permissions, and desktop/mobile layouts. No PHP runtime errors or browser errors were reported.
- Login pages at 320, 390, and 1440px and the password setup page at 390px fit without horizontal overflow. Desktop/mobile login and mobile password setup screenshots were visually reviewed.
- All **27 application and test PHP files passed syntax checks**. Composer configuration validation passed with only the existing missing-license metadata warning. Dependency versions did not change.

No production accounts were created or emailed during this update. Email tests used a local simulated transport, including forced delivery failures. The user previously confirmed receipt of a production SMTP test message; the new welcome flow still needs a controlled mailbox check after deploying this update.

The update reuses the existing tables and requires **no database migration or initialization**. Existing users are not emailed automatically. Deployment and resend instructions are in the README.

## Initial implementation — October 9, 2026

The initial portal was implemented and verified locally. No production deployment or actual staff email delivery was performed during that verification.

## Completed checks

- **68 backend checks passed on SQLite 3.49.2.** The suite used a newly generated disposable database, separate from the local preview.
- **The same 68 checks passed on MariaDB 11.4.8 / InnoDB**, using PDO's MySQL driver and a dedicated empty test database. This covered the production schema, real row locks, concurrent PHP processes, notification transactions, and recovery after an uncertain SMTP delivery receipt.
- A separate initialization check verified that an unrelated existing table is preserved and portal setup refuses the occupied database.
- **34 Chromium browser checks passed** across Design, Analyst, and Administrator sessions. They covered form submission, conditional inputs, markup escaping, forbidden pages and actions, CSRF rejection, folder copying, each workflow milestone, first-login enforcement, account creation, and saved records in a fresh browser session.
- Dashboard, request, and detail pages fit **320, 390, 768, and 1024px** viewports without page overflow. Administration pages also fit a 390px viewport. Desktop and mobile screenshots were visually reviewed. A narrow-screen deadline overflow was fixed during verification.
- All application PHP files passed syntax checks. Composer reported no dependency security advisories at verification time.
- The local preview database contains **six fictional projects and four demo accounts**. All email is simulated. No operational project records or actual stakeholder email addresses were imported.

Backend scenarios include mandatory password changes, reset token hashing/expiry/single use, revoked sessions, disabled accounts, role enforcement, conditional required fields, unique identifiers preserving leading zeros, duplicate submission replay, stale edits, exact folder paths, milestone order, closed-record locking, before/after history, overdue boundaries, recipient deduplication, SMTP failure, safe retry, atomic rollback, and concurrent submissions/transitions.

The MariaDB ZIP runtime came from the [official MariaDB archive](https://archive.mariadb.org/mariadb-11.4.8/winx64-packages/) and its SHA-256 matched the published checksum. It ran as a temporary localhost-only process, not a Windows service. Existing databases were not modified. Initial PHP execution used 8.5.0. This does not constitute testing every supported PHP/MySQL version.

## PHP 8.2 compatibility

The hosting account uses PHP 8.2 globally. The portal does not require PHP 8.3 language features or dependencies, so its Composer minimum was changed to `^8.2`. Composer's platform target is `8.2.0` to preserve compatibility when dependencies are resolved on newer development machines. Locked package versions did not change.

An isolated official PHP 8.2.34 Windows runtime was downloaded from [PHP's download page](https://www.php.net/downloads.php?os=windows&version=8.2) and verified against its published SHA-256. It did not replace the installed development PHP or change hosting settings. On this runtime:

- All **68 backend checks passed on SQLite**, and the same **68 passed on MariaDB 11.4.8** using a new empty test database.
- The separate initialization-preservation check passed.
- All **34 browser checks passed**, including first-login changes, workflow actions, folder copying, and desktop/mobile layouts, with no PHP runtime errors.
- All **25 application and test PHP files passed syntax checks**.
- Composer installation, platform requirement checks, and configuration validation passed. Validation reports only the existing missing-license metadata warning. Dependency audit reported no security advisories.

PHP 8.2 support still requires the extensions and Argon2id listed in the README. This verifies application compatibility locally, not the production server's configuration. The deployment archive was rebuilt with the updated Composer metadata and autoloader.

## Launch checks requiring the hosting environment

- Set verified account and stakeholder addresses, production `APP_URL`, database credentials, and SMTP settings.
- Verify the host's PHP extensions, Argon2id support, database permissions, private document-root layout, and HTTPS behavior.
- Test real SMTP delivery to a controlled recipient and confirm inbox receipt. Local log delivery and SMTP acceptance are not evidence of inbox delivery.
- Configure pending-mail processing, log monitoring, database backups, and a successful restore test.
- Repeat a controlled end-to-end workflow on the deployed hostname, including login, first-login change, role restrictions, and protected email links.

The in-app browser surface was unavailable in this session. Automated browser verification used an isolated Chromium instance and local test server. Screenshots and machine-readable results are in ignored `test-results/`; they are not included in the deployment package.

## Reproduce

See the [README verification commands](../README.md#verification). Tests reject a populated test database rather than deleting or resetting it. A new empty database is required for each MariaDB run.
