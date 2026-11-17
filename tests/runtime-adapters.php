<?php
/** File/transport effects are Infrastructure responsibilities, with compatibility callers preserved. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
$dir=sys_get_temp_dir().'/latra-log-test-'.bin2hex(random_bytes(5));mkdir($dir,0700);
define('LOGS_DIR',$dir);
try {
    Logger::info('synthetic.event',['unit'=>12]);
    $app=json_decode(file_get_contents($dir.'/app-'.date('Y-m-d').'.log'),true,512,JSON_THROW_ON_ERROR);
    $automation=json_decode(file_get_contents($dir.'/automation-'.date('Y-m-d').'.log'),true,512,JSON_THROW_ON_ERROR);
    if ($app['event']!=='synthetic.event' || $app['rid']!==Logger::requestId() || $automation['unit']!==12) {
        throw new RuntimeException('Structured logger contract changed');
    }
    foreach (['https://example.invalid/','http://localhost:8000/','http://127.0.0.1:0/','http://127.0.0.1:65536/'] as $url) {
        try {new \Latra\Infrastructure\Delivery\LoopbackRelayTransport($url);throw new LogicException('Unsafe transport accepted');}
        catch (InvalidArgumentException) {}
    }
    new \Latra\Infrastructure\Delivery\LoopbackRelayTransport('http://127.0.0.1:8000/');
} finally {foreach(glob($dir.'/*')?:[] as $file)unlink($file);rmdir($dir);}
echo "PASS structured logging and loopback-only transport configuration\n";
