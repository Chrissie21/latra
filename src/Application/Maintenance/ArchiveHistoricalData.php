<?php
namespace Latra\Application\Maintenance;

use Closure;

final class ArchiveHistoricalData
{
    private Closure $archive;
    private Closure $purge;
    private Closure $pruneLogs;
    private Closure $now;
    public function __construct(callable $archive, callable $purge, callable $pruneLogs, callable $now)
    {
        $this->archive = Closure::fromCallable($archive);
        $this->purge = Closure::fromCallable($purge);
        $this->pruneLogs = Closure::fromCallable($pruneLogs);
        $this->now = Closure::fromCallable($now);
    }
    public function run(int $days): int
    {
        $removed = ($this->archive)($days);
        $removed += ($this->purge)();
        ($this->pruneLogs)(($this->now)() - $days * 86400);
        return $removed;
    }
}
