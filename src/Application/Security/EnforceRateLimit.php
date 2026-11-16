<?php
namespace Latra\Application\Security;

use Closure;

final class EnforceRateLimit
{
    private Closure $now;
    public function __construct(private AuthRepository $repository, callable $now) { $this->now = Closure::fromCallable($now); }
    public function allows(string $key, int $max, int $windowSeconds): bool
    {
        $this->repository->ensure();
        $windowStart = intdiv(($this->now)(), $windowSeconds) * $windowSeconds;
        return $this->repository->incrementRate($key, $windowStart) <= $max;
    }
}
