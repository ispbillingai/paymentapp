<?php
require __DIR__ . '/mysql_bootstrap.php';
date_default_timezone_set('UTC');
class Db {
    private static $db;
    public static function pdo(){return self::$db??(self::$db=TestDb::pdo());}
    private static function statement($sql,$args){$s=self::pdo()->prepare($sql);$s->execute($args);return $s;}
    public static function row($sql,$args=[]){return self::statement($sql,$args)->fetch()?:null;}
    public static function rows($sql,$args=[]){return self::statement($sql,$args)->fetchAll();}
    public static function run($sql,$args=[]){return self::statement($sql,$args)->rowCount();}
}
require __DIR__.'/../src/Sandbox.php';
foreach ([
"CREATE TABLE portal_users(id INT PRIMARY KEY,status VARCHAR(12)) ENGINE=InnoDB",
"INSERT INTO portal_users VALUES(1,'active'),(2,'active')",
"CREATE TABLE sandbox_accounts(user_id INT PRIMARY KEY,public_id VARCHAR(40) UNIQUE,created_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE sandbox_keys(id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,key_hash CHAR(64) UNIQUE,hint VARCHAR(20),status VARCHAR(10),created_at DATETIME) ENGINE=InnoDB",
"CREATE TABLE sandbox_intents(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(40) UNIQUE,user_id INT,amount DECIMAL(18,2),currency CHAR(3),reference VARCHAR(100),status VARCHAR(12),idempotency_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin,request_hash CHAR(64),created_at DATETIME,UNIQUE KEY uq_idem (user_id,idempotency_key)) ENGINE=InnoDB",
"CREATE TABLE sandbox_events(id BIGINT AUTO_INCREMENT PRIMARY KEY,public_id VARCHAR(40),user_id INT,intent_id VARCHAR(40),type VARCHAR(40),payload TEXT,created_at DATETIME) ENGINE=InnoDB",
] as $statement) Db::pdo()->exec($statement);
$checks=0;
function check($ok,$message){global $checks;$checks++;if(!$ok)throw new RuntimeException($message);}
function rejects($fn,$class){try{$fn();}catch(Throwable $e){check($e instanceof $class,'Unexpected error '.get_class($e));return;}throw new RuntimeException('Expected rejection');}
Sandbox::create(1);Sandbox::create(2);$key=Sandbox::key(1);
check((int)Sandbox::authenticate($key)['user_id']===1,'Test key owner');
check(Sandbox::authenticate(str_replace('sk_test_','sk_live_',$key))===null,'Live prefix rejected');
check(Sandbox::authenticate('sk_test_'.str_repeat('0',48))===null,'Unknown key rejected');
check(Db::row('SELECT key_hash FROM sandbox_keys')['key_hash']!==$key,'No raw keys');
$payload=['amount'=>'1000.00','currency'=>'UGX','reference'=>'fixture-001'];
$a=Sandbox::intent(1,$payload,'request-001');$b=Sandbox::intent(1,$payload,'request-001');
check($a['id']===$b['id'],'Retry returns same intent');check($a['livemode']===false,'Test marker');
rejects(fn()=>Sandbox::intent(1,array_merge($payload,['amount'=>'2000']),'request-001'),DomainException::class);
$other=Sandbox::intent(2,$payload,'request-001');check($other['id']!==$a['id'],'Idempotency scoped to user');
rejects(fn()=>Sandbox::simulate(2,$a['id'],'success'),OutOfBoundsException::class);
rejects(fn()=>Sandbox::simulate(1,$a['id'],'reversal'),DomainException::class);
check(Sandbox::simulate(1,$a['id'],'success')['status']==='succeeded','Success transition');
Sandbox::simulate(1,$a['id'],'success');check((int)Db::row('SELECT COUNT(*) c FROM sandbox_events')['c']===1,'No duplicate event');
check(Sandbox::simulate(1,$a['id'],'reversal')['status']==='reversed','Reversal transition');
rejects(fn()=>Sandbox::simulate(1,$a['id'],'success'),DomainException::class);
check(Sandbox::simulate(2,$other['id'],'failure')['status']==='failed','Failure transition');
foreach(['0','-1','NaN','1e5','100.999','1000000000',''] as $amount)rejects(fn()=>Sandbox::intent(1,array_merge($payload,['amount'=>$amount]),'invalid-key'),InvalidArgumentException::class);
rejects(fn()=>Sandbox::intent(1,$payload,'short'),InvalidArgumentException::class);
rejects(fn()=>Sandbox::intent(1,array_merge($payload,['currency'=>'INVALID']),'invalid-currency'),InvalidArgumentException::class);
rejects(fn()=>Sandbox::simulate(1,$a['id'],'unknown'),InvalidArgumentException::class);
$c=Sandbox::intent(1,$payload,'rollback-001');Db::pdo()->exec("CREATE TRIGGER event_failure BEFORE INSERT ON sandbox_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'injected'");
rejects(fn()=>Sandbox::simulate(1,$c['id'],'success'),PDOException::class);
check(Db::row('SELECT status FROM sandbox_intents WHERE public_id=?',[$c['id']])['status']==='waiting','Event failure rolls back transition');
Db::run("UPDATE sandbox_keys SET status='revoked' WHERE user_id=1");check(Sandbox::authenticate($key)===null,'Revocation enforced');
$key=Sandbox::key(1);Db::run("UPDATE portal_users SET status='disabled' WHERE id=1");check(Sandbox::authenticate($key)===null,'Disabled user rejected');
check(!TestDb::hasTable('payments'),'No live payment table touched');
echo "PASS: $checks sandbox isolation, idempotency and lifecycle checks.\n";
