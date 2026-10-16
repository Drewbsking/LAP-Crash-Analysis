<?php
declare(strict_types=1);
if (getenv('APP_ENV') !== 'test') exit(1);
require dirname(__DIR__) . '/app/bootstrap.php';
try {
    App\Auth::resetWithToken($argv[1], 'Concurrent-Setup-Password!');
    echo 'accepted';
} catch (App\ValidationException $e) {
    echo 'rejected';
}
