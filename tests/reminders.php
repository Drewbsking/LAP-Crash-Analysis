<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_dir($root . '/test-results')) mkdir($root . '/test-results', 0700, true);
$dsn = getenv('TEST_MYSQL_DSN') ?: 'sqlite:' . $root . '/test-results/reminders-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('APP_ENV=test'); putenv('APP_DSN=' . $dsn); putenv('MAIL_TRANSPORT=log'); putenv('APP_URL=https://portal.example.test');
if (getenv('TEST_MYSQL_DSN')) {
    putenv('APP_DB_USER=' . (getenv('TEST_MYSQL_USER') ?: ''));
    putenv('APP_DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));
}
require $root . '/app/bootstrap.php';
use App\{Auth, DB, Mail, Projects, Reminders};

$checks = 0;
function ok(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    $checks++; echo "PASS $description\n";
}
function clockAt(string $time): DateTimeImmutable { return new DateTimeImmutable($time, new DateTimeZone('UTC')); }
function tick(string $time): array { return Reminders::queueDue(clockAt($time)); }
function request(string $number, string $submitted, int $requester): int {
    $id = Projects::create($requester, ['rcoc_number'=>$number, 'name'=>'Test <reminder>', 'needed_by'=>'2026-10-15', 'bid_date'=>'2027-01-01', 'design_exception'=>'0', 'exception_details'=>'', 'project_type'=>'3R', 'type_details'=>'', 'construction_year'=>'2027', 'scope'=>'Road rehabilitation.', 'limits_text'=>'A to B', 'mdot_number'=>'000987', 'safety_improvements'=>['None'], 'safety_details'=>''], bin2hex(random_bytes(32)));
    DB::query('UPDATE projects SET created_at=? WHERE id=?', [$submitted, $id]);
    return $id;
}
function finish(int $admin, int $id): void {
    $p = Projects::get($id);
    if ($p['status'] === 'entered') Projects::folder($admin, $id, (int)$p['version'], 'G:\\A TOC');
    $p = Projects::get($id);
    if ($p['status'] === 'started') Projects::advance($admin, $id, (int)$p['version'], 'ready_for_review', true);
    $p = Projects::get($id);
    if ($p['status'] === 'ready_for_review') Projects::advance($admin, $id, (int)$p['version'], 'complete', true);
}
function mails(int $project): array {
    return DB::query('SELECT m.*, r.period_number FROM mail_outbox m JOIN reminder_deliveries d ON d.mail_id=m.id JOIN project_reminders r ON r.id=d.reminder_id WHERE r.project_id=? ORDER BY m.id', [$project])->fetchAll();
}
function rows(string $table): int { return (int)DB::query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
function race(array $commands): array {
    $jobs = [];
    foreach ($commands as $command) {
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/reminder-race.php', ...$command], [['pipe','r'],['pipe','w'],['pipe','w']], $pipes, APP_ROOT);
        fclose($pipes[0]); $jobs[] = [$process, $pipes];
    }
    $outputs = [];
    foreach ($jobs as [$process, $pipes]) {
        $outputs[] = trim(stream_get_contents($pipes[1]));
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) throw new RuntimeException('Worker failed: ' . $error);
    }
    sort($outputs);
    return $outputs;
}

DB::install();
ok(Reminders::installed(), 'Fresh installation includes status reminder tables');
// The disposable database is brand new; remove only its empty addition tables
// to reproduce an existing version-one deployment before filling its records.
DB::query('DROP TABLE reminder_deliveries');
DB::query('DROP TABLE project_reminders');
ok(!Reminders::installed(), 'An older schema is detected without changing its tables');
$temp = 'Temporary-Test-Password!';
$password = 'Private-Test-Password!';
$admin = Auth::createUser(['full_name'=>'Administrator','email'=>'admin@example.test','role'=>'admin'], $temp);
Auth::changePassword($admin, $temp, $password);
$extra = Auth::createUser(['full_name'=>'Requester','email'=>'requester@example.test','role'=>'design'], $temp, $admin);
Auth::changePassword($extra, $temp, $password);
$analyst = Auth::createUser(['full_name'=>'Jeremy Miller','email'=>'jeremy@example.test','role'=>'analyst'], $temp, $admin);
$id = request('R-001', '2026-10-01 14:00:00', $admin);
Mail::$testTransport = function (array $row): void {};
$oldMail = (int)DB::query('SELECT id FROM mail_outbox ORDER BY id LIMIT 1')->fetchColumn();
ok(Mail::deliver($oldMail), 'Existing milestone delivery works before the migration');
$before = [rows('users'), rows('projects'), rows('project_events'), rows('mail_outbox'), Projects::get($id)];
DB::migrate(); DB::migrate();
ok(Reminders::installed(), 'Migration adds reminder tables to an existing populated database');
ok($before === [rows('users'), rows('projects'), rows('project_events'), rows('mail_outbox'), Projects::get($id)], 'Repeated migration preserves existing accounts, projects, history, and mail');
ok(tick('2026-10-15 13:59:59')['queued'] === 0, 'No reminder before the first 14-day anniversary');
$eventCount = rows('project_events');
$version = Projects::get($id)['version'];
ok(tick('2026-10-15 14:00:00')['queued'] === 1, 'First reminder is due exactly 14 days after submission');
$first = mails($id);
ok(count($first) === 2 && count(array_unique(array_column($first, 'recipient'))) === 2, 'A project contact who requests a project receives one copy');
ok($first[0]['event_id'] === null && rows('project_events') === $eventCount && Projects::get($id)['version'] === $version, 'Reminder does not impersonate an actor, advance a milestone, or stale an edit');
ok(str_contains($first[0]['text_body'], 'Status: Entered') && str_contains($first[0]['text_body'], 'RCOC project: R-001') && str_contains($first[0]['text_body'], 'MDOT job: 000987') && str_contains($first[0]['text_body'], 'Submitted:') && str_contains($first[0]['text_body'], 'Needed by:') && str_contains($first[0]['text_body'], 'https://portal.example.test/project.php?id=' . $id), 'Reminder includes identifiers, current status, submission, deadline, and protected link');
ok(str_contains($first[0]['html_body'], 'Test &lt;reminder&gt;') && !str_contains($first[0]['html_body'], '<reminder>'), 'Project text is escaped in reminder HTML');
ok(!str_contains($first[0]['text_body'], 'Overdue'), 'A project due today is not called overdue');
ok(tick('2026-10-15 14:00:00')['queued'] === 0 && tick('2026-10-28 23:00:00')['queued'] === 0 && count(mails($id)) === 2, 'Repeated and intervening cron runs do not resend the same period');
Mail::$testTransport = function (): void { throw new RuntimeException('Simulated SMTP failure'); };
ok(!Mail::deliver((int)$first[0]['id']), 'SMTP failure is retained for reminder retry');
$delivered = [];
Mail::$testTransport = function (array $row) use (&$delivered): void { $delivered[] = $row; };
ok(Mail::retry($admin, (int)$first[0]['id']) && !Mail::retry($admin, (int)$first[0]['id']) && count($delivered) === 1, 'Reminder retry sends once without a new reminder period');
Projects::folder($admin, $id, 1, 'G:\\A TOC');
ok(tick('2026-10-29 14:00:00')['queued'] === 1, 'Second reminder remains anchored at day 28 despite a milestone change');
$second = mails($id);
ok(str_contains($second[2]['text_body'], 'Status: Started'), 'Next reminder uses the current Started status');
ok($second[0]['state'] === 'sent' && $second[1]['state'] === 'cancelled', 'New reminder supersedes older unsent mail while retaining delivered history');
Projects::advance($admin, $id, 2, 'ready_for_review', true);
ok(tick('2026-11-12 15:00:00')['queued'] === 1, 'Ready for Review continues to receive status reminders');
$third = mails($id);
ok(str_contains($third[4]['text_body'], 'Status: Ready for Review') && str_contains($third[4]['text_body'], 'Overdue - awaiting signed completion.'), 'Ready for Review reminder includes overdue signed-completion deadline');
Mail::$testTransport = function (): void { throw new RuntimeException('Simulated SMTP failure'); };
Mail::deliver((int)$third[4]['id']);
finish($admin, $id);
ok(!array_filter(mails($id), fn($m) => in_array($m['state'], ['pending','failed'], true)), 'Completion cancels queued and failed reminders');
Mail::$testTransport = function (array $row) use (&$delivered): void { $delivered[] = $row; };
ok(!Mail::retry($admin, (int)$third[4]['id']) && tick('2027-01-01 15:00:00')['queued'] === 0, 'Completed projects cannot generate or retry a reminder');

ok(Reminders::duePeriod('2026-03-01 15:00:00', clockAt('2026-03-15 13:59:59')) === null && Reminders::duePeriod('2026-03-01 15:00:00', clockAt('2026-03-15 14:00:00'))['scheduled_for'] === '2026-03-15 14:00:00', 'Spring daylight saving preserves the Eastern submission time');
ok(Reminders::duePeriod('2026-10-25 14:00:00', clockAt('2026-11-08 14:59:59')) === null && Reminders::duePeriod('2026-10-25 14:00:00', clockAt('2026-11-08 15:00:00'))['scheduled_for'] === '2026-11-08 15:00:00', 'Fall daylight saving preserves the Eastern submission time');
ok(Reminders::duePeriod('2026-10-20 14:00:00', clockAt('2026-10-01 14:00:00')) === null, 'Future submission dates do not trigger reminders');
$catchup = request('R-CATCHUP', '2026-01-01 15:00:00', $extra);
tick('2026-02-20 15:00:00');
$catchupMail = mails($catchup);
ok(count($catchupMail) === 3 && (int)$catchupMail[0]['period_number'] === 3, 'Missed runs queue only the latest due period and include a separate requester');
ok(tick('2026-02-20 16:00:00')['queued'] === 0 && tick('2026-02-26 15:00:00')['queued'] === 1, 'Catch-up does not shift the original two-week schedule');
finish($admin, $catchup);

$missing = request('R-CONFIG', '2026-10-01 14:00:00', $extra);
DB::query('UPDATE users SET is_active=0 WHERE id=?', [$analyst]);
$before = [rows('project_reminders'), rows('mail_outbox')];
$result = tick('2026-10-15 14:00:00');
ok($result['failed'] === 1 && $before === [rows('project_reminders'), rows('mail_outbox')], 'Inactive project contact rolls back the whole reminder');
DB::query('UPDATE users SET is_active=1 WHERE id=?', [$analyst]);
ok(tick('2026-10-15 14:00:00')['queued'] === 1, 'Next cron run queues the period after the project contact is reactivated');
finish($admin, $missing);

$rollback = request('R-ROLLBACK', '2026-10-01 14:00:00', $admin);
$before = [rows('project_reminders'), rows('mail_outbox'), rows('reminder_deliveries')];
if (DB::sqlite()) DB::conn()->exec("CREATE TRIGGER reject_reminder_link BEFORE INSERT ON reminder_deliveries BEGIN SELECT RAISE(ABORT, 'Simulated reminder failure'); END");
else DB::conn()->exec("CREATE TRIGGER reject_reminder_link BEFORE INSERT ON reminder_deliveries FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated reminder failure'");
try { tick('2026-10-15 14:00:00'); throw new RuntimeException('Expected queue failure'); }
catch (PDOException $e) { ok(str_contains($e->getMessage(), 'Simulated reminder failure'), 'Queue storage failure is reported'); }
DB::conn()->exec('DROP TRIGGER reject_reminder_link');
ok($before === [rows('project_reminders'), rows('mail_outbox'), rows('reminder_deliveries')], 'Storage failure rolls back period, all messages, and links together');
ok(tick('2026-10-15 14:00:00')['queued'] === 1, 'A failed transaction leaves the reminder available for the next run');
// Simulate completion by an external update; delivery still rechecks the current status.
$guardMail = mails($rollback)[0];
DB::query("UPDATE projects SET status='complete' WHERE id=?", [$rollback]);
ok(!Mail::deliver((int)$guardMail['id']) && DB::one('SELECT state FROM mail_outbox WHERE id=?', [$guardMail['id']])['state'] === 'cancelled', 'Delivery rechecks completion before claiming an old queued reminder');
DB::transaction(fn() => Reminders::cancelPending($rollback));

$raceId = request('R-RACE', '2026-10-01 14:00:00', $admin);
ok(race([['queue','2026-10-15 14:00:00'], ['queue','2026-10-15 14:00:00']]) === ['0','1'] && count(mails($raceId)) === 2, 'Overlapping cron workers queue one reminder and one recipient set');
finish($admin, $raceId);
$completeRace = request('R-COMPLETE-RACE', '2026-10-01 14:00:00', $admin);
Projects::folder($admin, $completeRace, 1, 'G:\\A TOC');
Projects::advance($admin, $completeRace, 2, 'ready_for_review', true);
race([['queue','2026-10-15 14:00:00'], ['complete',(string)$admin,(string)$completeRace]]);
ok(Projects::get($completeRace)['status'] === 'complete' && !array_filter(mails($completeRace), fn($m) => in_array($m['state'], ['pending','failed'], true)), 'Simultaneous completion and reminder scheduling leave no reminder waiting to send');

echo "\n$checks reminder checks passed on " . DB::conn()->getAttribute(PDO::ATTR_DRIVER_NAME) . ".\n";
