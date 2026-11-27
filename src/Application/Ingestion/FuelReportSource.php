<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Ingestion\TelemetryMessage;

interface FuelReportSource
{
    public function open(): bool;

    /** Canonical decoded source evidence; null means the source had no data. */
    public function read(int $unitId): ?TelemetryMessage;

    public function close(): void;
}
