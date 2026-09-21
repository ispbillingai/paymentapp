"""Exercise the app's actual CREATE statements against a populated v1 SQLite database."""
from pathlib import Path
import re, sqlite3
root=Path(__file__).resolve().parents[1]
source=root/'app/src/main/java/com/ispledger/paymentapp/Outbox.java'
s=source.read_text(encoding='utf-8')
sql=re.findall(r'db\.execSQL\("(CREATE TABLE[^"\n]+)"\)',s)
assert len(sql)==3
c=sqlite3.connect(':memory:')
for statement in sql[:2]:c.execute(statement)
c.execute("INSERT INTO outbox(sender,body,sent_at,sim,attempts) VALUES ('TEST','keep this pending receipt',123,0,2)")
c.execute("INSERT INTO activity(at,line) VALUES (123,'Existing activity')")
before=c.execute('SELECT * FROM outbox').fetchall()
c.execute(sql[2])
assert c.execute('SELECT * FROM outbox').fetchall()==before
assert c.execute('SELECT COUNT(*) FROM activity').fetchone()[0]==1
c.execute(sql[2])
c.execute("INSERT INTO daily_stats(day,delivered) VALUES ('2026-09-21',3)")
assert c.execute("SELECT delivered,retries FROM daily_stats WHERE day='2026-09-21'").fetchone()==(3,0)
plan=c.execute("EXPLAIN QUERY PLAN SELECT delivered,retries FROM daily_stats WHERE day='2026-09-21'").fetchone()[3]
assert 'INDEX' in plan.upper(),plan
queue_plan=c.execute('EXPLAIN QUERY PLAN SELECT * FROM outbox ORDER BY id LIMIT 25').fetchall()
assert not any('TEMP B-TREE' in row[3].upper() for row in queue_plan)
print('SQLite migration preserves queued receipts and activity; indexed daily lookup and FIFO order verified.')
