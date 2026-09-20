<?php
/** Pure migration-planner tests; never opens a database. */
require dirname(__DIR__) . '/src/Db.php';
$checks = 0;
function checkIndex($condition, $message) {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function indexRows($name, array $columns, $unique = false) {
    $rows = [];
    foreach ($columns as $offset => $column) $rows[] = ['Key_name' => $name, 'Seq_in_index' => $offset + 1,
        'Column_name' => $column, 'Non_unique' => $unique ? 0 : 1, 'Sub_part' => null, 'Index_type' => 'BTREE', 'Collation' => 'A'];
    return $rows;
}
$schema = file_get_contents(dirname(__DIR__) . '/schema.sql');
foreach (GatewayIndexes::definitions() as $table => $indexes) {
    $rows = [];
    preg_match('/CREATE TABLE IF NOT EXISTS ' . $table . ' \((.*?)\) ENGINE=/s', $schema, $section);
    checkIndex(isset($section[1]), 'Missing schema table ' . $table);
    foreach ($indexes as $name => $columns) {
        checkIndex(strpos($section[1], 'KEY ' . $name . ' (' . implode(', ', $columns) . ')') !== false, 'Schema and upgrade disagree for ' . $name);
        $rows = array_merge($rows, indexRows($name, $columns));
    }
    checkIndex(GatewayIndexes::plan($table, $rows) === null, 'Up-to-date table should be a no-op.');
    $plan = GatewayIndexes::plan($table, []);
    checkIndex(strpos($plan, 'ALGORITHM=INPLACE, LOCK=NONE') !== false, 'Migration must not silently choose table-copy locking.');
    checkIndex(strpos($plan, 'DROP') === false, 'Missing indexes must not delete other indexes.');
}
$legacy = GatewayIndexes::plan('claims', indexRows('idx_claim_ip', ['merchant_id', 'ip', 'created_at']));
checkIndex(strpos($legacy, 'DROP INDEX `idx_claim_ip`') !== false && strpos($legacy, '`merchant_id`, `ip`, `ok`, `created_at`') !== false, 'Known old index must be replaced in one statement.');
foreach ([indexRows('idx_claim_ip', ['ip']), indexRows('idx_claim_ip', ['merchant_id', 'ip', 'created_at'], true)] as $rows) {
    try { GatewayIndexes::plan('claims', $rows); checkIndex(false, 'Custom index must not be destroyed.'); }
    catch (RuntimeException $e) { checkIndex(strpos($e->getMessage(), 'custom definition') !== false, 'Unexpected planner error.'); }
}
try { GatewayIndexes::plan('payments; DROP TABLE merchants', []); checkIndex(false, 'Unknown identifier accepted.'); }
catch (InvalidArgumentException $e) { checkIndex(true, 'Known tables only.'); }
echo "PASS: $checks index-planner checks; no database connection.\n";
