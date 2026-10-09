<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit;
use App\{Auth, DB, Mail, Projects};

$command = $argv[1] ?? 'help';
switch ($command) {
    case 'init':
        if (in_array('--demo', $argv, true) && env('APP_ENV', 'production') === 'production') throw new RuntimeException('Demo initialization requires APP_ENV=local or test.');
        DB::install();
        echo "Database initialized.\n";
        if (in_array('--demo', $argv, true)) {
            require __DIR__ . '/demo.php';
            seed_demo();
            echo "Fictional demo projects added. Local demo accounts: bates, jeremy, peters, mayich\nDemo password for all four: Demo-Only-2026!\n";
        } else echo "Next: php bin/console.php create-admin bates your-work-email 'Andrew Bates'\n";
        break;
    case 'create-admin':
        $username = $argv[2] ?? '';
        $email = $argv[3] ?? '';
        $name = $argv[4] ?? 'Andrew Bates';
        $password = Auth::temporaryPassword();
        Auth::createUser(['username' => $username, 'email' => $email, 'full_name' => $name, 'role' => 'admin'], $password);
        echo "Administrator created. Temporary password (shown once): $password\nChange it at first sign-in.\n";
        break;
    case 'mail:work':
        Mail::deliverPending(100);
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
        foreach (['users','projects','project_events','mail_outbox','stakeholders','password_resets','rate_limits'] as $table) {
            echo $table . ': ' . DB::query("SELECT COUNT(*) FROM $table")->fetchColumn() . " rows\n";
        }
        $missing = DB::query("SELECT COUNT(*) FROM stakeholders WHERE email IS NULL OR email=''")->fetchColumn();
        echo "Stakeholder addresses missing: $missing\nMail transport: " . env('MAIL_TRANSPORT', 'smtp') . "\n";
        echo 'HTTPS site URL: ' . (str_starts_with(env('APP_URL'), 'https://') ? 'configured' : 'not configured (local preview only)') . "\n";
        break;
    default:
        echo "LAP Crash Analysis commands:\n  init [--demo]                 Initialize an empty dedicated database\n  create-admin USER EMAIL NAME Create the first administrator\n  check                        Check database and configuration\n  mail:work                    Deliver pending email (safe for cron)\n  mail:test EMAIL               Send one delivery test to a controlled address\n";
}
