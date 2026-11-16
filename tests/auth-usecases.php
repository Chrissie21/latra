<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Security\AuthRepository;
use Latra\Application\Security\AuthSession;
use Latra\Application\Security\AuthenticateUser;
use Latra\Application\Security\ManageAuthSession;
use Latra\Application\Security\EnforceRateLimit;
use Latra\Application\Security\RecordAuditEntry;
use Latra\Domain\Security\AccessPolicy;
use Latra\Domain\Security\AuthenticationPolicy;
function authAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
final class MemoryAuthRepository implements AuthRepository {
    public ?array $user = null;
    public array $failures = [];
    public array $successes = [];
    public array $rates = [];
    public array $audits = [];
    public bool $failAudit = false;
    public function ensure(): void {}
    public function activeUser(string $name): ?array { return $this->user; }
    public function failedLogin(int $id, int $failures, ?string $lockedUntil): void { $this->failures[] = [$id,$failures,$lockedUntil]; }
    public function successfulLogin(int $id, string $at): void { $this->successes[] = [$id,$at]; }
    public function incrementRate(string $key, int $window): int { return $this->rates[$key . ':' . $window] = ($this->rates[$key . ':' . $window] ?? 0) + 1; }
    public function appendAudit(array $entry): void {
        if ($this->failAudit) throw new RuntimeException('fixture failure');
        $this->audits[] = $entry;
    }
}
final class MemoryAuthSession implements AuthSession {
    public array $values = [];
    public int $logins = 0;
    public int $clears = 0;
    public function start(): void {}
    public function get(string $key): mixed { return $this->values[$key] ?? null; }
    public function set(string $key, mixed $value): void { $this->values[$key] = $value; }
    public function login(array $identity, int $now): void { $this->logins++; $this->values = ['auth'=>$identity,'auth_started'=>$now,'last_activity'=>$now]; }
    public function clear(): void { $this->clears++; $this->values = []; }
}
$repo = new MemoryAuthRepository(); $session = new MemoryAuthSession(); $now = 2000000000;
$audit = []; $attempts = [];
$login = new AuthenticateUser($repo,$session,static fn(): int => $now,static fn(int $time): string => gmdate('Y-m-d H:i:s',$time),
    static function(string $action,string $target,string $detail) use (&$audit): void { $audit[] = [$action,$target,$detail]; },
    static function(string $username,string $result,array $extra) use (&$attempts): void { $attempts[] = [$username,$result,$extra]; });
authAssert(!$login->attempt('absent','synthetic') && $session->logins === 0 && end($attempts)[1] === 'failed', 'Unknown user admitted');
$repo->user = ['id'=>7,'username'=>'synthetic','display_name'=>'','role'=>'viewer',
    'password_hash'=>password_hash('synthetic password',PASSWORD_DEFAULT),'failed_logins'=>7,'locked_until'=>null];
authAssert(!$login->attempt('synthetic','wrong') && $repo->failures === [[7,8,gmdate('Y-m-d H:i:s',$now+900)]], 'Eighth failure lockout changed');
$repo->user['locked_until'] = gmdate('Y-m-d H:i:s',$now+1);
authAssert(!$login->attempt('synthetic','synthetic password') && $session->logins === 0 && end($attempts)[1] === 'locked', 'Locked user admitted');
$repo->user['locked_until'] = gmdate('Y-m-d H:i:s',$now);
authAssert($login->attempt('synthetic','synthetic password',['timezone'=>'UTC']) && $session->logins === 1
    && $session->values['auth'] === ['uid'=>7,'username'=>'synthetic','display_name'=>'synthetic','role'=>'viewer']
    && $repo->successes === [[7,gmdate('Y-m-d H:i:s',$now)]] && end($attempts)[1] === 'success', 'Login identity or expired lockout changed');
$manage = new ManageAuthSession($session, static fn(): int => $now,1800);
$session->values['last_activity'] = $now-1800;
authAssert($manage->check() && $session->values['last_activity'] === $now, 'Idle boundary changed');
$session->values['last_activity'] = $now-1801;
authAssert(!$manage->check() && $session->values === [], 'Idle expiry failed to clear session');
$session->values = ['auth'=>['uid'=>7],'auth_started'=>$now-43200,'last_activity'=>$now];
authAssert($manage->check(), 'Absolute session boundary changed');
$session->values['auth_started']--;
authAssert(!$manage->check() && $session->values === [], 'Absolute session expiry changed');
$token = $manage->csrfToken();
authAssert(strlen($token) === 64 && $manage->csrfToken() === $token && $manage->verifyCsrf($token)
    && !$manage->verifyCsrf(null) && !$manage->verifyCsrf('wrong'), 'CSRF generation/comparison contract changed');
$limit = new EnforceRateLimit($repo, static fn(): int => $now);
authAssert($limit->allows('synthetic',2,60) && $limit->allows('synthetic',2,60) && !$limit->allows('synthetic',2,60), 'Rate-limit threshold changed');
$otherWindow = new EnforceRateLimit($repo, static fn(): int => $now+60);
authAssert($otherWindow->allows('synthetic',2,60) && $limit->allows('other',2,60), 'Rate scopes or window reset changed');
authAssert(AccessPolicy::hasPermission('operator','arbitrary') && AccessPolicy::hasPermission('viewer','audit.view')
    && !AccessPolicy::hasPermission('latra_user','audit.view') && AccessPolicy::hasPermission('latra_admin','device.parameters.update')
    && !AccessPolicy::canWrite('latra_admin') && !AccessPolicy::hasPermission('unknown','vehicles.view'), 'Role permissions expanded/restricted');
$errors = [];
$record = new RecordAuditEntry($repo,static function(string $message) use (&$errors): void { $errors[] = $message; });
$record->record('edit','fixture',str_repeat('x',2100),['uid'=>7,'username'=>'synthetic'],'127.0.0.1',['x'=>1],['x'=>2]);
$entry = $repo->audits[0];
authAssert(strlen($entry['detail']) === 2000 && $entry['user_id'] === 7 && $entry['before_json'] === '{"x":1}' && $entry['ip'] === '127.0.0.1', 'Audit shape changed');
$repo->failAudit = true; $record->record('read','fixture','',['uid'=>0,'username'=>'system'],'');
authAssert(count($errors) === 1, 'Best-effort audit failure escaped');
authAssert(AuthenticationPolicy::deviceExtra('{"timezone":"UTC","username":"injected","cores":4}') === ['timezone'=>'UTC','cores'=>4], 'Fingerprint whitelist changed');
echo "PASS auth lockout, credentials, session expiry/CSRF, permissions, rate scopes, audit and fingerprint whitelist\n";
