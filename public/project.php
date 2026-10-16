<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$user = App\Auth::requireLogin();
$id = (int)($_GET['id'] ?? 0);
$project = App\Projects::get($id);
if (!$project) abort_page(404, 'That project could not be found.');
$errors = [];
if (is_post()) {
    check_csrf();
    try {
        $version = (int)post_string('version');
        if (post_string('action') === 'folder') App\Projects::folder((int)$user['id'], $id, $version, post_string('folder_path'));
        elseif (post_string('action') === 'advance') App\Projects::advance((int)$user['id'], $id, $version, post_string('target'), post_string('confirmed') === '1');
        else throw new App\ValidationException(['Unknown project action.']);
        App\Mail::deliverPending();
        flash('Project updated.');
        redirect('/project.php?id=' . $id);
    } catch (App\ValidationException $e) { $errors = $e->errors; }
    $project = App\Projects::get($id);
}
$events = App\DB::query('SELECT e.*, u.full_name AS actor_name FROM project_events e JOIN users u ON u.id=e.actor_id WHERE e.project_id=? ORDER BY e.id DESC', [$id])->fetchAll();
$failedMail = (int)App\DB::query("SELECT COUNT(*) FROM mail_outbox m JOIN project_events e ON e.id=m.event_id WHERE e.project_id=? AND m.state<>'sent'", [$id])->fetchColumn();
if (App\Reminders::installed()) {
    $failedMail += (int)App\DB::query("SELECT COUNT(*) FROM mail_outbox m JOIN reminder_deliveries d ON d.mail_id=m.id JOIN project_reminders r ON r.id=d.reminder_id WHERE r.project_id=? AND m.state IN ('pending','failed','sending')", [$id])->fetchColumn();
}
$pageTitle = $project['name'];
$displayChange = function (string $key, mixed $value): string {
    if ($key === 'safety_improvements') return implode(', ', json_decode((string)$value, true) ?? []);
    if ($key === 'design_exception') return (int)$value ? 'Yes' : 'No';
    return $value === '' || $value === null ? '(empty)' : (string)$value;
};
require APP_ROOT . '/templates/header.php';
?>
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(app_path('/dashboard.php')) ?>">Projects</a><span aria-hidden="true">/</span><span>RCOC <?= h($project['rcoc_number']) ?></span></nav>
<div class="page-heading"><div><div class="eyebrow">PROJECT REVIEW</div><h1><?= h($project['name']) ?></h1><p class="muted">RCOC <?= h($project['rcoc_number']) ?> <span aria-hidden="true">·</span> MDOT <?= h($project['mdot_number']) ?> <span aria-hidden="true">·</span> Requested by <?= h($project['requester_name']) ?></p></div><?php if (App\Projects::canEdit($user, $project)): ?><div class="heading-actions"><a class="button secondary" href="<?= h(app_path('/request.php?id=' . $id)) ?>">Edit details</a></div><?php endif; ?></div>
<?php render_errors($errors); ?>
<?php if ($failedMail): ?><div class="alert warning">The project is saved. <?= $failedMail ?> notification<?= $failedMail === 1 ? ' is' : 's are' ?> awaiting delivery. <?php if ($user['role'] === 'admin'): ?><a href="<?= h(app_path('/admin/settings.php#delivery')) ?>">Review email delivery</a><?php else: ?>Your administrator can review email delivery.<?php endif; ?></div><?php endif; ?>
<section class="panel progress-panel" aria-label="Project status and deadline"><?php progress($project); ?><div class="deadline <?= App\Projects::overdue($project) ? 'overdue' : '' ?>"><small>Signed &amp; complete by</small><strong><?= h(local_date($project['needed_by'])) ?></strong><?php if (App\Projects::overdue($project)): ?><span class="due-flag">Overdue · awaiting completion</span><?php elseif ($project['status'] === 'complete'): ?><span class="hint">All milestones complete</span><?php endif; ?></div></section>
<div class="detail-grid"><div>
  <section class="panel"><div class="panel-header"><h2>Project information</h2><span class="tag"><?= h($project['construction_year']) ?> construction</span></div><div class="panel-pad"><dl class="facts"><div><dt>Project type</dt><dd><?= h($project['project_type'] === 'Other' ? $project['type_details'] : $project['project_type']) ?></dd></div><div><dt>Bid letting date</dt><dd><?= h(local_date($project['bid_date'])) ?></dd></div><div class="full"><dt>Project scope</dt><dd><?= h($project['scope']) ?></dd></div><div class="full"><dt>Project limits</dt><dd><?= h($project['limits_text']) ?></dd></div><div class="full"><dt>Design exception</dt><dd><?= $project['design_exception'] ? 'Yes — ' . h($project['exception_details']) : 'No design exception' ?></dd></div><div class="full"><dt>Proposed safety improvements</dt><dd><span class="tag-list"><?php foreach (json_decode($project['safety_improvements'], true) as $item): ?><span class="tag"><?= h($item) ?></span><?php endforeach; ?></span><?php if ($project['safety_details']): ?><p class="hint"><?= h($project['safety_details']) ?></p><?php endif; ?></dd></div></dl></div></section>
  <section class="panel"><div class="panel-header"><h2>Project history</h2><span class="muted hint">Eastern time</span></div><div class="panel-pad"><ol class="history-list"><?php foreach ($events as $event): ?><li><strong><?= h($event['event_type'] === 'milestone' ? App\Projects::STATUSES[$event['to_status']] : ($event['event_type'] === 'folder_updated' ? 'Analysis folder updated' : 'Project details updated')) ?></strong><div class="history-meta"><?= h($event['actor_name']) ?> <span aria-hidden="true">·</span> <?= h(local_time($event['created_at'])) ?></div><?php $changes = json_decode($event['changes_json'], true); if ($changes): ?><details><summary>View <?= count($changes) ?> change<?= count($changes) === 1 ? '' : 's' ?></summary><ul class="change-list"><?php foreach ($changes as $key => $change): ?><li><strong><?= h(App\Projects::FIELDS[$key] ?? $key) ?></strong><span class="before">Before: <?= h($displayChange($key, $change['before'])) ?></span><span class="after">After: <?= h($displayChange($key, $change['after'])) ?></span></li><?php endforeach; ?></ul></details><?php endif; ?></li><?php endforeach; ?></ol></div></section>
</div><aside>
  <section class="panel panel-pad workflow-panel"><span class="status-label <?= h($project['status']) ?>"><?= h(App\Projects::STATUSES[$project['status']]) ?></span>
  <?php if ($project['status'] === 'complete'): ?><h2>Review complete</h2><p>Review and external signing have been confirmed. This project is now read-only.</p>
  <?php elseif (!App\Projects::canReview($user)): ?><h2>What happens next</h2><p><?= h(match ($project['status']) { 'entered' => 'Jeremy will add the analysis folder when work begins.', 'started' => 'Jeremy is preparing the analysis. The next step is Bates’ review.', default => 'The analysis is with Bates for review and external signing.' }) ?></p>
  <?php elseif ($project['status'] === 'entered'): ?><h2>Start the analysis</h2><p>Add the folder location below to move this request to Started.</p>
  <?php else: $target = $project['status'] === 'started' ? 'ready_for_review' : 'complete'; ?><h2><?= $target === 'complete' ? 'Finish the review' : 'Ready for Bates?' ?></h2><p><?= $target === 'complete' ? 'Once review and signing in OneSpan are finished, confirm completion here.' : 'Mark the analysis ready when Jeremy’s work is prepared for Bates’ review.' ?></p><form method="post" <?= $target === 'complete' ? 'data-confirm="Confirm that review and external signing are finished? Complete projects are read-only."' : '' ?>><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int)$project['version'] ?>"><input type="hidden" name="action" value="advance"><input type="hidden" name="target" value="<?= h($target) ?>"><label class="check-label"><input type="checkbox" name="confirmed" value="1" required><?= $target === 'complete' ? 'Review and external signing are complete.' : 'The analysis is ready for review.' ?></label><button class="button" type="submit"><?= $target === 'complete' ? 'Mark complete' : 'Mark ready for review' ?></button></form><?php endif; ?>
  </section>
  <section class="panel panel-pad"><h3>Analysis folder</h3><?php if ($project['folder_path']): ?><code class="folder-path" id="folder-path"><?= h($project['folder_path']) ?></code><button type="button" class="button secondary small" data-copy="folder-path">Copy path</button><p class="hint" id="copy-status" role="status" aria-live="polite">Paste this path into Windows File Explorer.</p><?php else: ?><p class="muted hint">A folder has not been provided yet.</p><?php endif; ?>
  <?php if (App\Projects::canReview($user) && $project['status'] !== 'complete'): ?><form method="post"><?= csrf_field() ?><input type="hidden" name="version" value="<?= (int)$project['version'] ?>"><input type="hidden" name="action" value="folder"><div class="field"><label for="folder_path"><?= $project['folder_path'] ? 'Update folder location' : 'Folder location' ?></label><input id="folder_path" name="folder_path" required maxlength="1000" placeholder="G:\A TOC\Project folder" value="<?= h($project['folder_path']) ?>" autocomplete="off"></div><button class="button secondary small" type="submit"><?= $project['status'] === 'entered' ? 'Save folder & start' : 'Save folder' ?></button></form><?php endif; ?>
  </section>
  <p class="hint">Submitted <?= h(local_time($project['created_at'])) ?><br>Last updated <?= h(local_time($project['updated_at'])) ?></p>
</aside></div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
