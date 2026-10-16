<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = App\Auth::requireAdmin();
$errors = [];
if (is_post()) {
    check_csrf();
    try {
        if (post_string('action') === 'stakeholders') {
            App\DB::transaction(function () use ($user): void {
                App\Auth::actor((int)$user['id'], ['admin']);
                $people = App\DB::query('SELECT * FROM stakeholders ORDER BY id' . App\DB::lock())->fetchAll();
                foreach ($people as $person) {
                    $email = strtolower(trim(post_string('email_' . $person['id'])));
                    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 191) throw new App\ValidationException(['Enter a valid email address for ' . $person['full_name'] . '.']);
                    App\DB::query('UPDATE stakeholders SET email=? WHERE id=?', [$email, $person['id']]);
                }
            });
            flash('Stakeholder email addresses saved. Future milestone emails will use these addresses.');
        } elseif (post_string('action') === 'retry') {
            $sent = App\Mail::retry((int)$user['id'], (int)post_string('mail_id'));
            flash($sent ? 'Email delivery completed.' : 'Email was not sent. Check its delivery status and SMTP settings.', $sent ? 'success' : 'warning');
        } else throw new App\ValidationException(['Unknown notification action.']);
        redirect('/admin/settings.php');
    } catch (App\ValidationException $e) { $errors = $e->errors; }
}
$people = App\DB::query('SELECT * FROM stakeholders ORDER BY id')->fetchAll();
$mail = App\DB::query("SELECT m.*, e.project_id FROM mail_outbox m LEFT JOIN project_events e ON e.id=m.event_id ORDER BY CASE WHEN m.state='sent' THEN 1 ELSE 0 END, m.id DESC LIMIT 100")->fetchAll();
$pageTitle = 'Notifications';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-heading"><div><div class="eyebrow">ADMINISTRATION</div><h1>Notifications</h1><p class="muted">Keep the right people informed at every milestone.</p></div></div><?php render_errors($errors); ?>
<section class="panel panel-pad"><h2>Milestone recipients</h2><p class="muted">These four stakeholders and the requester receive an email when a project is Entered, Started, Ready for Review, or Complete.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="stakeholders"><div class="form-grid"><?php foreach ($people as $person): ?><div class="field"><label for="email_<?= (int)$person['id'] ?>"><?= h($person['full_name']) ?> · <?= h($person['department']) ?></label><input type="email" id="email_<?= (int)$person['id'] ?>" name="email_<?= (int)$person['id'] ?>" maxlength="191" required value="<?= h(is_post() && post_string('action') === 'stakeholders' ? post_string('email_' . $person['id']) : $person['email']) ?>" placeholder="Enter their work email"></div><?php endforeach; ?></div><div class="form-actions"><span class="hint">Repeated email addresses receive a single notification.</span><button class="button" type="submit">Save recipients</button></div></form></section>
<section class="panel" id="delivery"><div class="panel-header"><h2>Email delivery</h2><span class="hint">Latest 100 · Unsent first</span></div><?php if (!$mail): ?><div class="empty-state"><h3>No notifications yet</h3><p>Welcome, milestone, and password reset emails will appear here.</p></div><?php else: ?><div class="table-scroll"><table class="admin-table"><thead><tr><th>Recipient / message</th><th>Status</th><th>Attempts</th><th>Created</th><th>Action</th></tr></thead><tbody><?php foreach ($mail as $item): ?><tr><td><strong><?= h($item['recipient_name']) ?></strong><span class="project-meta"><?= h($item['recipient']) ?></span><span class="project-meta"><?= h($item['subject']) ?></span><?php if ($item['project_id']): ?><a class="hint" href="<?= h(app_path('/project.php?id=' . $item['project_id'])) ?>">View project</a><?php endif; ?><?php if ($item['last_error']): ?><p class="hint"><?= h($item['last_error']) ?></p><?php endif; ?></td><td><span class="notification-state <?= h($item['state']) ?>"><?= h(ucfirst($item['state'])) ?></span></td><td><?= (int)$item['attempts'] ?></td><td class="nowrap hint"><?= h(local_time($item['created_at'])) ?></td><td><?php if ($item['state'] !== 'sent'): ?><form method="post" <?= $item['state'] === 'sending' ? 'data-confirm="The previous delivery outcome may be unknown. Retrying could send a duplicate email. Continue?"' : '' ?>><?= csrf_field() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="mail_id" value="<?= (int)$item['id'] ?>"><button class="button secondary small" type="submit">Retry</button></form><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
