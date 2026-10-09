<?php
require dirname(__DIR__) . '/app/bootstrap.php';
if (App\Auth::user()) redirect('/dashboard.php');
$errors = [];
if (is_post()) {
    check_csrf();
    if (App\Auth::login(post_string('username'), post_string('password'), $_SERVER['REMOTE_ADDR'] ?? 'unknown')) redirect('/dashboard.php');
    $errors[] = 'Unable to sign in. Check your username and password. After repeated attempts, wait 15 minutes before trying again.';
}
$pageTitle = 'Sign in';
require APP_ROOT . '/templates/header.php';
?>
<div class="login-layout">
  <section class="login-intro"><div class="eyebrow">DESIGN + TRAFFIC SAFETY</div><h1>A clearer path<br>from request<br>to review.</h1><p>One place to request crash analyses, follow project progress, and keep the next step in view.</p><ol class="login-track"><li>Enter your project</li><li>Follow the analysis</li><li>Review and complete</li></ol></section>
  <section class="login-form"><div class="eyebrow">STAFF PORTAL</div><h2>Welcome back</h2><p class="muted">Sign in to your crash analysis workspace.</p><?php render_errors($errors); ?><form method="post"><?= csrf_field() ?><div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required maxlength="100" value="<?= h(post_string('username')) ?>" placeholder="Your username"></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128" placeholder="Your password"></div><button class="button" type="submit">Sign in <span aria-hidden="true">→</span></button></form><a class="forgot" href="<?= h(app_path('/forgot-password.php')) ?>">Forgot your password?</a><p class="fine-print">Accounts are provided by your administrator.<br>Contact Andrew Bates if you need access.</p></section>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
