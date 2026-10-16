<?php
declare(strict_types=1);
namespace App;

use PDO;
use Throwable;

final class DB
{
    private static ?PDO $connection = null;
    public static function conn(): PDO
    {
        if (self::$connection) return self::$connection;
        $dsn = \env('APP_DSN', 'sqlite:' . APP_ROOT . '/storage/lap.sqlite');
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, 7);
            if ($path !== ':memory:' && !preg_match('~^(?:[a-zA-Z]:[\\\\/]|/|\\\\\\\\)~', $path)) {
                $dsn = 'sqlite:' . APP_ROOT . '/' . $path;
            }
        }
        if (\env('APP_ENV', 'production') === 'production' && str_starts_with($dsn, 'sqlite:')) {
            throw new \RuntimeException('Production requires a configured MySQL/MariaDB database.');
        }
        self::$connection = new PDO($dsn, \env('APP_DB_USER'), \env('APP_DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        if (self::sqlite()) {
            self::$connection->exec('PRAGMA foreign_keys=ON');
            self::$connection->exec('PRAGMA busy_timeout=10000');
            self::$connection->exec('PRAGMA journal_mode=WAL');
        } else {
            self::$connection->exec("SET time_zone = '+00:00'");
        }
        return self::$connection;
    }
    public static function sqlite(): bool { return self::conn()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite'; }
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::conn()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    public static function one(string $sql, array $params = []): ?array { return self::query($sql, $params)->fetch() ?: null; }
    public static function lock(): string { return self::sqlite() ? '' : ' FOR UPDATE'; }
    public static function transaction(callable $operation): mixed
    {
        $db = self::conn();
        $sqlite = self::sqlite();
        $sqlite ? $db->exec('BEGIN IMMEDIATE') : $db->beginTransaction();
        try {
            $result = $operation();
            $sqlite ? $db->exec('COMMIT') : $db->commit();
            return $result;
        } catch (Throwable $e) {
            $sqlite ? $db->exec('ROLLBACK') : $db->rollBack();
            throw $e;
        }
    }
    public static function install(): void
    {
        $exists = self::sqlite()
            ? self::one("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' LIMIT 1")
            : self::one('SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() LIMIT 1');
        if ($exists) throw new \RuntimeException('Initialization requires an empty dedicated database. Existing data will not be modified.');
        self::executeSchema(file_get_contents(APP_ROOT . '/database/schema.sql'));
        self::migrate();
    }
    /** Add tracking/configuration tables while preserving existing records. */
    public static function migrate(): void
    {
        foreach (['users', 'projects', 'mail_outbox'] as $table) self::query("SELECT id FROM $table LIMIT 0");
        self::executeSchema(file_get_contents(APP_ROOT . '/database/migrations/002-status-reminders.sql'));
        self::executeSchema(file_get_contents(APP_ROOT . '/database/migrations/003-project-contacts.sql'));
        $insert = self::sqlite() ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        foreach (array_keys(ProjectContacts::SLOTS) as $slot) self::query("$insert INTO project_contacts(slot) VALUES (?)", [$slot]);
        ProjectContacts::reviewers(true);
    }
    private static function executeSchema(string $sql): void
    {
        if (self::sqlite()) {
            $sql = str_replace('BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
            $sql = str_replace(['BIGINT UNSIGNED', 'TINYINT(1)', 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'], ['INTEGER', 'INTEGER', ''], $sql);
        }
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') self::conn()->exec($statement);
        }
    }
}
