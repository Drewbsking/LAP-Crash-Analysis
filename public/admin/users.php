<?php
require dirname(__DIR__, 2) . '/app/bootstrap.php';
$user = App\Auth::requireAdmin();
$errors = [];
if (is_post()) {
    check_csrf();
    try {
        $target = (int)post_string('user_id');
        if (post_string('action') === 'create') {
            $password = App\Auth::temporaryPassword();
            App\Auth::createUser($_POST, $password, (int)$user['id']);
            $_SESSION['temporary_password'] = [post_string('username'), $password];
            flash('Account created. Share the temporary password directly with this person.');
        } elseif (post_string('action') === 'update') {
            App\Auth::updateUser((int)$user['id'], $target, $_POST);
            flash('Account updated.');
        } elseif (post_string('action') === 'reset') {
            $password = App\Auth::adminReset((int)$user['id'], $target);
            $name = App\DB::one('SELECT username FROM users WHERE id=?', [$target])['username'];
            $_SESSION['temporary_password'] = [$name, $password];
            flash('Temporary password created. Existing sessions and reset links have been revoked.');
        } else throw new App\ValidationException(['Unknown account action.']);
        redirect('/admin/users.php');
    } catch (App\ValidationException $e) { $errors = $e->errors; }
}
$users = App\DB::query('SELECT * FROM users ORDER BY full_name')->fetchAll();
$pageTitle = 'People';
require APP_ROOT . '/templates/header.php';
?>
<div class="page-heading"><div><div class="eyebrow">ADMINISTRATION</div><h1>People &amp; access</h1><p class="muted">Create staff accounts and keep access up to date.</p></div></div>
<?php render_errors($errors); ?>
<?php if ($temporary = $_SESSION['temporary_password'] ?? null): unset($_SESSION['temporary_password']); ?><div class="alert success"><strong>Temporary password for <?= h($temporary[0]) ?></strong><code class="secret-display"><?= h($temporary[1]) ?></code>This password is shown once. Share it directly with the account owner; they must replace it at their next sign-in.</div><?php endif; ?>
<div class="admin-grid"><section class="panel"><div class="panel-header"><h2>Staff accounts <span class="count-badge"><?= count($users) ?></span></h2></div><?php foreach ($users as $person): ?><div class="admin-person"><div class="admin-person-header"><div><h3><?= h($person['full_name']) ?></h3><span class="hint"><?= h(App\Auth::ROLES[$person['role']]) ?><?= $person['must_reset'] ? ' · Password change required' : '' ?></span></div><span class="tag"><?= $person['is_active'] ? 'Active' : 'Inactive' ?></span></div><details><summary>Edit account</summary><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><div class="form-grid"><div class="field"><label for="name-<?= (int)$person['id'] ?>">Full name</label><input id="name-<?= (int)$person['id'] ?>" name="full_name" value="<?= h($person['full_name']) ?>" required maxlength="191"></div><div class="field"><label for="username-<?= (int)$person['id'] ?>">Username</label><input id="username-<?= (int)$person['id'] ?>" name="username" value="<?= h($person['username']) ?>" required maxlength="100"></div><div class="field"><label for="email-<?= (int)$person['id'] ?>">Email</label><input type="email" id="email-<?= (int)$person['id'] ?>" name="email" value="<?= h($person['email']) ?>" required maxlength="191"></div><div class="field"><label for="role-<?= (int)$person['id'] ?>">Role</label><select id="role-<?= (int)$person['id'] ?>" name="role"><?php foreach (App\Auth::ROLES as $key => $label): ?><option value="<?= h($key) ?>" <?= $person['role'] === $key ? 'selected' : '' ?>><?= h($label) ?></option><?php endforeach; ?></select></div></div><div class="actions"><label class="check-label"><input type="checkbox" name="is_active" value="1" <?= $person['is_active'] ? 'checked' : '' ?>>Account active</label><button class="button secondary small" type="submit">Save account</button></div></form><?php if ((int)$person['id'] !== (int)$user['id']): ?><form method="post" class="actions" data-confirm="Reset this person’s password and sign them out of all sessions?"><?= csrf_field() ?><input type="hidden" name="action" value="reset"><input type="hidden" name="user_id" value="<?= (int)$person['id'] ?>"><button class="text-button" type="submit">Generate temporary password</button></form><?php endif; ?></details></div><?php endforeach; ?></section>
<aside class="panel panel-pad"><h2>Add a staff member</h2><p class="hint">A temporary password will be generated after you create the account.</p><form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="create"><div class="field"><label for="full_name">Full name</label><input id="full_name" name="full_name" required maxlength="191"></div><div class="field"><label for="username">Username</label><input id="username" name="username" required minlength="3" maxlength="100" pattern="[A-Za-z0-9][A-Za-z0-9._-]{2,99}" autocomplete="off"></div><div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required maxlength="191"></div><div class="field"><label for="role">Role</label><select id="role" name="role"><?php foreach (App\Auth::ROLES as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div><button class="button" type="submit">Create account</button></form><p class="hint">Design submits requests. Analysts manage milestones. Administrators also manage accounts and settings.</p></aside></div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
