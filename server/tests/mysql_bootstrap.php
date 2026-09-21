<?php
/**
 * Test database: MySQL, always. The gateway stores everything in MySQL, so its
 * tests run on MySQL too rather than on a different engine that could accept
 * SQL the real database would reject.
 *
 * Each run creates its own throwaway database, uses it, and drops it at the end.
 * It refuses to touch a database that is not clearly a test one, so a mistyped
 * setting can never point a test at live payments.
 *
 * Settings, in order of preference:
 *   GATEWAY_TEST_DSN   e.g. mysql:host=127.0.0.1;port=3306
 *   GATEWAY_TEST_USER  GATEWAY_TEST_PASS  GATEWAY_TEST_DB
 * Defaults suit a local development MySQL.
 */

final class TestDb
{
    private static $pdo;
    private static $name;

    public static function name()
    {
        if (self::$name === null) {
            $given = (string) getenv('GATEWAY_TEST_DB');
            $name = $given !== '' ? $given : 'gateway_test_' . substr(bin2hex(random_bytes(4)), 0, 8);
            if (!preg_match('/^gateway_test_[A-Za-z0-9_]+$/', $name)) {
                fwrite(STDERR, "GATEWAY_TEST_DB must start with gateway_test_ so a live database can never be used.\n");
                exit(1);
            }
            self::$name = $name;
        }
        return self::$name;
    }

    public static function pdo()
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        $dsn = (string) getenv('GATEWAY_TEST_DSN');
        if ($dsn === '') {
            $dsn = 'mysql:host=127.0.0.1;port=3306';
        }
        $user = (string) getenv('GATEWAY_TEST_USER');
        $pass = (string) getenv('GATEWAY_TEST_PASS');
        if ($user === '') {
            $user = 'root';
        }
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
        try {
            $server = new PDO($dsn . ';charset=utf8mb4', $user, $pass, $options);
        } catch (PDOException $e) {
            fwrite(STDERR, "These tests need MySQL. Could not connect with " . $dsn . " as " . $user . ".\n"
                . "Start MySQL, or set GATEWAY_TEST_DSN, GATEWAY_TEST_USER and GATEWAY_TEST_PASS.\n"
                . 'Reason: ' . $e->getMessage() . "\n");
            exit(1);
        }
        $name = self::name();
        $server->exec('DROP DATABASE IF EXISTS `' . $name . '`');
        $server->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        self::$pdo = new PDO($dsn . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, $options);
        register_shutdown_function([self::class, 'drop']);
        return self::$pdo;
    }

    /** Called automatically when the test ends, however it ends. */
    public static function drop()
    {
        if (!self::$pdo) {
            return;
        }
        try {
            self::$pdo->exec('DROP DATABASE IF EXISTS `' . self::name() . '`');
        } catch (Throwable $e) {
            fwrite(STDERR, 'Could not drop the test database ' . self::name() . ': ' . $e->getMessage() . "\n");
        }
        self::$pdo = null;
    }

    /** Is this table present in the test database? Replaces SQLite's sqlite_master. */
    public static function hasTable($table)
    {
        $st = self::pdo()->prepare('SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = ? AND table_name = ?');
        $st->execute([self::name(), $table]);
        return (int) $st->fetch()['c'] > 0;
    }
}
