<?php
namespace Latra\Application\Maintenance;

use Closure;
use RuntimeException;

final class CreateBackup
{
    private Closure $notify;
    public function __construct(private BackupProcess $process, callable $notify) { $this->notify = Closure::fromCallable($notify); }
    public function run(): int
    {
        $code = $this->process->run();
        if ($code !== 0) {
            ($this->notify)('backup_failed', ['code'=>$code]);
            throw new RuntimeException('backup exit ' . $code);
        }
        return 1;
    }
}
