# Database growth and index upgrades

The gateway uses InnoDB and composite indexes aligned with its actual payment queries. New installations receive these indexes from `schema.sql`. An existing installation must run the explicit upgrade below: normal API requests do not rebuild indexes or scan index metadata.

## Existing installations

Use the gateway's own database configuration, not the ISP billing database. `GATEWAY_CONFIG` can select a separate configuration file; `db.port` is optional and defaults to 3306.

```text
php server/bin/migrate-indexes.php
php server/bin/migrate-indexes.php --apply
```

The first command is read-only: it prints the planned SQL without initializing tables. The second inspects every affected table first, acquires a migration lock, and applies only missing or recognized older gateway indexes. A custom index with a conflicting name stops the upgrade for review. It never deletes payments, tables or unique transaction constraints. The command can be rerun after completion or an interrupted deployment; already-upgraded tables are skipped.

Each table uses a single `ALTER TABLE` with `ALGORITHM=INPLACE, LOCK=NONE`. If the database cannot provide that operation, the command stops rather than falling back to a blocking table copy. A five-second metadata-lock timeout avoids waiting indefinitely behind other work. Online index construction still consumes CPU, disk I/O and temporary space, and it takes brief metadata locks. Keep a verified backup and choose a quieter deployment window for large tables. DDL is committed per table, not as one transaction across the whole upgrade.

## Query coverage

| Table and index | Query served |
| --- | --- |
| `api_keys.idx_key_merchant (merchant_id, status)` | Revoke active keys for one merchant. Authentication retains the unique key-hash index. |
| `devices.idx_dev_active (merchant_id, status, id)` | List active receiving phones in order. The existing merchant and unique device/key indexes remain. |
| `intents.idx_int_match (merchant_id, payer_key, status, amount, currency, expires_at)` | Find eligible purchases using payer, amount, currency and expiry before matching. |
| `intents.idx_int_reuse (merchant_id, reference, payer_key, status, amount, expires_at)` | Reuse a waiting purchase when a page reloads or a customer retries. |
| `payments.idx_pay_feed (merchant_id, id)` | Read a merchant's payment feed with an ID cursor, without sorting its full history. |
| `payments.idx_pay_list (merchant_id, status, id)` | Read matched or unmatched payments with a cursor; retained existing index. |
| `payments.idx_pay_payer (merchant_id, payer_key, status, kind, reversed, amount, currency, received_at)` | Find a recent payment received before the purchase was opened. |
| `payments.idx_pay_trxid (merchant_id, trx_id, kind, received_at)` | Find a claim receipt by transaction ID within its merchant and time window. |
| `payers.idx_payer_recent (merchant_id, payer_key, last_seen)` | Return previously paid references in recent-first order. |
| `claims.idx_claim_ip (merchant_id, ip, ok, created_at)` | Count failed claims in the rate-limit window without scanning successful claims. |
| `webhook_deliveries.idx_wh_due (status, next_attempt_at, id)` | Fetch a bounded batch of oldest-due webhook retries directly from the index. |
| `signups.idx_signup_ip (ip, created_at)` | Count recent registration attempts; retained existing index. |

Primary keys and unique public IDs already cover individual lookups and updates. The unique `(merchant_id, provider, receiving_key, trx_id)` constraint continues to prevent the same receipt being recorded twice. The claim query compares `trx_id` directly instead of applying `UPPER()` to the indexed column. Case-insensitive matching therefore requires the schema's case-insensitive `utf8mb4` collation; the upgrade refuses a custom binary/case-sensitive transaction column rather than silently changing its meaning.

These are targeted indexes rather than an index on every column. Six older narrow indexes are replaced and four are added, limiting unnecessary duplicate indexes. Every index still costs storage and write work. Amount/expiry matching can sort its small candidate set; the unfiltered payment feed and retry backlog do not require a full result sort.

## Verification

```text
php server/tests/security_indexes.php
php server/tests/security_regression.php
```

The planner suite uses no database. The security suite uses isolated in-memory SQLite. The optional real-engine suite requires an explicitly supplied configuration (`GATEWAY_INDEX_TEST_CONFIG`) for an **empty** database whose name begins `gateway_test_`; it refuses the default configuration and non-empty databases:

```text
php server/tests/security_mysql_indexes.php
```

During this change the real-engine suite ran against a separate temporary MariaDB 10.4.28 instance, with PHP 8.2.4. It checked fresh-install indexes, upgraded the original index layout, verified a second run had no work, preserved 160,000 synthetic rows, and verified seven `EXPLAIN` plans selected the intended indexes. This is evidence of query coverage and migration behavior, not a production throughput benchmark.

`security_mysql_payments.php` is a separate optional test that requires `GATEWAY_PAYMENT_TEST_CONFIG` pointing to another empty `gateway_test_*` database. Two PHP processes attempt to match distinct receipts to the same purchase concurrently. On the isolated MariaDB instance, exactly one receipt, one paid purchase and one activation event won; the other receipt remained available for review.

## Further growth work

Indexes do not remove all limits. Measure query latency, lock waits, retry backlog and disk growth with representative production traffic. The retry worker currently handles up to 100 events per invocation and sends them sequentially; higher volume needs more frequent or safely coordinated workers. Raw messages and webhook payloads can dominate storage, so establish retention and archival rules with the business before deleting anything. Review the payment API's response-size limits and avoid unbounded exports. Integer IDs currently use signed `INT`; plan an explicit `BIGINT` migration before any table approaches its capacity. Do not add partitioning or replicas until measured workload justifies their operational cost.

References: [MariaDB online InnoDB index operations](https://mariadb.com/docs/server/server-usage/storage-engines/innodb/innodb-online-ddl/innodb-online-ddl-operations-with-the-inplace-alter-algorithm), [MariaDB ALTER TABLE](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table), [MySQL index optimization](https://dev.mysql.com/doc/refman/8.0/en/optimization-indexes.html).
