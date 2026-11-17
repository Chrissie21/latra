<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Closure;

/** Credential probe used by the operator connectivity check; no message traffic. */
final class WialonConnectionProbe
{
    private Closure $connector;

    public function __construct(callable $connector)
    {
        $this->connector = Closure::fromCallable($connector);
    }

    public function check(): bool
    {
        $client = ($this->connector)();
        $ok = (bool) $client->login();
        if ($ok) $client->logout();
        return $ok;
    }
}
