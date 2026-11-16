<?php
namespace Latra\Infrastructure\Maintenance;

use Latra\Application\Maintenance\BackupProcess;
use RuntimeException;

final class ShellBackupProcess implements BackupProcess
{
    public function __construct(private string $script) {}
    public function run(): int
    {
        if (!is_executable($this->script) && !is_file($this->script)) throw new RuntimeException('backup.sh missing');
        exec('bash ' . escapeshellarg($this->script), $output, $code);
        return $code;
    }
}
