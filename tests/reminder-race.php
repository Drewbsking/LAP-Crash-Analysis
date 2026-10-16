<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (env('APP_ENV') !== 'test') throw new RuntimeException('Test environment required.');
if (($argv[1] ?? '') === 'complete') {
    $project = App\Projects::get((int)$argv[3]);
    App\Projects::advance((int)$argv[2], (int)$project['id'], (int)$project['version'], 'complete', true);
    echo 'complete';
} else {
    $result = App\Reminders::queueDue(new DateTimeImmutable($argv[2], new DateTimeZone('UTC')));
    echo $result['queued'];
}
