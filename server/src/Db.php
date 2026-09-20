<?php

/** One PDO connection and three small helpers. Tables are created on first use. */
class Db
{
    private static $pdo = null;

    public static function pdo()
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            self::$pdo = new PDO(
                'mysql:host=' . $c['host'] . ';dbname=' . $c['name'] . ';charset=utf8mb4',
                $c['user'],
                $c['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            self::migrate();
        }
        return self::$pdo;
    }

    public static function row($sql, array $args = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        $r = $st->fetch();
        return $r ? $r : null;
    }

    public static function rows($sql, array $args = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->fetchAll();
    }

    public static function run($sql, array $args = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($args);
        return $st->rowCount();
    }

    public static function lastId()
    {
        return (int) self::pdo()->lastInsertId();
    }

    /**
     * Nothing in this service deletes a payment. A payment that has not
     * reached its merchant yet is the only proof that somebody paid.
     */
    private static function migrate()
    {
        // The last table in schema.sql doubles as the "already installed" marker.
        if (self::$pdo->query("SHOW TABLES LIKE 'signups'")->fetch()) {
            return;
        }
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            self::$pdo->exec($stmt);
        }
    }
}

class Config
{
    private static $data = null;

    public static function get($key, $default = null)
    {
        if (self::$data === null) {
            $file = getenv('GATEWAY_CONFIG') ?: dirname(__DIR__) . '/config.php';
            if (!is_file($file)) {
                http_response_code(500);
                exit(json_encode(['error' => ['code' => 'not_configured', 'message' => 'Copy config.sample.php to config.php']]));
            }
            self::$data = require $file;
        }
        return array_key_exists($key, self::$data) ? self::$data[$key] : $default;
    }
}
