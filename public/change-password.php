<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$user = App\Auth::requireLogin(true);
$errors = [];
if (is_post()) {
    check_csrf();
    try {
        if (post_string('password') !== post_string('confirm')) throw new App\ValidationException(['The new passwords do not match.']);
        App\Auth::changePassword((int)$user['id'], post_string('current'), post_string('password'));
        flash('Your password has been changed. Sign in with your new password.');
        redirect('/login.php');
    } catch (App\ValidationException $e) { $errors = $e->errors; }
}
$pageTitle = 'Change password';
require APP_ROOT . '/templates/header.php';
?>
<section class="panel auth-panel"><div class="eyebrow">ACCOUNT SECURITY</div><h1>Change password</h1><p class="muted"><?= $user['must_reset'] ? 'Before you continue, replace your temporary password with one of your own.' : 'Choose a new password for your account.' ?></p><?php render_errors($errors); ?><form method="post"><?= csrf_field() ?><div class="field"><label for="current">Current password</label><input type="password" id="current" name="current" required autocomplete="current-password" maxlength="128"></div><div class="field"><label for="password">New password</label><input type="password" id="password" name="password" required minlength="12" maxlength="128" autocomplete="new-password" aria-describedby="password-hint"><p class="hint" id="password-hint">Use at least 12 characters. A memorable phrase works well.</p></div><div class="field"><label for="confirm">Confirm new password</label><input type="password" id="confirm" name="confirm" required minlength="12" maxlength="128" autocomplete="new-password"></div><button class="button" type="submit">Save new password</button></form></section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
