<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Closure;
use Latra\Application\Collection\FleetUnitSource;

/** Wialon-backed fleet unit source with one client session per open/close pair. */
final class WialonFleetUnitSource implements FleetUnitSource
{
    private Closure $createClient;
    private ?WialonApiClient $client = null;

    public function __construct(callable $createClient)
    {
        $this->createClient = Closure::fromCallable($createClient);
    }

    public function open(): bool
    {
        $this->client = ($this->createClient)();
        return $this->client->login();
    }

    public function units(): array
    {
        if ($this->client === null) {
            throw new \LogicException('Fleet source is not open');
        }
        return $this->client->getUnits();
    }

    public function unit(int $unitId): ?array
    {
        if ($this->client === null) {
            throw new \LogicException('Fleet source is not open');
        }
        return $this->client->getUnitData($unitId);
    }

    public function engineSensorParam(int $unitId): ?string
    {
        if ($this->client === null) {
            throw new \LogicException('Fleet source is not open');
        }
        return $this->client->getEngineSensorParam($unitId);
    }

    public function close(): void
    {
        $this->client?->logout();
        $this->client = null;
    }
}
