<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;
use App\{Auth, DB, Mail, Projects, Reminders, ProjectContacts};

$command = $argv[1] ?? 'help';
switch ($command) {
    case 'init':
        if (in_array('--demo', $argv, true) && env('APP_ENV', 'production') === 'production') throw new RuntimeException('Demo initialization requires APP_ENV=local or test.');
        DB::install();
        echo "Database initialized.\n";
        if (in_array('--demo', $argv, true)) {
            require __DIR__ . '/demo.php';
            seed_demo();
            echo "Fictional demo projects added. Local demo accounts: bates@example.test, jeremy@example.test, peters@example.test, mayich@example.test\nDemo password for all four: Demo-Only-2026!\n";
        } else echo "Next: php bin/console.php create-admin your-work-email 'Andrew Bates'\n";
        break;
    case 'create-admin':
        $email = $argv[2] ?? '';
        $name = $argv[3] ?? 'Andrew Bates';
        $input = [];
        // Accept the previous USER EMAIL NAME command for older setup guides.
        if (!str_contains($email, '@') && isset($argv[3]) && filter_var($argv[3], FILTER_VALIDATE_EMAIL)) {
            $input['username'] = $email;
            $email = $argv[3];
            $name = $argv[4] ?? 'Andrew Bates';
        }
        $password = Auth::temporaryPassword();
        Auth::createUser($input + ['email' => $email, 'full_name' => $name, 'role' => 'admin'], $password);
        echo "Administrator created. Sign in with your email address.\nTemporary password (shown once): $password\nChange it at first sign-in.\n";
        break;
    case 'migrate':
        DB::migrate();
        echo "Database updates are ready, including reminders and project contacts. Existing records were preserved.\n";
        break;
    case 'mail:work':
        try {
            if (Reminders::installed()) {
                $reminders = Reminders::queueDue();
                echo 'Status reminders queued: ' . $reminders['queued'] . '; recipient configuration failures: ' . $reminders['failed'] . ".\n";
            } else {
                echo "Status reminders need setup: run php bin/console.php migrate. Existing email delivery remains active.\n";
            }
        } finally {
            Mail::deliverPending(100);
        }
        echo "Pending email batch processed. Review failed deliveries in Notifications.\n";
        break;
    case 'mail:test':
        $email = $argv[2] ?? '';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Usage: php bin/console.php mail:test controlled-recipient@example.org');
        Mail::queue(null, $email, 'Deployment test recipient', 'LAP Crash Analysis delivery test', '<p>The LAP Crash Analysis portal is testing email delivery. No project data is included.</p>', 'The LAP Crash Analysis portal is testing email delivery. No project data is included.');
        $id = (int)DB::conn()->lastInsertId();
        if (!Mail::deliver($id)) throw new RuntimeException('Email test failed. Check SMTP configuration.');
        echo env('MAIL_TRANSPORT') === 'log' ? "Local-only delivery simulated. No real email sent.\n" : "SMTP accepted the test message. Verify arrival in the recipient inbox.\n";
        break;
    case 'check':
        $version = DB::conn()->getAttribute(PDO::ATTR_SERVER_VERSION);
        echo 'PHP: ' . PHP_VERSION . "\nDatabase: " . DB::conn()->getAttribute(PDO::ATTR_DRIVER_NAME) . ' ' . $version . "\n";
        foreach (['users','projects','project_events','mail_outbox','password_resets','rate_limits'] as $table) {
            echo $table . ': ' . DB::query("SELECT COUNT(*) FROM $table")->fetchColumn() . " rows\n";
        }
        $contactsReady = ProjectContacts::ready() ? 'ready (requester + two selected accounts)' : 'select the two project contacts in People';
        echo 'Project contact settings: ' . (ProjectContacts::installed() ? 'ready' : 'run php bin/console.php migrate') . "\n";
        echo 'Status reminders: ' . (Reminders::installed() ? 'ready (every 14 days until Complete)' : 'run php bin/console.php migrate') . "\n";
        echo "Project notifications: $contactsReady\nMail transport: " . env('MAIL_TRANSPORT', 'smtp') . "\n";
        echo 'HTTPS site URL: ' . (str_starts_with(env('APP_URL'), 'https://') ? 'configured' : 'not configured (local preview only)') . "\n";
        break;
    default:
        echo "LAP Crash Analysis commands:\n  init [--demo]                 Initialize an empty dedicated database\n  migrate                      Update an existing database without replacing records\n  create-admin EMAIL NAME       Create the first administrator\n  check                        Check database and configuration\n  mail:work                    Queue due status reminders and deliver pending email\n  mail:test EMAIL               Send one delivery test to a controlled address\n";
}
