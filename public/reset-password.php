<?php
require dirname(__DIR__) . '/app/bootstrap.php';
header('Referrer-Policy: no-referrer');
$token = is_post() ? post_string('token') : (is_string($_GET['token'] ?? null) ? $_GET['token'] : '');
$errors = [];
if (is_post()) {
    check_csrf();
    try {
        if (post_string('password') !== post_string('confirm')) throw new App\ValidationException(['The passwords do not match.']);
        App\Auth::resetWithToken($token, post_string('password'));
        flash('Your password has been saved. Sign in with your new password.');
        redirect('/login.php');
    } catch (App\ValidationException $e) { $errors = $e->errors; }
}
$valid = preg_match('/^[a-f0-9]{64}$/', $token) && App\DB::one('SELECT r.id FROM password_resets r JOIN users u ON u.id=r.user_id WHERE r.token_hash=? AND r.used_at IS NULL AND r.expires_at>? AND u.is_active=1', [hash('sha256', $token), now()]);
$pageTitle = 'Set your password';
require APP_ROOT . '/templates/header.php';
?>
<section class="panel auth-panel"><div class="eyebrow">ACCOUNT ACCESS</div><h1>Set your password</h1><?php render_errors($errors); ?><?php if (!$valid): ?><p>This link is invalid or has expired.</p><a class="button" href="<?= h(app_path('/forgot-password.php')) ?>">Request a new link</a><?php else: ?><p class="muted">Choose a new password of at least 12 characters.</p><form method="post" action="<?= h(app_path('/reset-password.php')) ?>"><?= csrf_field() ?><input type="hidden" name="token" value="<?= h($token) ?>"><div class="field"><label for="password">New password</label><input type="password" id="password" name="password" required minlength="12" maxlength="128" autocomplete="new-password"></div><div class="field"><label for="confirm">Confirm new password</label><input type="password" id="confirm" name="confirm" required minlength="12" maxlength="128" autocomplete="new-password"></div><button class="button" type="submit">Save password</button></form><?php endif; ?></section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
