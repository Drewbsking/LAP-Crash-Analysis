<?php
declare(strict_types=1);

function env(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value !== false ? $value : (string)($_ENV[$key] ?? $default);
}
function h(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function now(): string { return gmdate('Y-m-d H:i:s'); }
function app_path(string $path): string
{
    $base = rtrim((string)parse_url(env('APP_URL', 'http://127.0.0.1:8090'), PHP_URL_PATH), '/');
    return $base . '/' . ltrim($path, '/');
}
function app_url(string $path): string { return rtrim(env('APP_URL', 'http://127.0.0.1:8090'), '/') . '/' . ltrim($path, '/'); }
function redirect(string $path): never { header('Location: ' . app_path($path), true, 303); exit; }
function is_post(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">'; }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function check_csrf(): void
{
    if (!is_string($_POST['csrf'] ?? null) || !hash_equals(csrf_token(), $_POST['csrf'])) {
        http_response_code(403);
        exit('This form has expired. Return to the page, refresh it, and try again.');
    }
}
function flash(string $message, string $type = 'success'): void { $_SESSION['flash'][] = [$type, $message]; }
function local_date(?string $value): string
{
    return $value ? (new DateTimeImmutable($value, new DateTimeZone('UTC')))->format('M j, Y') : '—';
}
function local_time(string $value): string
{
    return (new DateTimeImmutable($value, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(env('APP_TIMEZONE', 'America/New_York')))->format('M j, Y · g:i a T');
}
function today(): string { return (new DateTimeImmutable('now', new DateTimeZone(env('APP_TIMEZONE', 'America/New_York'))))->format('Y-m-d'); }
function abort_page(int $status, string $message): never
{
    http_response_code($status);
    $pageTitle = $status === 404 ? 'Project not found' : 'Access restricted';
    require APP_ROOT . '/templates/header.php';
    echo '<section class="panel empty-state"><h1>' . h($pageTitle) . '</h1><p>' . h($message) . '</p><a class="button" href="' . h(app_path('/dashboard.php')) . '">Back to projects</a></section>';
    require APP_ROOT . '/templates/footer.php';
    exit;
}
function post_string(string $key): string { return is_string($_POST[$key] ?? null) ? $_POST[$key] : ''; }
function render_errors(array $errors): void
{
    if (!$errors) return;
    echo '<div class="alert error" role="alert" tabindex="-1" id="form-errors"><strong>Please check the following:</strong><ul>';
    foreach ($errors as $error) echo '<li>' . h($error) . '</li>';
    echo '</ul></div>';
}
function progress(array $project, bool $compact = false): void
{
    $index = array_search($project['status'], array_keys(App\Projects::STATUSES), true);
    echo '<ol class="timeline' . ($compact ? ' compact' : '') . '" aria-label="Review progress">';
    foreach (array_values(App\Projects::STATUSES) as $i => $label) {
        echo '<li class="' . ($i < $index ? 'passed' : ($i === $index ? 'current' : '')) . '"' . ($i === $index ? ' aria-current="step"' : '') . '><span class="step-dot" aria-hidden="true">' . ($i < $index ? '✓' : $i + 1) . '</span><span>' . h($label) . '</span></li>';
    }
    echo '</ol>';
}
