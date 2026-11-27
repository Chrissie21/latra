<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Ingestion\TelemetrySignals;

/** Decodes source parameter evidence before domain policies make activity/status decisions. */
interface TelemetrySignalReader
{
    /** Relay-facing view: named strict flags plus the filtered detector evidence. */
    public function read(array $parameters, ?string $profile = null, ?string $engineParam = null,
        ?float $speedKmh = null): TelemetrySignals;

    /** Device-status view: measured rails and battery values, without relay voltage rules. */
    public function readDeviceStatus(array $parameters): TelemetrySignals;
}
