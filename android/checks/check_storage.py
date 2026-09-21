"""Exercise the app's own CREATE and ALTER statements against a populated database.

Upgrading must never cost a queued payment message or an activity line, so this
builds a database the way an older version of the app did, puts real rows in it,
then applies exactly the statements onCreate and onUpgrade would run, taken from
the source rather than copied here where they could drift.
"""
from pathlib import Path
import re, sqlite3

root = Path(__file__).resolve().parents[1]
source = root / 'app/src/main/java/com/ispledger/paymentapp/Outbox.java'
s = source.read_text(encoding='utf-8')

create = re.findall(r'db\.execSQL\("(CREATE TABLE[^"\n]+)"\)', s)
alter = re.findall(r'db\.execSQL\("(ALTER TABLE[^"\n]+)"\)', s)
version = int(re.search(r'super\(c,\s*"outbox\.db",\s*null,\s*(\d+)\)', s).group(1))
assert len(create) == 3, create
assert version == 3, version
assert len(alter) == 1, alter

outbox_sql = next(x for x in create if 'TABLE outbox' in x)
activity_sql = next(x for x in create if 'TABLE activity' in x)
stats_sql = next(x for x in create if 'daily_stats' in x)

# --- a version 1 database: outbox and activity only, and activity without detail
c = sqlite3.connect(':memory:')
c.execute(outbox_sql)
c.execute(re.sub(r',\s*detail TEXT', '', activity_sql))
c.execute("INSERT INTO outbox(sender,body,sent_at,sim,attempts) VALUES ('TEST','keep this pending receipt',123,0,2)")
c.execute("INSERT INTO activity(at,line) VALUES (123,'Existing activity')")
before = c.execute('SELECT * FROM outbox').fetchall()

# --- upgrade to 3: add the stats table, then the activity column
c.execute(stats_sql)
for statement in alter:
    c.execute(statement)

assert c.execute('SELECT * FROM outbox').fetchall() == before, 'a queued message was lost'
assert c.execute('SELECT COUNT(*) FROM activity').fetchone()[0] == 1, 'an activity line was lost'
kept, detail = c.execute('SELECT line, detail FROM activity').fetchone()
assert kept == 'Existing activity', kept
assert detail is None or detail == '', 'an existing line must simply have no message text'

# --- and the new column holds a forwarded message
c.execute("INSERT INTO activity(at,line,detail) VALUES (124,'Forwarded from MPESA','UIL0K7XB8J Confirmed. Ksh1.00 ...')")
assert c.execute("SELECT detail FROM activity WHERE at=124").fetchone()[0].startswith('UIL0K7XB8J')

# --- running the upgrade twice must not lose anything either
c.execute(stats_sql)
c.execute("INSERT INTO daily_stats(day,delivered) VALUES ('2026-09-21',3)")
assert c.execute("SELECT delivered,retries FROM daily_stats WHERE day='2026-09-21'").fetchone() == (3, 0)
assert c.execute('SELECT COUNT(*) FROM activity').fetchone()[0] == 2

plan = c.execute("EXPLAIN QUERY PLAN SELECT delivered,retries FROM daily_stats WHERE day='2026-09-21'").fetchone()[3]
assert 'INDEX' in plan.upper(), plan
queue_plan = c.execute('EXPLAIN QUERY PLAN SELECT * FROM outbox ORDER BY id LIMIT 25').fetchall()
assert not any('TEMP B-TREE' in row[3].upper() for row in queue_plan)

print('SQLite upgrade to version 3 keeps queued receipts and activity, stores the forwarded message, and reads by index.')
