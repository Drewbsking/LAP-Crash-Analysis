<?php
require dirname(__DIR__) . '/app/bootstrap.php';
$sent = false; $errors = [];
if (is_post()) {
    check_csrf();
    if (!filter_var(post_string('email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    else {
        App\Auth::requestReset(post_string('email'), $_SERVER['REMOTE_ADDR'] ?? 'unknown');
        App\Mail::deliverPending();
        $sent = true;
    }
}
$pageTitle = 'Reset password';
require APP_ROOT . '/templates/header.php';
?>
<section class="panel auth-panel"><div class="eyebrow">ACCOUNT ACCESS</div><h1>Forgot password?</h1><p class="muted">Enter the email address associated with your account.</p><?php render_errors($errors); ?><?php if ($sent): ?><div class="alert success" role="status">If an active account matches that address, a reset link has been requested. Check your inbox. If it does not arrive, contact Andrew Bates.</div><?php endif; ?><form method="post"><?= csrf_field() ?><div class="field"><label for="email">Email address</label><input type="email" id="email" name="email" required autocomplete="email" maxlength="191" value="<?= h(post_string('email')) ?>"></div><button class="button" type="submit">Send reset link</button></form><p class="hint"><a href="<?= h(app_path('/login.php')) ?>">Back to sign in</a></p></section>
<?php require APP_ROOT . '/templates/footer.php'; ?>
