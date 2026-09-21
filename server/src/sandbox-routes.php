<?php
require_once __DIR__ . '/Sandbox.php';
if ($path === '/v1/sandbox/register' && $method === 'POST') {
    $in=body(); requireTextFields($in,['email','password']);
    $email=strtolower(trim($in['email']??'')); $password=$in['password']??'';
    if(!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($email)>190)fail('bad_email','Enter a valid email.');
    if(strlen($password)<12||strlen($password)>72)fail('weak_password','Use 12–72 bytes for your password.');
    $recent=Db::row('SELECT COUNT(*) c FROM signups WHERE ip=? AND created_at>?',[client_ip(),date('Y-m-d H:i:s',time()-3600)]);
    if((int)$recent['c']>=10)fail('too_many_attempts','Please try again later.',429);
    Db::run('INSERT INTO signups (ip,created_at) VALUES (?,?)',[client_ip(),date('Y-m-d H:i:s')]);
    if(Db::row('SELECT id FROM portal_users WHERE email=?',[$email]))fail('email_exists','Sign in with your existing account to open a sandbox.',409);
    $pdo=Db::pdo();$pdo->beginTransaction();
    try {
        Db::run("INSERT INTO portal_users (merchant_id,email,phone,password_hash,role,status,created_at) VALUES (NULL,?,'',?,'developer','active',?)",[$email,password_hash($password,PASSWORD_DEFAULT),date('Y-m-d H:i:s')]);
        $uid=Db::lastId();$account=Sandbox::create($uid);$key=Sandbox::key($uid);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();if($e instanceof PDOException && ($e->errorInfo[1]??0)==1062)fail('email_exists','Sign in with your existing account.',409);throw $e;}
    out(['account'=>$account['public_id'],'api_key'=>$key,'livemode'=>false],201);
}
if (strpos($path,'/v1/portal/sandbox')===0) {
    $user=portalSession();if(!$user)fail('unauthorized','Sign in to use the sandbox.',401);
    $uid=(int)$user['id'];$account=Sandbox::account($uid);
    if($path==='/v1/portal/sandbox' && $method==='POST') {
        if($account)fail('already_exists','This account already has a sandbox.',409);
        $pdo=Db::pdo();$pdo->beginTransaction();
        try { $account=Sandbox::create($uid);$key=Sandbox::key($uid);$pdo->commit(); }
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        out(['account'=>$account['public_id'],'api_key'=>$key,'livemode'=>false],201);
    }
    if($path==='/v1/portal/sandbox' && $method==='GET') {
        out(['account'=>$account?$account['public_id']:null,'livemode'=>false,
          'keys'=>$account?Db::rows('SELECT id,hint,status,created_at FROM sandbox_keys WHERE user_id=? ORDER BY id DESC LIMIT 20',[$uid]):[],
          'intents'=>$account?array_map(['Sandbox','present'],Db::rows('SELECT * FROM sandbox_intents WHERE user_id=? ORDER BY id DESC LIMIT 50',[$uid])):[],
          'events'=>$account?Db::rows('SELECT public_id,type,payload,created_at FROM sandbox_events WHERE user_id=? ORDER BY id DESC LIMIT 50',[$uid]):[]]);
    }
    if(!$account)fail('not_found','Create a sandbox first.',404);
    if($path==='/v1/portal/sandbox/keys' && $method==='POST') {
        $recent=Db::row('SELECT COUNT(*) c FROM signups WHERE ip=? AND created_at>?',[client_ip(),date('Y-m-d H:i:s',time()-900)]);
        if((int)$recent['c']>=20)fail('too_many_attempts','Please wait before retrying key rotation.',429);
        Db::run('INSERT INTO signups (ip,created_at) VALUES (?,?)',[client_ip(),date('Y-m-d H:i:s')]);
        $in=body();requireTextFields($in,['password']);
        if(!password_verify($in['password']??'',$user['password_hash']))fail('unauthorized','Confirm your current password.',401);
        $pdo=Db::pdo();$pdo->beginTransaction();
        try { Db::row('SELECT * FROM sandbox_accounts WHERE user_id=? FOR UPDATE',[$uid]);Db::run("UPDATE sandbox_keys SET status='revoked' WHERE user_id=? AND status='active'",[$uid]);$key=Sandbox::key($uid);$pdo->commit(); }
        catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        out(['api_key'=>$key,'livemode'=>false]);
    }
}
if(strpos($path,'/v1/sandbox/')===0 || strpos($path,'/v1/portal/sandbox/')===0) {
    $portal=strpos($path,'/v1/portal/')===0;
    if(!$portal){$account=Sandbox::authenticate(bearerKey());if(!$account)fail('unauthorized','A valid sandbox test key is required.',401);$uid=(int)$account['user_id'];}
    $base=$portal?'/v1/portal/sandbox':'/v1/sandbox';
    try {
        if($path===$base.'/intents' && $method==='POST')out(['intent'=>Sandbox::intent($uid,body(),$_SERVER['HTTP_IDEMPOTENCY_KEY']??'')],201);
        if($path===$base.'/intents' && $method==='GET')out(['livemode'=>false,'intents'=>array_map(['Sandbox','present'],Db::rows('SELECT * FROM sandbox_intents WHERE user_id=? ORDER BY id DESC LIMIT 50',[$uid]))]);
        if($path===$base.'/events' && $method==='GET')out(['livemode'=>false,'events'=>array_map(static function($row){return json_decode($row['payload'],true);},Db::rows('SELECT payload FROM sandbox_events WHERE user_id=? ORDER BY id DESC LIMIT 50',[$uid]))]);
        if(preg_match('#^'.preg_quote($base,'#').'/intents/(pi_test_[a-f0-9]{24})/simulate$#D',$path,$matches) && $method==='POST') {
            $in=body();requireTextFields($in,['scenario']);out(['intent'=>Sandbox::simulate($uid,$matches[1],$in['scenario']??'')]);
        }
    } catch(InvalidArgumentException $e){fail('invalid_request',$e->getMessage());}
      catch(DomainException $e){fail('conflict',$e->getMessage(),409);}
      catch(OutOfBoundsException $e){fail('not_found',$e->getMessage(),404);}
    fail('not_found','Sandbox endpoint not found.',404);
}
