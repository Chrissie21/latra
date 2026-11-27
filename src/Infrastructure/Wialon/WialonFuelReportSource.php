<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Closure;
use Latra\Application\Ingestion\FuelReportSource;
use Latra\Domain\Ingestion\TelemetryMessage;

final class WialonFuelReportSource implements FuelReportSource
{
    private Closure $createClient;
    private ?WialonApiClient $client = null;
    public function __construct(callable $createClient) { $this->createClient=Closure::fromCallable($createClient); }
    public function open(): bool
    {
        $this->client=($this->createClient)();
        return $this->client->login();
    }
    public function read(int $unitId): ?TelemetryMessage
    {
        if ($this->client === null) throw new \LogicException('Fuel source is not open');
        return (new WialonSourceReader())->decodeUnitData($this->client->getUnitData($unitId));
    }
    public function close(): void { $this->client?->logout(); }
}
