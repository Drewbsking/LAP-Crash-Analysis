<?php
declare(strict_types=1);
$root = dirname(__DIR__);
if (!is_dir($root . '/test-results')) mkdir($root . '/test-results', 0700, true);
putenv('APP_ENV=test');
putenv('APP_DSN=sqlite:' . $root . '/test-results/initialization-' . bin2hex(random_bytes(6)) . '.sqlite');
require $root . '/app/bootstrap.php';
App\DB::conn()->exec('CREATE TABLE unrelated_existing_data (id INTEGER PRIMARY KEY, note TEXT)');
App\DB::query('INSERT INTO unrelated_existing_data(id, note) VALUES (1, ?)', ['preserve me']);
try {
    App\DB::install();
    throw new RuntimeException('Initialization incorrectly accepted an occupied database.');
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'empty dedicated database')) throw $e;
}
if (App\DB::query('SELECT note FROM unrelated_existing_data WHERE id=1')->fetchColumn() !== 'preserve me') throw new RuntimeException('Existing data changed.');
if (App\DB::one("SELECT name FROM sqlite_master WHERE type='table' AND name='users'")) throw new RuntimeException('Portal tables were created unexpectedly.');
echo "PASS Initialization rejects unrelated existing tables without modifying them.\n";
