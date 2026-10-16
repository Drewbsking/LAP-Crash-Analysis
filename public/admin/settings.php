<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = App\Auth::requireAdmin();
$errors = [];
if (is_post()) {
    check_csrf();
    try {
        if (post_string('action') === 'retry') {
            $sent = App\Mail::retry((int)$user['id'], (int)post_string('mail_id'));
            flash($sent ? 'Email delivery completed.' : 'Email was not sent. Check its delivery status and SMTP settings.', $sent ? 'success' : 'warning');
        } else throw new App\ValidationException(['Unknown notification action.']);
        redirect('/admin/settings.php');
    } catch (App\ValidationException $e) { $errors = $e->errors; }
}
$reviewers = App\ProjectContacts::reviewers();
$remindersReady = App\Reminders::installed();
$reminderJoin = $remindersReady ? ' LEFT JOIN reminder_deliveries d ON d.mail_id=m.id LEFT JOIN project_reminders r ON r.id=d.reminder_id' : '';
$projectColumn = $remindersReady ? 'COALESCE(e.project_id, r.project_id)' : 'e.project_id';
$mail = App\DB::query("SELECT m.*, $projectColumn AS project_id FROM mail_outbox m LEFT JOIN project_events e ON e.id=m.event_id" . $reminderJoin . " ORDER BY CASE WHEN m.state IN ('sent','cancelled') THEN 1 ELSE 0 END, m.id DESC LIMIT 100")->fetchAll();
$pageTitle = 'Notifications';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-heading"><div><div class="eyebrow">ADMINISTRATION</div><h1>Notifications</h1><p class="muted">Keep the right people informed about project progress.</p></div></div><?php render_errors($errors); ?>
<?php if (!$remindersReady): ?><div class="alert warning">Automatic status reminders need a database update. Run <code>php bin/console.php migrate</code> in the hosting terminal.</div><?php endif; ?>
<section class="panel panel-pad"><h2>Project recipients</h2><p class="muted">The requester and the two project contacts receive milestone emails and reminders every 14 days until Complete. Other staff can view projects on the dashboard.</p><ul><?php foreach (App\ProjectContacts::SLOTS as $slot => $label): ?><li><strong><?= h($label) ?>:</strong> <?= h($reviewers[$slot]['full_name'] ?? 'Select an account in People') ?></li><?php endforeach; ?></ul><a class="hint" href="<?= h(app_path('/admin/users.php#project-contacts')) ?>">Manage project contacts in People</a><p class="hint">Addresses come from staff accounts. No separate email list is needed.</p></section>
<section class="panel" id="delivery"><div class="panel-header"><h2>Email delivery</h2><span class="hint">Latest 100 · Unsent first</span></div><?php if (!$mail): ?><div class="empty-state"><h3>No notifications yet</h3><p>Welcome, milestone, status reminder, and password reset emails will appear here.</p></div><?php else: ?><div class="table-scroll"><table class="admin-table"><thead><tr><th>Recipient / message</th><th>Status</th><th>Attempts</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach ($mail as $item): ?><tr><td><strong><?= h($item['recipient_name']) ?></strong><span class="project-meta"><?= h($item['recipient']) ?></span><span class="project-meta"><?= h($item['subject']) ?></span><?php if ($item['project_id']): ?><a class="hint" href="<?= h(app_path('/project.php?id=' . $item['project_id'])) ?>">View project</a><?php endif; ?><?php if ($item['last_error']): ?><p class="hint"><?= h($item['last_error']) ?></p><?php endif; ?></td><td><span class="notification-state <?= h($item['state']) ?>"><?= h(ucfirst($item['state'])) ?></span></td><td><?= (int)$item['attempts'] ?></td><td class="nowrap hint"><?= h(local_time($item['created_at'])) ?></td><td><?php if (in_array($item['state'], ['pending', 'failed', 'sending'], true)): ?><form method="post" <?= $item['state'] === 'sending' ? 'data-confirm="The previous delivery outcome may be unknown. Retrying could send a duplicate email. Continue?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="mail_id" value="<?= (int)$item['id'] ?>"><button class="button secondary small" type="submit">Retry</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
