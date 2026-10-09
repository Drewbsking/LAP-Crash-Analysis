<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$user = App\Auth::requireLogin();
$id = (int)($_GET['id'] ?? 0);
$project = $id ? App\Projects::get($id) : null;
if ($id && !$project) abort_page(404, 'That project could not be found.');
if ($project ? !App\Projects::canEdit($user, $project) : !in_array($user['role'], ['design','admin'], true)) abort_page(403, 'Your account cannot edit this request.');
$values = $project ?? [];
if ($project) $values['safety_improvements'] = json_decode($project['safety_improvements'], true);
$errors = []; $existingId = null;
$token = is_post() ? post_string('submission_token') : bin2hex(random_bytes(32));
$version = is_post() ? (int)post_string('version') : (int)($project['version'] ?? 0);
if (is_post()) {
    check_csrf();
    $values = $_POST;
    try {
        if ($project) App\Projects::update((int)$user['id'], $id, $version, $_POST);
        else $id = App\Projects::create((int)$user['id'], $_POST, $token);
        App\Mail::deliverPending();
        flash($project ? 'Project details updated.' : 'Your crash analysis request has been submitted.');
        redirect('/project.php?id=' . $id);
    } catch (App\ValidationException $e) { $errors = $e->errors; $existingId = $e->existingProjectId; }
}
$value = fn(string $key): string => is_scalar($values[$key] ?? null) ? (string)$values[$key] : '';
$selectedSafety = is_array($values['safety_improvements'] ?? null) ? $values['safety_improvements'] : [];
$pageTitle = $project ? 'Edit request' : 'New request';
require APP_ROOT . '/templates/header.php';
?>
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= h(app_path('/dashboard.php')) ?>">Projects</a><span aria-hidden="true">/</span><span><?= h($pageTitle) ?></span></nav>
<div class="page-heading"><div><div class="eyebrow">CRASH REVIEW REQUEST</div><h1><?= $project ? 'Edit project details' : 'Request a crash analysis' ?></h1><p class="muted">Give Traffic Safety the project context and the dates to work toward.</p></div></div>
<?php render_errors($errors); ?><?php if ($existingId): ?><div class="alert"><a href="<?= h(app_path('/project.php?id=' . $existingId)) ?>">Open the existing crash analysis request →</a></div><?php endif; ?>
<div class="form-layout"><form method="post" class="panel form-main"><?= csrf_field() ?><input type="hidden" name="submission_token" value="<?= h($token) ?>"><input type="hidden" name="version" value="<?= $version ?>">
  <section class="form-section"><div class="section-title"><span class="section-number">01</span><h2>Project &amp; schedule</h2></div><div class="form-grid">
    <div class="field full"><label for="name">Project name</label><input id="name" name="name" required maxlength="200" value="<?= h($value('name')) ?>" placeholder="e.g., Maple Road rehabilitation"></div>
    <div class="field"><label for="rcoc_number">RCOC project number</label><input id="rcoc_number" name="rcoc_number" required maxlength="100" value="<?= h($value('rcoc_number')) ?>" placeholder="e.g., 58001"><p class="hint">One crash analysis per RCOC project.</p></div>
    <div class="field"><label for="mdot_number">MDOT job number</label><input id="mdot_number" name="mdot_number" required maxlength="100" value="<?= h($value('mdot_number')) ?>" placeholder="Enter the MDOT job number"></div>
    <div class="field"><label for="needed_by">Analysis needed by</label><input type="date" id="needed_by" name="needed_by" required min="1900-01-01" max="2200-12-31" value="<?= h($value('needed_by')) ?>" aria-describedby="needed-hint"><p class="hint" id="needed-hint">Allow time for Bates’ review and the final signature.</p></div>
    <div class="field"><label for="bid_date">Current bid letting date</label><input type="date" id="bid_date" name="bid_date" required min="1900-01-01" max="2200-12-31" value="<?= h($value('bid_date')) ?>"></div>
    <div class="field"><label for="construction_year">Construction year</label><input type="number" id="construction_year" name="construction_year" required min="1900" max="2200" step="1" value="<?= h($value('construction_year')) ?>" placeholder="e.g., 2027"></div>
    <div class="field"><label for="project_type">Project type</label><select id="project_type" name="project_type" required><option value="">Select project type</option><?php foreach (App\Projects::TYPES as $type): ?><option <?= $value('project_type') === $type ? 'selected' : '' ?>><?= h($type) ?></option><?php endforeach; ?></select></div>
    <div class="field full" data-conditional="project_type" data-value="Other"><label for="type_details">Describe the project type</label><input id="type_details" name="type_details" maxlength="5000" data-required value="<?= h($value('type_details')) ?>"></div>
  </div></section>
  <section class="form-section"><div class="section-title"><span class="section-number">02</span><h2>Scope &amp; design</h2></div><div class="form-grid">
    <div class="field full"><label for="scope">Brief project scope</label><textarea id="scope" name="scope" required maxlength="10000" rows="4" placeholder="Describe the proposed work and its purpose."><?= h($value('scope')) ?></textarea></div>
    <div class="field full"><label for="limits_text">Project limits</label><textarea id="limits_text" name="limits_text" required maxlength="3000" rows="2" placeholder="Road name, beginning and ending limits, and any relevant intersections."><?= h($value('limits_text')) ?></textarea></div>
    <fieldset class="field full"><legend>Is there a design exception?</legend><div class="radio-group"><label class="radio-label"><input type="radio" name="design_exception" value="0" required <?= $value('design_exception') === '0' ? 'checked' : '' ?>> No</label><label class="radio-label"><input type="radio" name="design_exception" value="1" required <?= $value('design_exception') === '1' ? 'checked' : '' ?>> Yes</label></div></fieldset>
    <div class="field full" data-conditional="design_exception" data-value="1"><label for="exception_details">Describe the design exception</label><textarea id="exception_details" name="exception_details" maxlength="5000" rows="3" data-required><?= h($value('exception_details')) ?></textarea></div>
  </div></section>
  <section class="form-section"><div class="section-title"><span class="section-number">03</span><h2>Proposed safety improvements</h2></div><fieldset><legend>Select all that apply, or select None.</legend><div class="checkbox-grid"><?php foreach (App\Projects::SAFETY as $item): ?><label class="check-label"><input type="checkbox" name="safety_improvements[]" value="<?= h($item) ?>" <?= in_array($item, $selectedSafety, true) ? 'checked' : '' ?>><?= h($item) ?></label><?php endforeach; ?></div></fieldset><div class="field" data-conditional="safety_improvements[]" data-value="Other"><label for="safety_details">Describe the other safety improvements</label><textarea id="safety_details" name="safety_details" maxlength="5000" rows="3" data-required><?= h($value('safety_details')) ?></textarea></div></section>
  <div class="form-actions"><a href="<?= h(app_path($project ? '/project.php?id=' . $id : '/dashboard.php')) ?>">Cancel</a><button class="button" type="submit"><?= $project ? 'Save changes' : 'Submit request' ?> <span aria-hidden="true">→</span></button></div>
</form><aside class="form-aside"><h3><?= $project ? 'Keep everyone up to date' : 'What happens next' ?></h3><p><?= $project ? 'Changes are recorded in the project history so the team can see what was updated.' : 'Submitting your request starts the review and notifies Design and Traffic Safety.' ?></p><ol><li><strong>Entered</strong><br>Your request is in the queue.</li><li><strong>Started</strong><br>Jeremy adds the analysis folder.</li><li><strong>Ready for Review</strong><br>The analysis is ready for Bates.</li><li><strong>Complete</strong><br>Review and signing are finished.</li></ol><hr><p class="aside-extra">All project information is required. Select No or None where appropriate.</p></aside></div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
