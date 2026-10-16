<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_dir($root . '/test-results')) mkdir($root . '/test-results', 0700, true);
$dsn = getenv('TEST_MYSQL_DSN') ?: 'sqlite:' . $root . '/test-results/invitations-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('APP_ENV=test');
putenv('APP_DSN=' . $dsn);
putenv('APP_URL=https://portal.example.test');
putenv('MAIL_TRANSPORT=log');
if (getenv('TEST_MYSQL_DSN')) {
    putenv('APP_DB_USER=' . (getenv('TEST_MYSQL_USER') ?: ''));
    putenv('APP_DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));
}
require $root . '/app/bootstrap.php';
use App\{Auth, DB, Mail, ValidationException};

$checks = 0;
function ok(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    $checks++; echo "PASS $description\n";
}
function rejects(callable $operation, string $description): void {
    try { $operation(); } catch (ValidationException $e) { ok(true, $description); return; }
    throw new RuntimeException('FAIL (expected rejection): ' . $description);
}
function person(string $username, string $role = 'design'): array {
    return ['username' => $username, 'full_name' => 'New <Staff> & Colleague', 'email' => $username . '@example.test', 'role' => $role];
}
function countRows(string $table): int { return (int)DB::query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
function message(int $mailId): array { return DB::one('SELECT * FROM mail_outbox WHERE id=?', [$mailId]); }
function tokenFrom(int $mailId): string {
    preg_match('/token=([a-f0-9]{64})/', message($mailId)['text_body'], $matches);
    if (!isset($matches[1])) throw new RuntimeException('Missing setup link');
    return $matches[1];
}
function userRow(int $id): array { return DB::one('SELECT * FROM users WHERE id=?', [$id]); }

DB::install();
$temporary = 'Temporary-Test-Password!';
$password = 'Private-Test-Password!';
$admin = Auth::createUser(person('admin', 'admin'), $temporary);
Auth::changePassword($admin, $temporary, $password);
$designer = Auth::createUser(person('designer'), $temporary, $admin);
Auth::changePassword($designer, $temporary, $password);
ok(countRows('mail_outbox') === 0, 'Bootstrap and internal fixture account creation do not send welcome mail');
rejects(fn() => Auth::inviteUser(person('forbidden'), $designer), 'Design cannot create invitations');
ok(countRows('users') === 2 && countRows('password_resets') === 0, 'Rejected invitation leaves no account or setup token');

$invitation = Auth::inviteUser(person('invited'), $admin);
$id = $invitation['user_id'];
$mailId = $invitation['mail_id'];
$mail = message($mailId);
$token = tokenFrom($mailId);
$reset = DB::one('SELECT * FROM password_resets WHERE token_hash=?', [hash('sha256', $token)]);
ok(countRows('users') === 3 && countRows('mail_outbox') === 1 && countRows('password_resets') === 1, 'Invitation saves one account, token, and notification');
ok($mail['recipient'] === 'invited@example.test' && $mail['event_id'] === null && $mail['state'] === 'pending', 'Welcome is queued only for the new staff member');
ok($reset && $reset['token_hash'] !== $token && (int)$reset['user_id'] === $id, 'Setup token is random and hashed in the token table');
$lifetime = strtotime($reset['expires_at']) - time();
ok($lifetime >= 86395 && $lifetime <= 86400, 'Welcome link expires in 24 hours');
ok(str_contains($mail['text_body'], 'Sign-in email: invited@example.test') && str_contains($mail['text_body'], 'Role: Design'), 'Welcome includes the sign-in email and assigned role');
ok(str_contains($mail['text_body'], 'required project information up front') && str_contains($mail['text_body'], 'records that the request was made') && str_contains($mail['text_body'], 'Ready for Review'), 'Welcome explains complete intake, recorded requests, and progress tracking');
ok(str_contains($mail['html_body'], 'New &lt;Staff&gt; &amp; Colleague') && !str_contains($mail['html_body'], '<Staff>'), 'Welcome escapes user-provided HTML');
ok(str_contains($mail['text_body'], 'https://portal.example.test/reset-password.php?token=') && str_contains($mail['text_body'], 'Forgot your password?'), 'Welcome uses the configured HTTPS URL and explains expired-link recovery');
ok(!str_contains($mail['text_body'], $temporary) && !str_contains($mail['text_body'], userRow($id)['password_hash']), 'Welcome includes no password or password hash');
rejects(fn() => Auth::actor($id), 'Invited user cannot access project services before setting a password');
rejects(fn() => Auth::inviteUser(person('invited'), $admin), 'Duplicate creation is rejected');
ok(countRows('mail_outbox') === 1 && countRows('password_resets') === 1, 'Repeated creation does not duplicate the welcome email');

Mail::$testTransport = function (): void { throw new RuntimeException('Simulated SMTP outage'); };
ok(!Mail::deliver($mailId) && message($mailId)['state'] === 'failed', 'SMTP failure remains available for retry');
ok(userRow($id)['must_reset'] && $reset['used_at'] === null, 'SMTP failure preserves the pending account and setup token');
$delivered = [];
Mail::$testTransport = function (array $message) use (&$delivered): void { $delivered[] = $message; };
ok(Mail::retry($admin, $mailId) && count($delivered) === 1, 'Administrator can retry the original welcome message');
ok(!Mail::retry($admin, $mailId) && count($delivered) === 1 && countRows('users') === 3, 'Retry neither resends accepted mail nor creates another account');
rejects(fn() => Auth::resetWithToken($token, 'short'), 'Setup enforces the password policy');
Auth::resetWithToken($token, $password);
ok(!userRow($id)['must_reset'] && Auth::login('INVITED@example.test', $password, 'invitation-login'), 'Password setup enables normal sign-in without another forced change');
rejects(fn() => Auth::resetWithToken($token, 'Another-Private-Password!'), 'Welcome link cannot be used twice');
rejects(fn() => Auth::resendWelcome($admin, $id), 'Established users cannot receive onboarding links');

$legacy = Auth::createUser(person('legacy'), $temporary, $admin);
rejects(fn() => Auth::resendWelcome($designer, $legacy), 'Only administrators can send welcome links to existing users');
$legacyMail = Auth::resendWelcome($admin, $legacy);
$legacyToken = tokenFrom($legacyMail);
$newMail = Auth::resendWelcome($admin, $legacy);
$newToken = tokenFrom($newMail);
ok($legacyToken !== $newToken && countRows('users') === 4, 'Resending creates a fresh link for the same pending account');
rejects(fn() => Auth::resetWithToken($legacyToken, $password), 'Resending invalidates the older link');
DB::query('UPDATE password_resets SET expires_at=? WHERE token_hash=?', ['2000-01-01 00:00:00', hash('sha256', $newToken)]);
rejects(fn() => Auth::resetWithToken($newToken, $password), 'Expired welcome links are rejected');
Auth::requestReset('legacy@example.test', 'expired-invitation');
$recoveryMail = (int)DB::query('SELECT id FROM mail_outbox WHERE recipient=? ORDER BY id DESC LIMIT 1', ['legacy@example.test'])->fetchColumn();
$recovery = DB::one('SELECT * FROM password_resets WHERE token_hash=?', [hash('sha256', tokenFrom($recoveryMail))]);
ok(strtotime($recovery['expires_at']) - time() <= 3600, 'Forgot-password recovery keeps its one-hour expiry');
Auth::resetWithToken(tokenFrom($recoveryMail), $password);
ok(Auth::login('legacy', $password, 'legacy-recovery'), 'A user with an expired invitation can recover through Forgot password');

$corrected = Auth::inviteUser(person('corrected'), $admin);
$oldToken = tokenFrom($corrected['mail_id']);
Auth::updateUser($admin, $corrected['user_id'], array_replace(userRow($corrected['user_id']), ['email' => 'correct-address@example.test']));
rejects(fn() => Auth::resetWithToken($oldToken, $password), 'Changing the email address invalidates links sent to the old mailbox');
$correctedMail = Auth::resendWelcome($admin, $corrected['user_id']);
ok(message($correctedMail)['recipient'] === 'correct-address@example.test', 'Resent welcome uses the corrected email address');
Auth::updateUser($admin, $corrected['user_id'], array_replace(userRow($corrected['user_id']), ['is_active' => 0]));
rejects(fn() => Auth::resendWelcome($admin, $corrected['user_id']), 'Inactive users cannot receive new welcome links');
rejects(fn() => Auth::resetWithToken(tokenFrom($correctedMail), $password), 'Inactive users cannot use an existing welcome link');
Auth::updateUser($admin, $corrected['user_id'], array_replace(userRow($corrected['user_id']), ['is_active' => 1]));
rejects(fn() => Auth::resetWithToken(tokenFrom($correctedMail), $password), 'Reactivation does not restore old setup links');
$beforeReset = Auth::resendWelcome($admin, $corrected['user_id']);
Auth::adminReset($admin, $corrected['user_id']);
rejects(fn() => Auth::resetWithToken(tokenFrom($beforeReset), $password), 'Administrator password resets revoke earlier setup links');

// Reject the notification INSERT to verify account and token creation roll back together.
$counts = [countRows('users'), countRows('password_resets'), countRows('mail_outbox')];
if (DB::sqlite()) DB::conn()->exec("CREATE TRIGGER reject_welcome BEFORE INSERT ON mail_outbox WHEN NEW.recipient='rollback@example.test' BEGIN SELECT RAISE(ABORT, 'Simulated queue failure'); END");
else DB::conn()->exec("CREATE TRIGGER reject_welcome BEFORE INSERT ON mail_outbox FOR EACH ROW BEGIN IF NEW.recipient='rollback@example.test' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated queue failure'; END IF; END");
try {
    Auth::inviteUser(person('rollback'), $admin);
    throw new RuntimeException('Queue failure was not propagated');
} catch (PDOException $e) {
    ok(str_contains($e->getMessage(), 'Simulated queue failure'), 'Notification storage failure is surfaced');
}
DB::conn()->exec('DROP TRIGGER reject_welcome');
ok($counts === [countRows('users'), countRows('password_resets'), countRows('mail_outbox')], 'Failed notification storage rolls back the account and setup token');

$race = Auth::inviteUser(person('race'), $admin);
$raceToken = tokenFrom($race['mail_id']);
$jobs = [];
for ($i = 0; $i < 2; $i++) {
    $pipes = [];
    $process = proc_open([PHP_BINARY, __DIR__ . '/invitation-race.php', $raceToken], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, APP_ROOT);
    fclose($pipes[0]);
    $jobs[] = [$process, $pipes];
}
$outputs = [];
foreach ($jobs as [$process, $pipes]) {
    $outputs[] = trim(stream_get_contents($pipes[1]));
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Setup worker failed: ' . $error);
}
sort($outputs);
ok($outputs === ['accepted', 'rejected'], 'Concurrent setup submissions consume the link exactly once');

// Email is the account identity, including on databases created before this update.
ok(Auth::login('  ADMIN@EXAMPLE.TEST  ', $password, 'email-existing'), 'Existing account accepts its saved email regardless of case and outer whitespace');
ok(Auth::login('admin', $password, 'username-existing'), 'Existing username continues to work');
Auth::updateUser($admin, $designer, ['full_name' => 'Updated Designer', 'email' => 'DESIGNER@EXAMPLE.TEST', 'role' => 'design', 'is_active' => 1]);
ok(Auth::login('designer', $password, 'username-after-edit'), 'Editing an existing account without a username preserves its legacy sign-in');
ok(!Auth::login('invited', $password, 'ignored-invite-username') && !Auth::login(userRow($id)['username'], $password, 'internal-key-login'), 'New invitations ignore submitted usernames and internal account keys cannot sign in');

$emailOnly = ['full_name' => 'Email Only Staff', 'email' => '  EMAIL.ONLY+LAP@EXAMPLE.TEST  ', 'role' => 'design'];
$emailAccount = Auth::inviteUser($emailOnly, $admin);
$emailId = $emailAccount['user_id'];
ok(message($emailAccount['mail_id'])['recipient'] === 'email.only+lap@example.test', 'Creation needs no username and normalizes the email before sending');
Auth::resetWithToken(tokenFrom($emailAccount['mail_id']), $password);
ok(Auth::login('email.only+lap@example.test', $password, 'email-only-login'), 'Email with plus addressing works after password setup');
$emailSession = $_SESSION;
rejects(fn() => Auth::inviteUser(array_replace($emailOnly, ['email' => 'EMAIL.ONLY+LAP@example.test']), $admin), 'Duplicate email is rejected regardless of case');
rejects(fn() => Auth::inviteUser(array_replace($emailOnly, ['email' => 'invalid-email']), $admin), 'Account creation rejects an invalid email address');
rejects(fn() => Auth::updateUser($admin, $emailId, array_replace($emailOnly, ['email' => 'ADMIN@example.test', 'is_active' => 1])), 'Editing cannot reuse another account email');
ok(userRow($emailId)['email'] === 'email.only+lap@example.test', 'Rejected email edit preserves the existing sign-in');
Auth::updateUser($admin, $emailId, array_replace($emailOnly, ['email' => 'changed@example.test', 'is_active' => 1]));
$_SESSION = $emailSession;
ok(Auth::user() === null && !Auth::login('email.only+lap@example.test', $password, 'old-email'), 'Changing email revokes existing sessions and the old email sign-in');
ok(Auth::login('CHANGED@example.test', $password, 'new-email'), 'New email uses the existing password');
Auth::updateUser($admin, $emailId, array_replace($emailOnly, ['email' => 'changed@example.test', 'is_active' => 0]));
ok(!Auth::login('changed@example.test', $password, 'inactive-email'), 'Deactivated accounts cannot sign in by email');

$longEmail = str_repeat('a', 60) . '@' . str_repeat('b', 50) . '.example.test';
$longAccount = Auth::inviteUser(array_replace($emailOnly, ['email' => $longEmail]), $admin);
Auth::resetWithToken(tokenFrom($longAccount['mail_id']), $password);
ok(strlen($longEmail) > 100 && Auth::login($longEmail, $password, 'long-email'), 'Email addresses longer than the legacy username column work without a schema change');

Auth::createUser(person('limited'), $password, $admin);
for ($attempt = 0; $attempt < 15; $attempt++) {
    Auth::login($attempt % 2 ? 'limited' : 'limited@example.test', 'wrong-password', 'shared-limit');
}
ok(!Auth::login('LIMITED@example.test', $password, 'shared-limit') && !Auth::login('limited', $password, 'shared-limit'), 'Email and legacy username share the same failed-attempt limit');
echo "\n$checks invitation checks passed on " . DB::conn()->getAttribute(PDO::ATTR_DRIVER_NAME) . ".\n";
