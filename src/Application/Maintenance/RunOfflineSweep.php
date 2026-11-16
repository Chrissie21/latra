<?php
namespace Latra\Application\Maintenance;

use Closure;

final class RunOfflineSweep
{
    private Closure $refreshGps;
    private Closure $markOffline;
    private Closure $notify;
    public function __construct(callable $refreshGps, callable $markOffline, callable $notify)
    {
        $this->refreshGps = Closure::fromCallable($refreshGps);
        $this->markOffline = Closure::fromCallable($markOffline);
        $this->notify = Closure::fromCallable($notify);
    }
    public function run(): int
    {
        ($this->refreshGps)();
        $marked = ($this->markOffline)();
        if ($marked > 0) ($this->notify)('device_offline', ['count'=>$marked]);
        return $marked;
    }
}
