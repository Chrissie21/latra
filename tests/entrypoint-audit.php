<?php
/** Entry points and adapters must only compose application and infrastructure logic. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
function auditFail(string $message): void { throw new RuntimeException($message); }
function auditFiles(string $pattern): array {
    $files = glob($pattern) ?: [];
    sort($files);
    return $files;
}
$entryPoints = array_merge(
    [dirname(__DIR__) . '/api.php', dirname(__DIR__) . '/cron.php', dirname(__DIR__) . '/config.php'],
    auditFiles(dirname(__DIR__) . '/bin/*.php'),
    auditFiles(dirname(__DIR__) . '/scripts/*.php'),
);
$sql = '/\b(SELECT\s|INSERT\s+INTO\b|UPDATE\s+\w+\s+SET\b|DELETE\s+FROM\b|ALTER\s+TABLE\b|CREATE\s+TABLE\b|DROP\s+TABLE\b)/i';
foreach ($entryPoints as $file) {
    $name = basename($file);
    $source = (string) file_get_contents($file);
    if (preg_match($sql, $source, $match)) {
        auditFail($name . ' still contains SQL: ' . trim($match[0]));
    }
    foreach (['->prepare(', '->query(', 'information_schema'] as $needle) {
        if (str_contains($source, $needle)) auditFail($name . ' still owns persistence detail: ' . $needle);
    }
    foreach (['posix_kill', 'password_hash', 'file_put_contents(LOGS_DIR'] as $needle) {
        if (str_contains($source, $needle)) auditFail($name . ' still owns runtime policy: ' . $needle);
    }
    if ($name !== 'collection.php' && str_contains($source, 'new PDO(')) {
        auditFail($name . ' opens its own connection instead of composing the adapter');
    }
}
$adapters = auditFiles(dirname(__DIR__) . '/includes/*.php');
foreach ($adapters as $file) {
    if (basename($file) === 'Database.php') continue;
    $source = (string) file_get_contents($file);
    if (preg_match($sql, $source, $match)) {
        auditFail('includes/' . basename($file) . ' still contains SQL: ' . trim($match[0]));
    }
    if (str_contains($source, '->prepare(') || str_contains($source, '->query(')) {
        auditFail('includes/' . basename($file) . ' still owns a query');
    }
    if (preg_match('/Database::(fetch|fetchAll)\(/', $source)) {
        auditFail('includes/' . basename($file) . ' still reads through raw Database helpers');
    }
}
$config = (string) file_get_contents($root . '/config.php');
foreach (['openstreetmap', 'cdnjs', 'jsdelivr', 'fonts.googleapis', 'fonts.gstatic', 'code.jquery', 'unpkg'] as $origin) {
    if (stripos($config, $origin) !== false) {
        auditFail('config.php still allows a removed UI asset origin: ' . $origin);
    }
}
// Hosted jobs must reference the current files, including syntax-only checks.
foreach (auditFiles($root . '/.github/workflows/*.yml') as $workflow) {
    preg_match_all('#\b(?:tests|includes|src|bin|scripts)/[A-Za-z0-9_/.-]+\.php#',
        (string) file_get_contents($workflow), $paths);
    foreach (array_unique($paths[0]) as $path) {
        if (!is_file($root . '/' . $path)) auditFail(basename($workflow) . ' references deleted file: ' . $path);
    }
}
echo 'PASS ' . count($entryPoints) . ' entry points and ' . count($adapters)
    . " adapters contain no inline SQL; entry points delegate locking and provisioning\n";
