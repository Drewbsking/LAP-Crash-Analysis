<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_dir($root . '/test-results')) mkdir($root . '/test-results', 0700, true);
$testDsn = getenv('TEST_MYSQL_DSN') ?: 'sqlite:' . $root . '/test-results/test-' . bin2hex(random_bytes(6)) . '.sqlite';
putenv('APP_ENV=test'); putenv('APP_DSN=' . $testDsn); putenv('MAIL_TRANSPORT=log');
if (getenv('TEST_MYSQL_DSN')) {
    putenv('APP_DB_USER=' . (getenv('TEST_MYSQL_USER') ?: ''));
    putenv('APP_DB_PASSWORD=' . (getenv('TEST_MYSQL_PASSWORD') ?: ''));
}
require $root . '/app/bootstrap.php';
use App\{Auth, DB, Mail, Projects, ValidationException};
$checks = 0;
function ok(bool $condition, string $description): void {
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $description);
    $checks++; echo "PASS $description\n";
}
function rejects(callable $fn, string $description, ?string $part = null): void {
    try { $fn(); } catch (ValidationException $e) { ok($part === null || str_contains($e->getMessage(), $part), $description); return; }
    throw new RuntimeException('FAIL (expected rejection): ' . $description);
}
function number(string $table): int { return (int)DB::query("SELECT COUNT(*) FROM $table")->fetchColumn(); }
function form(string $number = '001234'): array {
    return ['rcoc_number'=>$number,'name'=>'Maple & Adams <review>','needed_by'=>'2026-10-15','bid_date'=>'2027-01-15','design_exception'=>'0','exception_details'=>'','project_type'=>'3R','type_details'=>'','construction_year'=>'2027','scope'=>'Rehabilitate the road.','limits_text'=>'A Street to B Street','mdot_number'=>'000987','safety_improvements'=>['Turn lanes','Pavement markings'],'safety_details'=>''];
}
DB::install();
$temp = 'Temporary-Test-2026!'; $password = 'A-Private-Test-Password!';
$admin = Auth::createUser(['username'=>'bates','full_name'=>'Andrew Bates','email'=>'bates@example.test','role'=>'admin'], $temp);
rejects(fn()=>Auth::actor($admin, ['admin']), 'First-login password change enforced in services');
ok(Auth::login('bates', $temp, 'test-login'), 'Temporary password allows sign-in');
ok((bool)Auth::user()['must_reset'], 'Temporary session is marked for password change');
Auth::changePassword($admin, $temp, $password);
ok(!DB::one('SELECT must_reset FROM users WHERE id=?', [$admin])['must_reset'], 'First password change clears requirement');
$users = [];
foreach ([['peters','Andrew Peters','design'],['mayich','Jacob Mayich','design'],['jeremy','Jeremy Miller','analyst'],['extra','Extra Designer','design']] as [$username,$name,$role]) {
    $id = Auth::createUser(['username'=>$username,'full_name'=>$name,'email'=>$username.'@example.test','role'=>$role], $temp, $admin);
    Auth::changePassword($id,$temp,$password); $users[$username]=$id;
}
rejects(fn()=>Projects::create($users['jeremy'], form(), bin2hex(random_bytes(32))), 'Analyst cannot submit requests');
$token = bin2hex(random_bytes(32));
$id = Projects::create($users['peters'], form(), $token);
ok(Projects::get($id)['status']==='entered', 'New request enters first milestone');
ok(Projects::get($id)['rcoc_number']==='001234' && Projects::get($id)['mdot_number']==='000987', 'Leading zeros preserved');
ok(number('mail_outbox')===3, 'Request goes only to its requester and the two project contacts');
ok(Projects::create($users['peters'], form(), $token)===$id && number('project_events')===1 && number('mail_outbox')===3, 'Repeated submission token does not repeat milestone or email');
try { Projects::create($users['peters'], form(), bin2hex(random_bytes(32))); throw new RuntimeException('Expected duplicate'); } catch (ValidationException $e) { ok($e->existingProjectId===$id, 'Duplicate project links to existing request'); }
rejects(fn()=>Projects::create($users['extra'], form(), $token), 'Submission token cannot be reused by another requester');
rejects(fn()=>Projects::validate(array_replace(form(),['name'=>''])), 'Required fields enforced');
rejects(fn()=>Projects::validate(array_replace(form(),['needed_by'=>'2026-02-30'])), 'Invalid calendar dates rejected');
rejects(fn()=>Projects::validate(array_replace(form(),['design_exception'=>'1'])), 'Design exception explanation required');
rejects(fn()=>Projects::validate(array_replace(form(),['project_type'=>'Other'])), 'Other project type explanation required');
rejects(fn()=>Projects::validate(array_replace(form(),['safety_improvements'=>[]])), 'Safety selection required');
rejects(fn()=>Projects::validate(array_replace(form(),['safety_improvements'=>['None','Turn lanes']])), 'None cannot be combined with safety improvements');
rejects(fn()=>Projects::validate(array_replace(form(),['safety_improvements'=>['Other']])), 'Other safety description required');
ok(Projects::validate(array_replace(form(),['safety_improvements'=>['None']]))['safety_improvements']==='["None"]', 'Explicit None accepted');
rejects(fn()=>Projects::update($users['mayich'],$id,1,array_replace(form(),['scope'=>'Unauthorized change'])), 'Other designer cannot edit request');
Projects::update($users['peters'],$id,1,array_replace(form(),['scope'=>'Updated scope']));
ok(Projects::get($id)['scope']==='Updated scope', 'Requester can edit active project');
$event = DB::one("SELECT * FROM project_events WHERE event_type='details_updated'");
ok(json_decode($event['changes_json'],true)['scope']['before']==='Rehabilitate the road.' && (int)$event['actor_id']===$users['peters'], 'History preserves previous value and actor');
rejects(fn()=>Projects::update($admin,$id,1,form()), 'Stale project details rejected', 'changed');
rejects(fn()=>Projects::folder($users['peters'],$id,2,'G:\\A TOC'), 'Designer cannot start analysis');
rejects(fn()=>Projects::advance($users['jeremy'],$id,2,'complete',true), 'Cannot skip milestones');
rejects(fn()=>Projects::folder($users['jeremy'],$id,2,'https://example.test/folder'), 'Folder requires a Windows path');
Projects::folder($users['jeremy'],$id,2,'G:\\A TOC\\A folder with spaces');
ok(Projects::get($id)['folder_path']==='G:\\A TOC\\A folder with spaces' && Projects::get($id)['status']==='started', 'Saving exact Windows path starts the review');
rejects(fn()=>Projects::advance($users['jeremy'],$id,3,'ready_for_review',false), 'Confirmation checkbox enforced');
Projects::advance($users['jeremy'],$id,3,'ready_for_review',true);
ok(Projects::overdue(Projects::get($id),'2026-10-16'), 'Ready for Review remains overdue after needed-by date');
ok(!Projects::overdue(Projects::get($id),'2026-10-15') && !Projects::overdue(Projects::get($id),'2026-10-14'), 'Due today and future dates are not overdue');
rejects(fn()=>Projects::advance($users['jeremy'],$id,3,'ready_for_review',true), 'Repeated stale transition rejected');
Projects::advance($admin,$id,4,'complete',true);
ok(!Projects::overdue(Projects::get($id),'2026-10-16'), 'Completed project is never overdue');
rejects(fn()=>Projects::update($admin,$id,5,form()), 'Completed project details are read-only');
rejects(fn()=>Projects::folder($users['jeremy'],$id,5,'G:\\New Folder'), 'Completed folder is read-only');
rejects(fn()=>Projects::advance($admin,$id,5,'started',true), 'Completed project cannot reopen');
ok(number('mail_outbox')===12, 'Exactly one notification per recipient at every milestone');
ok((int)DB::query("SELECT COUNT(*) FROM project_events WHERE event_type='milestone'")->fetchColumn()===4, 'All four milestone events preserved');
$id2 = Projects::create($users['extra'],form('001235'),bin2hex(random_bytes(32)));
ok(number('mail_outbox')===15, 'Each new requester receives their own project notifications');
Projects::folder($admin,$id2,1,'G:\\A TOC');
Projects::advance($admin,$id2,2,'ready_for_review',true);
Projects::advance($users['jeremy'],$id2,3,'complete',true);
ok(Projects::get($id2)['status']==='complete','Jeremy can also confirm final signing');
$before = number('projects');
DB::query('UPDATE users SET is_active=0 WHERE id=?', [$users['jeremy']]);
rejects(fn()=>Projects::create($users['peters'],form('missing-address'),bin2hex(random_bytes(32))), 'Inactive project contact prevents incomplete notification setup');
ok(number('projects')===$before, 'Notification configuration failure rolls back request');
DB::query('UPDATE users SET is_active=1 WHERE id=?', [$users['jeremy']]);
$received=[];
Mail::$testTransport = function(array $message) use (&$received): void { $received[]=$message; };
Mail::deliverPending(100);
ok(count($received)===number('mail_outbox'), 'Milestone notifications delivered through controlled transport');
Mail::deliverPending(100);
ok(count($received)===number('mail_outbox'), 'Sent notifications are not resent');
Mail::$testTransport = function(): void { throw new RuntimeException('Simulated mail outage'); };
$failedId = Projects::create($users['peters'],form('mail-failure'),bin2hex(random_bytes(32)));
Mail::deliverPending(100);
ok(Projects::get($failedId)!==null && (int)DB::query("SELECT COUNT(*) FROM mail_outbox WHERE state='failed'")->fetchColumn()===3, 'SMTP failure keeps project saved and records failures');
$mailId = (int)DB::query("SELECT id FROM mail_outbox WHERE state='failed' ORDER BY id LIMIT 1")->fetchColumn();
rejects(fn()=>Mail::retry($users['peters'],$mailId), 'Only administrator can retry email');
Mail::$testTransport = function(array $message) use (&$received): void { $received[]=$message; };
ok(Mail::retry($admin,$mailId), 'Administrator can retry failed delivery');
ok(!Mail::retry($admin,$mailId), 'Retry does not resend a sent message');
ok(number('project_events')===10, 'Email retry never repeats project milestones');
// A lost database receipt after SMTP acceptance must remain visibly uncertain.
$uncertainId=(int)DB::query("SELECT id FROM mail_outbox WHERE state='failed' ORDER BY id LIMIT 1")->fetchColumn();
if(DB::sqlite()) DB::conn()->exec("CREATE TRIGGER reject_mail_receipt BEFORE UPDATE ON mail_outbox WHEN NEW.state='sent' BEGIN SELECT RAISE(ABORT, 'Simulated receipt failure'); END");
else DB::conn()->exec("CREATE TRIGGER reject_mail_receipt BEFORE UPDATE ON mail_outbox FOR EACH ROW BEGIN IF NEW.state='sent' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Simulated receipt failure'; END IF; END");
ok(!Mail::retry($admin,$uncertainId) && DB::one('SELECT state FROM mail_outbox WHERE id=?',[$uncertainId])['state']==='sending','Lost receipt preserves uncertain delivery state');
DB::conn()->exec('DROP TRIGGER reject_mail_receipt');
rejects(fn()=>Mail::retry($admin,$uncertainId),'Uncertain in-flight email cannot be immediately retried');
DB::query('UPDATE mail_outbox SET locked_at=? WHERE id=?',['2000-01-01 00:00:00',$uncertainId]);
ok(Mail::retry($admin,$uncertainId),'Administrator can recover an old uncertain delivery');
Auth::requestReset('peters@example.test','reset-test');
$resetMail = DB::one("SELECT * FROM mail_outbox WHERE event_id IS NULL ORDER BY id DESC LIMIT 1");
preg_match('/token=([a-f0-9]{64})/', $resetMail['text_body'], $matches); $resetToken = $matches[1];
$resetRow = DB::one('SELECT * FROM password_resets ORDER BY id DESC LIMIT 1');
ok($resetRow['token_hash']===hash('sha256',$resetToken) && $resetRow['token_hash']!==$resetToken,'Password reset token stored hashed');
Auth::login('peters',$password,'session-test'); $oldSession=$_SESSION;
Auth::resetWithToken($resetToken,'New-Private-Test-Password!');
rejects(fn()=>Auth::resetWithToken($resetToken,'Another-Private-Password!'),'Password reset token is single-use');
$_SESSION=$oldSession; ok(Auth::user()===null,'Password reset revokes existing sessions');
Auth::requestReset('peters@example.test','reset-test');
$resetMail=DB::one('SELECT * FROM mail_outbox WHERE event_id IS NULL ORDER BY id DESC LIMIT 1');preg_match('/token=([a-f0-9]{64})/',$resetMail['text_body'],$matches);
DB::query('UPDATE password_resets SET expires_at=? WHERE used_at IS NULL',['2000-01-01 00:00:00']);
rejects(fn()=>Auth::resetWithToken($matches[1],'Another-Private-Password!'),'Expired reset token rejected');
$person=DB::one('SELECT * FROM users WHERE id=?',[$users['mayich']]);
Auth::login('mayich',$password,'deactivation-test');
Auth::updateUser($admin,$users['mayich'],array_replace($person,['is_active'=>0]));
ok(Auth::user()===null,'Deactivation revokes current access');
rejects(fn()=>Projects::create($users['mayich'],form('inactive'),bin2hex(random_bytes(32))),'Inactive user cannot submit via service');
$adminRow=DB::one('SELECT * FROM users WHERE id=?',[$admin]);
rejects(fn()=>Auth::updateUser($admin,$admin,array_replace($adminRow,['is_active'=>0])),'Administrator cannot deactivate self');
$temporary=Auth::adminReset($admin,$users['jeremy']);
ok(Auth::login('jeremy',$temporary,'admin-reset') && (bool)Auth::user()['must_reset'],'Admin reset requires a new first-login change');
ok(count(Projects::search(['status'=>'complete'])['rows'])===2,'Completed filter includes completed projects');
ok(count(Projects::search(['status'=>'active'])['rows'])===1,'Active filter excludes completed projects');
ok(count(Projects::search(['status'=>'all','q'=>'000987'])['rows'])===3,'MDOT number search preserves leading zeros');
ok(h('<script>')==='&lt;script&gt;','Display escaping prevents markup injection');
ok(local_time('2026-10-09 14:00:00')==='Oct 9, 2026 · 10:00 am EDT','Timestamp displays in Eastern time');
// Parallel process tests use the same dedicated database, never production data.
$jobs=[]; $raceToken=bin2hex(random_bytes(32));
for($i=0;$i<2;$i++) {
    $pipes=[]; $process=proc_open([PHP_BINARY,__DIR__.'/race.php','create',(string)$users['peters'],$raceToken], [['pipe','r'],['pipe','w'],['pipe','w']],$pipes,APP_ROOT);
    fclose($pipes[0]);$jobs[]=[$process,$pipes];
}
$outputs=[];
foreach($jobs as [$process,$pipes]){$outputs[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException('Race worker failed: '.$err);}
ok($outputs[0]===$outputs[1] && ctype_digit($outputs[0]),'Simultaneous duplicate submissions return one project');
$raceId=(int)$outputs[0];
ok((int)DB::query('SELECT COUNT(*) FROM project_events WHERE project_id=?',[$raceId])->fetchColumn()===1,'Concurrent submission writes one event');
$jobs=[];
for($i=0;$i<2;$i++){$pipes=[];$process=proc_open([PHP_BINARY,__DIR__.'/race.php','folder',(string)$admin,(string)$raceId],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes,APP_ROOT);fclose($pipes[0]);$jobs[]=[$process,$pipes];}
$outputs=[];
foreach($jobs as [$process,$pipes]){$outputs[]=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);}
sort($outputs);ok($outputs===['changed','stale'],'Concurrent milestone updates have one winner and one stale rejection');
ok((int)DB::query('SELECT COUNT(*) FROM mail_outbox m JOIN project_events e ON e.id=m.event_id WHERE e.project_id=?',[$raceId])->fetchColumn()===6,'Concurrent milestone creates one recipient set');
echo "\n$checks checks passed on " . DB::conn()->getAttribute(PDO::ATTR_DRIVER_NAME) . ".\n";
