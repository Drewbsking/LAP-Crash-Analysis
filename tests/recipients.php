<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_dir($root . '/test-results')) mkdir($root . '/test-results', 0700, true);
$dsn = getenv('TEST_MYSQL_DSN') ?: 'sqlite:' . $root . '/test-results/recipients-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('APP_ENV=test'); putenv('APP_DSN=' . $dsn); putenv('MAIL_TRANSPORT=log');
if (getenv('TEST_MYSQL_DSN')) {
    putenv('APP_DB_USER=' . (getenv('TEST_MYSQL_USER') ?: ''));
    putenv('APP_DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));
}
require $root . '/app/bootstrap.php';
use App\{Auth, DB, Mail, Projects, ProjectContacts, Reminders, ValidationException};

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
function account(string $name, string $email, string $role, ?int $admin = null): int {
    $id = Auth::createUser(['full_name'=>$name, 'email'=>$email, 'role'=>$role], 'Temporary-Test-Password!', $admin);
    Auth::changePassword($id, 'Temporary-Test-Password!', 'Private-Test-Password!');
    return $id;
}
function project(string $number, int $requester): int {
    return Projects::create($requester, ['rcoc_number'=>$number,'name'=>'Maple Road '.$number,'needed_by'=>'2027-03-01','bid_date'=>'2027-05-01','design_exception'=>'0','exception_details'=>'','project_type'=>'3R','type_details'=>'','construction_year'=>'2027','scope'=>'Rehabilitate road.','limits_text'=>'A to B','mdot_number'=>'000987','safety_improvements'=>['None'],'safety_details'=>''], bin2hex(random_bytes(32)));
}
function recipientEmails(int $project): array {
    $emails = DB::query('SELECT DISTINCT m.recipient FROM mail_outbox m JOIN project_events e ON e.id=m.event_id WHERE e.project_id=? ORDER BY m.recipient', [$project])->fetchAll(PDO::FETCH_COLUMN);
    return $emails;
}
DB::install();
$admin = account('Andrew Bates', 'bates@example.test', 'admin');
$jeremy = account('Jeremy Miller', 'jeremy@example.test', 'analyst', $admin);
$peters = account('Andrew Peters', 'peters@example.test', 'design', $admin);
$mayich = account('Jacob Mayich', 'mayich@example.test', 'design', $admin);
$otherAdmin = account('Other Administrator', 'other-admin@example.test', 'admin', $admin);
$otherAnalyst = account('Other Analyst', 'other-analyst@example.test', 'analyst', $admin);
// This is a fresh disposable database. Reproduce an upgrade without its new
// contact table, then confirm account selection survives a repeatable migration.
DB::query('DROP TABLE project_contacts');
ok(!ProjectContacts::installed() && ProjectContacts::ready(), 'Existing deployments can resolve the two named accounts before migration');
DB::migrate();
ok(ProjectContacts::installed(), 'Migration adds project contact selection to a populated database');
ok(ProjectContacts::ready(), 'Named project contacts resolve even with additional administrators and analysts');
$petersProject = project('PETERS', $peters);
$mayichProject = project('MAYICH', $mayich);
ok(recipientEmails($petersProject) === ['bates@example.test','jeremy@example.test','peters@example.test'], 'Peters project notifies only Peters, Jeremy, and Bates');
ok(recipientEmails($mayichProject) === ['bates@example.test','jeremy@example.test','mayich@example.test'], 'Other requester project excludes Peters and extra administrators/analysts');
ok((int)DB::query('SELECT COUNT(*) FROM project_contacts WHERE user_id IS NOT NULL')->fetchColumn() === 2, 'Default contacts are saved as stable account IDs');
DB::query("UPDATE stakeholders SET email='retired-list@example.test'");
$own = project('BATES', $admin);
ok(recipientEmails($own) === ['bates@example.test','jeremy@example.test'], 'Reviewer who requests a project is deduplicated and the retired email list is ignored');
$delivered = [];
Mail::$testTransport = function (array $mail) use (&$delivered): void { $delivered[] = $mail; };
$eventId = (int)DB::query('SELECT id FROM project_events WHERE project_id=?', [$mayichProject])->fetchColumn();
$obsolete = Mail::queue($eventId, 'peters@example.test', 'Andrew Peters', 'Old queued milestone', '<p>Old notification</p>', 'Old notification');
ok(!Mail::deliver($obsolete) && DB::one('SELECT state FROM mail_outbox WHERE id=?', [$obsolete])['state'] === 'cancelled', 'Old queued milestone to Peters for another requester is cancelled before SMTP');
ok(!Mail::retry($admin, $obsolete) && !$delivered, 'Cancelled old recipient cannot receive mail through administrator retry');

DB::query("UPDATE projects SET created_at='2026-10-01 14:00:00' WHERE id=?", [$mayichProject]);
Reminders::queueDue(new DateTimeImmutable('2026-10-15 14:00:00', new DateTimeZone('UTC')));
$reminderId = (int)DB::query('SELECT id FROM project_reminders WHERE project_id=?', [$mayichProject])->fetchColumn();
$reminderRecipients = DB::query('SELECT m.recipient FROM mail_outbox m JOIN reminder_deliveries d ON d.mail_id=m.id WHERE d.reminder_id=? ORDER BY m.recipient', [$reminderId])->fetchAll(PDO::FETCH_COLUMN);
ok($reminderRecipients === ['bates@example.test','jeremy@example.test','mayich@example.test'], 'Two-week reminders follow the same per-project recipient rule');
$obsoleteReminder = Mail::queue(null, 'peters@example.test', 'Andrew Peters', 'Old queued reminder', '<p>Old reminder</p>', 'Old reminder');
DB::query('INSERT INTO reminder_deliveries(mail_id,reminder_id) VALUES (?,?)', [$obsoleteReminder,$reminderId]);
ok(!Mail::deliver($obsoleteReminder) && count($delivered) === 0, 'Old queued reminder also excludes Peters from other projects');
$oldJeremyMail = (int)DB::query('SELECT m.id FROM mail_outbox m JOIN project_events e ON e.id=m.event_id WHERE e.project_id=? AND m.recipient=?', [$mayichProject,'jeremy@example.test'])->fetchColumn();
Auth::updateUser($admin, $jeremy, ['full_name'=>'Jeremy M. Miller','email'=>'new-jeremy@example.test','role'=>'analyst','is_active'=>1]);
ok((int)ProjectContacts::reviewers()['analyst']['id'] === $jeremy && !Mail::deliver($oldJeremyMail), 'Contact identity survives a name/email edit and the old address is not used');
Projects::folder($jeremy, $mayichProject, 1, 'G:\\A TOC');
ok(in_array('new-jeremy@example.test', recipientEmails($mayichProject), true), 'Future milestone mail uses the contact account current email');
rejects(fn() => ProjectContacts::save($peters, ['contact_analyst'=>$otherAnalyst,'contact_admin'=>$admin]), 'Design staff cannot change the project contacts');
rejects(fn() => ProjectContacts::save($admin, ['contact_analyst'=>$peters,'contact_admin'=>$admin]), 'Design accounts cannot be selected as the Traffic Safety contact');
ProjectContacts::save($admin, ['contact_analyst'=>$otherAnalyst,'contact_admin'=>$admin]);
DB::migrate();
ok((int)ProjectContacts::reviewers()['analyst']['id'] === $otherAnalyst, 'Migration preserves explicitly selected contacts');
$oldNewAddress = (int)DB::query('SELECT m.id FROM mail_outbox m JOIN project_events e ON e.id=m.event_id WHERE e.project_id=? AND m.recipient=?', [$mayichProject,'new-jeremy@example.test'])->fetchColumn();
ok(!Mail::deliver($oldNewAddress), 'Queued mail to a replaced project contact is cancelled');
ProjectContacts::save($admin, ['contact_analyst'=>$jeremy,'contact_admin'=>$admin]);

$filtered = Projects::search(['status'=>'all','requester'=>(string)$peters]);
ok($filtered['count'] === 1 && (int)$filtered['rows'][0]['id'] === $petersProject, 'Requester filter selects exactly that person projects');
ok(Projects::search(['status'=>'started','year'=>'2027','q'=>'MAYICH','requester'=>(string)$mayich])['count'] === 1, 'Requester combines with status, construction year, and text search');
ok(Projects::search(['status'=>'all','q'=>'MAYICH','requester'=>(string)$peters])['count'] === 0, 'Mixed filters cannot expose another requester results');
ok(Projects::search(['status'=>'all','requester'=>"1 OR 1=1"])['count'] === 0, 'Malformed requester filter is safely rejected as no match');
Auth::updateUser($admin, $peters, ['full_name'=>'Andrew Peters','email'=>'peters@example.test','role'=>'design','is_active'=>0]);
ok(Projects::search(['status'=>'all','requester'=>(string)$peters])['count'] === 1, 'Projects remain filterable when the requester account is inactive');
Auth::updateUser($admin, $peters, ['full_name'=>'Andrew Peters','email'=>'peters@example.test','role'=>'design','is_active'=>1]);
for ($i=0; $i<26; $i++) project('PAGE-'.$i, $peters);
$page = Projects::search(['status'=>'all','year'=>'2027','requester'=>(string)$peters,'page'=>'2']);
ok($page['count'] === 27 && $page['pages'] === 2 && count($page['rows']) === 2 && !array_filter($page['rows'], fn($p) => (int)$p['requester_id'] !== $peters), 'Requester filtering preserves correct counts and pagination');
ok(Projects::search(['status'=>'all'])['count'] === 29 && Projects::get($mayichProject)['requester_id'] == $mayich, 'All-project view and direct project access retain other requesters projects');
echo "\n$checks recipient/filter checks passed on " . DB::conn()->getAttribute(PDO::ATTR_DRIVER_NAME) . ".\n";
