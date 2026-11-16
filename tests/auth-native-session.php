<?php
/** Uses isolated temporary session/log files, with no database, credentials, DNS or HTTP. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
const SESSION_TIMEOUT = 1800;
$dir = sys_get_temp_dir() . '/latra-auth-' . bin2hex(random_bytes(6));
mkdir($dir,0700);
ini_set('session.save_path',$dir);
ini_set('session.use_cookies','0');
ini_set('session.use_strict_mode','1');
function nativeAuthAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
try {
    nativeAuthAssert(!Auth::check(), 'Empty native session authenticated');
    $token = Auth::csrfToken(); $before = session_id();
    (new Latra\Infrastructure\Security\NativeAuthSession())->login(
        ['uid'=>7,'username'=>'synthetic','display_name'=>'Synthetic','role'=>'viewer'],time());
    nativeAuthAssert(session_id() !== $before && Auth::check() && Auth::verifyCsrf($token)
        && Auth::hasPermission('audit.view') && !Auth::canWrite(), 'Native session rotation/identity or permission adapter changed');
    $_SESSION['last_activity'] = time()-SESSION_TIMEOUT-1;
    nativeAuthAssert(!Auth::check() && empty($_SESSION), 'Native expired session retained identity');
    $after = Auth::csrfToken();
    nativeAuthAssert(strlen($after) === 64 && !Auth::verifyCsrf($token), 'Expired session retained old CSRF token');
    Auth::logout();
    $logger = new Latra\Infrastructure\Security\FileLoginAttemptLogger($dir,
        ['REMOTE_ADDR'=>'127.0.0.1','HTTP_USER_AGENT'=>'curl/8.0','REQUEST_METHOD'=>'POST'],false);
    $logger->log('synthetic','failed',['username'=>'spoofed','ip'=>'spoofed','result'=>'success','timezone'=>'UTC']);
    $entry = json_decode(file_get_contents($dir . '/login.log'),true,512,JSON_THROW_ON_ERROR);
    nativeAuthAssert($entry['username'] === 'synthetic' && $entry['result'] === 'failed'
        && $entry['ip'] === '127.0.0.1' && $entry['timezone'] === 'UTC'
        && $entry['browser'] === 'HTTP client', 'Client data overrode authoritative login evidence');
} finally {
    if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
    foreach (glob($dir . '/*') ?: [] as $path) unlink($path);
    rmdir($dir);
}
echo "PASS native session rotation/expiry, CSRF invalidation, Auth compatibility and authoritative login evidence\n";
