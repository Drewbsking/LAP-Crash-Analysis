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
  <section class="login-intro"><div class="eyebrow">DESIGN + TRAFFIC SAFETY</div><h1>Project details up front.<br>Progress in view.</h1><p>Provide the required project information when you request a crash analysis. See that your request has been submitted and track where it stands, from Entered through Started and Ready for Review to Complete.</p><ol class="login-track"><li>Provide the project details</li><li>Record the request</li><li>Track progress to completion</li></ol></section>
  <section class="login-form"><div class="eyebrow">STAFF PORTAL</div><h2>Welcome back</h2><p class="muted">Sign in to your crash analysis workspace.</p><?php render_errors($errors); ?><form method="post"><?= csrf_field() ?><div class="field"><label for="username">Username</label><input id="username" name="username" autocomplete="username" required maxlength="100" value="<?= h(post_string('username')) ?>" placeholder="Your username"></div><div class="field"><label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required maxlength="128" placeholder="Your password"></div><button class="button" type="submit">Sign in <span aria-hidden="true">→</span></button></form><a class="forgot" href="<?= h(app_path('/forgot-password.php')) ?>">Forgot your password?</a><p class="fine-print">Accounts are provided by your administrator.<br>Contact Andrew Bates if you need access.</p></section>
</div>
<?php require APP_ROOT . '/templates/footer.php'; ?>
