<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
if (!is_file(APP_ROOT . '/vendor/autoload.php')) {
    http_response_code(503);
    exit('Application dependencies are missing. Run composer install before starting the portal.');
}
require APP_ROOT . '/vendor/autoload.php';
Dotenv\Dotenv::createImmutable(APP_ROOT)->safeLoad();
require __DIR__ . '/helpers.php';
date_default_timezone_set('UTC');
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('zend.exception_ignore_args', '1');
ini_set('error_log', APP_ROOT . '/storage/app.log');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e): void {
    error_log((string)$e);
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Operation failed: ' . $e->getMessage() . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Unable to load page</title><h1>Unable to load this page</h1><p>Please try again. If the problem continues, contact your administrator.</p></html>';
});
if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; base-uri 'none'; frame-ancestors 'none'; form-action 'self'");
    if (env('APP_ENV', 'production') === 'production') {
        header('Strict-Transport-Security: max-age=31536000');
    }
    session_name('LAPSESSION');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(max(1, (int)env('SESSION_MINUTES', '120')) * 60));
    session_set_cookie_params([
        'httponly' => true, 'samesite' => 'Lax', 'path' => app_path('/') ?: '/',
        'secure' => env('APP_ENV', 'production') === 'production' || !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}
