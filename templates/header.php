<?php
$navUser = App\Auth::user();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Request and track local agency project crash reviews at the Road Commission for Oakland County.">
  <title><?= h($pageTitle ?? 'Projects') ?> · RCOC Crash Analysis</title>
  <link rel="icon" type="image/svg+xml" href="<?= h(app_path('/assets/icon.svg')) ?>">
  <link rel="stylesheet" href="<?= h(app_path('/assets/app.css')) ?>">
  <script src="<?= h(app_path('/assets/app.js')) ?>" defer></script>
</head>
<body>
<a class="skip-link" href="#main">Skip to content</a>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="<?= h(app_path('/dashboard.php')) ?>"><span class="brand-mark" aria-hidden="true">R</span><span><strong>RCOC</strong><small>CRASH ANALYSIS</small></span></a>
    <?php if ($navUser && !$navUser['must_reset']): ?>
    <nav class="main-nav" aria-label="Main navigation">
      <a href="<?= h(app_path('/dashboard.php')) ?>" <?= in_array($currentPage, ['dashboard.php','project.php','request.php']) ? 'aria-current="page"' : '' ?>>Projects</a>
      <?php if ($navUser['role'] === 'admin'): ?>
      <a href="<?= h(app_path('/admin/users.php')) ?>" <?= $currentPage === 'users.php' ? 'aria-current="page"' : '' ?>>People</a>
      <a href="<?= h(app_path('/admin/settings.php')) ?>" <?= $currentPage === 'settings.php' ? 'aria-current="page"' : '' ?>>Notifications</a>
      <?php endif; ?>
    </nav>
    <?php endif; ?>
    <?php if ($navUser): ?>
    <details class="account-menu"><summary><span class="avatar"><?= h(mb_substr($navUser['full_name'], 0, 1)) ?></span><span><?= h($navUser['full_name']) ?></span><span aria-hidden="true">⌄</span></summary><div class="account-popover"><span class="muted"><?= h(App\Auth::ROLES[$navUser['role']]) ?></span><a href="<?= h(app_path('/change-password.php')) ?>">Change password</a><form method="post" action="<?= h(app_path('/logout.php')) ?>"><?= csrf_field() ?><button class="text-button" type="submit">Sign out</button></form></div></details>
    <?php else: ?><span class="header-label">LOCAL AGENCY PROJECTS</span><?php endif; ?>
  </div>
</header>
<?php if (env('APP_ENV', 'production') !== 'production'): ?><div class="environment-banner">Local preview · <?= env('MAIL_TRANSPORT') === 'log' ? 'Email delivery is simulated' : 'Test environment' ?></div><?php endif; ?>
<main id="main" class="container">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $message]): ?><div class="alert <?= h($type) ?>" role="status"><?= h($message) ?></div><?php endforeach; unset($_SESSION['flash']); ?>
