<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Maintenance\CreateBackup;
use Latra\Application\Maintenance\BackupProcess;
use Latra\Application\Maintenance\RunOfflineSweep;
use Latra\Application\Maintenance\ArchiveHistoricalData;
function maintenanceAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$calls=[]; $marked=3;
$sweep = new RunOfflineSweep(static function() use (&$calls): void { $calls[] = 'gps'; },
    static function() use (&$calls,&$marked): int { $calls[] = 'devices'; return $marked; },
    static function(string $type,array $payload) use (&$calls): void { $calls[] = [$type,$payload]; });
maintenanceAssert($sweep->run() === 3 && $calls === ['gps','devices',['device_offline',['count'=>3]]], 'Offline sweep order or notification changed');
$calls=[]; $marked=0;
maintenanceAssert($sweep->run() === 0 && $calls === ['gps','devices'], 'Empty offline sweep emitted false alert');
$process = new class implements BackupProcess {
    public int $exit=0;
    public function run(): int { return $this->exit; }
};
$calls=[];
$backup = new CreateBackup($process, static function(string $type,array $payload) use (&$calls): void { $calls[] = [$type,$payload]; });
maintenanceAssert($backup->run() === 1 && $calls === [], 'Successful backup accounting changed');
$process->exit = 7;
try { $backup->run(); throw new LogicException('Backup failure hidden'); }
catch (RuntimeException $error) { maintenanceAssert($error->getMessage() === 'backup exit 7', 'Backup exit detail lost'); }
maintenanceAssert($calls === [['backup_failed',['code'=>7]]], 'Backup failure notification changed');
$calls=[];
$archive = new ArchiveHistoricalData(
    static function(int $days) use (&$calls): int { $calls[] = ['archive',$days]; return 2; },
    static function() use (&$calls): int { $calls[] = ['purge']; return 3; },
    static function(int $cutoff) use (&$calls): void { $calls[] = ['logs',$cutoff]; },
    static fn(): int => 2000000000);
maintenanceAssert($archive->run(90) === 5 && $calls === [['archive',90],['purge'],['logs',2000000000-90*86400]], 'Archive/purge order, age or accounting changed');
$dir = sys_get_temp_dir() . '/latra-maintenance-' . bin2hex(random_bytes(6)); mkdir($dir,0700);
try {
    file_put_contents($dir.'/old.log','fixture'); touch($dir.'/old.log',1000);
    file_put_contents($dir.'/recent.log','fixture'); touch($dir.'/recent.log',3000);
    file_put_contents($dir.'/keep.txt','fixture'); touch($dir.'/keep.txt',1000);
    (new Latra\Infrastructure\Maintenance\FilesystemLogPruner($dir))->before(2000);
    maintenanceAssert(!is_file($dir.'/old.log') && is_file($dir.'/recent.log') && is_file($dir.'/keep.txt'), 'Filesystem cleanup ignored extension or age');
    $path=$dir.'/synthetic backup.sh'; file_put_contents($path,"#!/bin/sh\nexit 7\n");
    maintenanceAssert((new Latra\Infrastructure\Maintenance\ShellBackupProcess($path))->run() === 7, 'Quoted backup path or exit code lost');
    try { (new Latra\Infrastructure\Maintenance\ShellBackupProcess($dir.'/missing.sh'))->run(); throw new LogicException('Missing script accepted'); }
    catch (RuntimeException $error) { maintenanceAssert($error->getMessage() === 'backup.sh missing', 'Missing backup script error changed'); }
} finally {
    foreach (glob($dir.'/*') ?: [] as $file) unlink($file);
    rmdir($dir);
}
echo "PASS scheduled maintenance sequencing, backup failure, fixed age policy and isolated filesystem/process adapters\n";
