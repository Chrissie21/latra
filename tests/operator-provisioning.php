<?php
/** Installer/admin reset contracts against isolated SQL, including rollback. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Security\ProvisionOperatorAccount;
use Latra\Infrastructure\Persistence\PdoOperatorAccountRepository;
use Latra\Infrastructure\Security\NativePasswordHasher;
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE app_users(id INTEGER PRIMARY KEY,username TEXT UNIQUE,password_hash TEXT,display_name TEXT,
    role TEXT DEFAULT 'viewer',is_active INTEGER,failed_logins INTEGER DEFAULT 0,locked_until TEXT)");
$work=new ProvisionOperatorAccount(new PdoOperatorAccountRepository($db),new NativePasswordHasher());
function provisionExpect(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);}
provisionExpect(!$work->provision('operator','synthetic-password'),'New account reported an update');
$row=$db->query('SELECT * FROM app_users')->fetch();
provisionExpect(password_verify('synthetic-password',$row['password_hash']) && $row['display_name']==='Operator'
    && $row['role']==='viewer','New account defaults or password hashing changed');
$db->exec("UPDATE app_users SET role='operator',failed_logins=4,locked_until='2099-01-01',is_active=0");
provisionExpect($work->provision('operator','reset-password'),'Reset reported a create');
$row=$db->query('SELECT * FROM app_users')->fetch();
provisionExpect($row['role']==='operator' && $row['failed_logins']===4 && $row['locked_until']==='2099-01-01'
    && $row['is_active']===1 && password_verify('reset-password',$row['password_hash']),
    'Admin reset changed role/lockout policy or failed to update password');
$db->beginTransaction();
$work->provision('operator','install-password',['role'=>'admin','reset_lockout'=>true]);
$row=$db->query('SELECT * FROM app_users')->fetch();
provisionExpect($row['role']==='admin' && $row['failed_logins']===0 && $row['locked_until']===null,
    'Installer did not restore admin access');
$db->rollBack();
provisionExpect($db->query('SELECT role FROM app_users')->fetchColumn()==='operator','Provisioning escaped caller rollback');
echo "PASS provisioning preserves create/reset distinctions, hashes passwords and joins caller transactions\n";
