<?php

/** One PDO connection and three small helpers. Tables are created on first use. */
class Db
{
    private static $pdo = null;

    public static function pdo($initializeSchema = true)
    {
        if (self::$pdo === null) {
            $c = Config::get('db');
            self::$pdo = new PDO(
                'mysql:host=' . $c['host'] . ';port=' . (int) ($c['port'] ?? 3306) . ';dbname=' . $c['name'] . ';charset=utf8mb4',
                $c['user'],
                $c['pass'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
            if ($initializeSchema) self::migrate();
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
        if (self::$pdo->query("SHOW TABLES LIKE 'portal_sessions'")->fetch()) {
            return;
        }
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sql');
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $stmt) {
            self::$pdo->exec($stmt);
        }
    }
}

/** Explicit deployment upgrade. Never invoked from a normal web request. */
class GatewayIndexes
{
    public static function definitions()
    {
        return [
            'api_keys' => ['idx_key_merchant' => ['merchant_id', 'status']],
            'devices' => ['idx_dev_active' => ['merchant_id', 'status', 'id']],
            'intents' => [
                'idx_int_match' => ['merchant_id', 'payer_key', 'status', 'amount', 'currency', 'expires_at'],
                'idx_int_reuse' => ['merchant_id', 'reference', 'payer_key', 'status', 'amount', 'expires_at'],
            ],
            'payments' => [
                'idx_pay_feed' => ['merchant_id', 'id'],
                'idx_pay_payer' => ['merchant_id', 'payer_key', 'status', 'kind', 'reversed', 'amount', 'currency', 'received_at'],
                'idx_pay_trxid' => ['merchant_id', 'trx_id', 'kind', 'received_at'],
            ],
            'payers' => ['idx_payer_recent' => ['merchant_id', 'payer_key', 'last_seen']],
            'claims' => ['idx_claim_ip' => ['merchant_id', 'ip', 'ok', 'created_at']],
            'webhook_deliveries' => ['idx_wh_due' => ['status', 'next_attempt_at', 'id']],
        ];
    }

    private static function legacyDefinitions()
    {
        return [
            'idx_key_merchant' => ['merchant_id'],
            'idx_int_match' => ['merchant_id', 'payer_key', 'status', 'expires_at'],
            'idx_pay_payer' => ['merchant_id', 'payer_key'],
            'idx_pay_trxid' => ['merchant_id', 'trx_id'],
            'idx_claim_ip' => ['merchant_id', 'ip', 'created_at'],
            'idx_wh_due' => ['status', 'next_attempt_at'],
        ];
    }

    /** SHOW INDEX rows -> a safe, repeatable ALTER for known gateway indexes. */
    public static function plan($table, array $rows)
    {
        $definitions = self::definitions();
        if (!isset($definitions[$table])) throw new InvalidArgumentException('Unknown gateway table.');
        $actual = [];
        foreach ($rows as $row) {
            $name = $row['Key_name'];
            $actual[$name]['columns'][(int) $row['Seq_in_index']] = $row['Column_name'];
            $actual[$name]['plain'] = ($actual[$name]['plain'] ?? true)
                && (int) $row['Non_unique'] === 1 && $row['Sub_part'] === null
                && strtoupper($row['Index_type']) === 'BTREE'
                && (($row['Collation'] ?? 'A') === 'A');
        }
        foreach ($actual as &$index) {
            ksort($index['columns']);
            $index['columns'] = array_values($index['columns']);
        }
        unset($index);
        $legacy = self::legacyDefinitions();
        $clauses = [];
        foreach ($definitions[$table] as $name => $columns) {
            if (isset($actual[$name])) {
                if ($actual[$name]['plain'] && $actual[$name]['columns'] === $columns) continue;
                if (!$actual[$name]['plain'] || !isset($legacy[$name]) || $actual[$name]['columns'] !== $legacy[$name]) {
                    throw new RuntimeException("$table.$name has a custom definition; review it before upgrading.");
                }
                $clauses[] = 'DROP INDEX `' . $name . '`';
            }
            $clauses[] = 'ADD INDEX `' . $name . '` (`' . implode('`, `', $columns) . '`)';
        }
        return $clauses ? 'ALTER TABLE `' . $table . '` ' . implode(', ', $clauses) . ', ALGORITHM=INPLACE, LOCK=NONE' : null;
    }

    public static function inspect(PDO $pdo)
    {
        $plans = [];
        $engines = $pdo->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (self::definitions() as $table => $definitions) {
            if (strtoupper($engines[$table] ?? '') !== 'INNODB') {
                throw new RuntimeException("$table is missing or is not InnoDB. Point this command at an installed gateway database.");
            }
            $sql = self::plan($table, $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC));
            if ($sql !== null) $plans[$table] = $sql;
        }
        $column = $pdo->query("SHOW FULL COLUMNS FROM payments LIKE 'trx_id'")->fetch(PDO::FETCH_ASSOC);
        if (!$column || !preg_match('/_ci$/i', (string) $column['Collation'])) {
            throw new RuntimeException('payments.trx_id must use a case-insensitive collation for indexed transaction lookup. Review custom collation before upgrading.');
        }
        return $plans;
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
