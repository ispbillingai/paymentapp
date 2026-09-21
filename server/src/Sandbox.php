<?php
/** Isolated simulator. No live tables, provider calls, or outbound webhook delivery. */
class Sandbox
{
    public static function account($userId) { return Db::row('SELECT * FROM sandbox_accounts WHERE user_id = ?', [(int)$userId]); }
    public static function key($userId)
    {
        $key = 'sk_test_' . bin2hex(random_bytes(24));
        Db::run("INSERT INTO sandbox_keys (user_id,key_hash,hint,status,created_at) VALUES (?,?,?,'active',?)", [(int)$userId,hash('sha256',$key),substr($key,0,16),date('Y-m-d H:i:s')]);
        return $key;
    }
    public static function authenticate($key)
    {
        if (!preg_match('/^sk_test_[a-f0-9]{48}$/D', $key)) return null;
        return Db::row("SELECT a.* FROM sandbox_accounts a JOIN sandbox_keys k ON k.user_id=a.user_id JOIN portal_users u ON u.id=a.user_id WHERE k.key_hash=? AND k.status='active' AND u.status='active'", [hash('sha256',$key)]);
    }
    public static function create($userId)
    {
        Db::run('INSERT INTO sandbox_accounts (user_id,public_id,created_at) VALUES (?,?,?)',[(int)$userId,'sbx_'.bin2hex(random_bytes(12)),date('Y-m-d H:i:s')]);
        return self::account($userId);
    }
    public static function intent($uid, array $in, $idempotency)
    {
        foreach (['amount','currency','reference'] as $field) if (!isset($in[$field]) || !is_string($in[$field])) throw new InvalidArgumentException($field.' must be text. Send amounts as decimal strings.');
        if (!preg_match('/^(?:0|[1-9][0-9]{0,8})(?:\.[0-9]{1,2})?$/D',$in['amount']) || (float)$in['amount']<=0) throw new InvalidArgumentException('Use a positive amount with at most two decimal places.');
        $currency=strtoupper($in['currency']); $ref=trim($in['reference']);
        if (!preg_match('/^[A-Z]{3}$/D',$currency) || $ref==='' || strlen($ref)>100) throw new InvalidArgumentException('Use a three-letter currency and a reference of 1–100 bytes.');
        if (!preg_match('/^[a-zA-Z0-9_-]{8,80}$/D',$idempotency)) throw new InvalidArgumentException('Idempotency-Key must contain 8–80 letters, digits, underscores or hyphens.');
        $amount=number_format((float)$in['amount'],2,'.','');
        $hash=hash('sha256',json_encode([$amount,$currency,$ref]));
        $old=Db::row('SELECT * FROM sandbox_intents WHERE user_id=? AND idempotency_key=?',[$uid,$idempotency]);
        if ($old) { if (!hash_equals($old['request_hash'],$hash)) throw new DomainException('This idempotency key was already used with different details.'); return self::present($old); }
        if ((int)Db::row('SELECT COUNT(*) c FROM sandbox_intents WHERE user_id=?',[$uid])['c']>=1000) throw new DomainException('Sandbox limit reached: 1,000 test intents per account.');
        $id='pi_test_'.bin2hex(random_bytes(12));
        try { Db::run("INSERT INTO sandbox_intents (public_id,user_id,amount,currency,reference,status,idempotency_key,request_hash,created_at) VALUES (?,?,?,?,?,'waiting',?,?,?)",[$id,$uid,$amount,$currency,$ref,$idempotency,$hash,date('Y-m-d H:i:s')]); }
        catch (PDOException $e) {
            $old=Db::row('SELECT * FROM sandbox_intents WHERE user_id=? AND idempotency_key=?',[$uid,$idempotency]);
            if (!$old) throw $e;
            if (!hash_equals($old['request_hash'],$hash)) throw new DomainException('This idempotency key was already used with different details.');
            return self::present($old);
        }
        return self::present(Db::row('SELECT * FROM sandbox_intents WHERE public_id=? AND user_id=?',[$id,$uid]));
    }
    public static function present($row) { return ['id'=>$row['public_id'],'livemode'=>false,'amount'=>(string)$row['amount'],'currency'=>$row['currency'],'reference'=>$row['reference'],'status'=>$row['status'],'created_at'=>$row['created_at']]; }
    public static function simulate($uid,$id,$scenario)
    {
        $map=['success'=>['waiting','succeeded','payment.succeeded'],'failure'=>['waiting','failed','payment.failed'],'reversal'=>['succeeded','reversed','payment.reversed']];
        if (!isset($map[$scenario])) throw new InvalidArgumentException('Choose success, failure or reversal.');
        [$from,$to,$type]=$map[$scenario]; $pdo=Db::pdo(); $pdo->beginTransaction();
        try {
            $row=Db::row('SELECT * FROM sandbox_intents WHERE public_id=? AND user_id=?',[$id,$uid]);
            if (!$row) throw new OutOfBoundsException('Test intent not found.');
            if ($row['status']===$to) { $pdo->commit(); return self::present($row); }
            if ($row['status']!==$from) throw new DomainException('This scenario is not valid for the current intent status.');
            if (Db::run('UPDATE sandbox_intents SET status=? WHERE public_id=? AND user_id=? AND status=?',[$to,$id,$uid,$from])!==1) throw new DomainException('The intent changed. Refresh and retry.');
            $row['status']=$to;
            $event=['id'=>'evt_test_'.bin2hex(random_bytes(12)),'type'=>$type,'livemode'=>false,'created'=>time(),'data'=>['intent'=>self::present($row)]];
            Db::run('INSERT INTO sandbox_events (public_id,user_id,intent_id,type,payload,created_at) VALUES (?,?,?,?,?,?)',[$event['id'],$uid,$id,$type,json_encode($event),date('Y-m-d H:i:s')]);
            $pdo->commit(); return self::present($row);
        } catch(Throwable $e) { if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
    }
}
