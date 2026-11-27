<?php
declare(strict_types=1);

namespace Latra\Application\Ingestion;

use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\FuelEvidence;
use Latra\Domain\Ingestion\TelemetryMessage;

/** Decodes vendor envelope, sensor and parameter evidence into the canonical telemetry contract. */
interface TelemetryMessageReader
{
    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $aliases enabled parameter aliases
     */
    public function decodeMessage(Vehicle $vehicle, array $raw, array $aliases = []): TelemetryMessage;

    /** @param array<string,mixed>|null $data */
    public function decodeUnitData(?array $data): ?TelemetryMessage;

    /** Extract the canonical parameter map from a stored source row. */
    public function decodeStoredParameters(array $stored): array;

    /** Decode already-resolved parameters for callers that hold only a parameter map. */
    public function decodeParameters(array $params): TelemetryMessage;

    public function decodeFuelEvidence(array $params): FuelEvidence;

    /** Map one source unit to a validated local vehicle without inventing identity. */
    public function decodeUnit(array $unit): Vehicle;
}
