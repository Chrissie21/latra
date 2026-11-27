<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

use Latra\Domain\Collection\Vehicle;

final class FuelReportPolicy
{
    /**
     * Choose the configured sensor from canonical decoded evidence.
     *
     * A missing or unconfigured sensor stays a null measurement with its source retained for
     * review; it is never converted into a healthy report or an HTTP attempt.
     *
     * @return array{reason:string}|array{source:TelemetryMessage,raw_source:array<string,mixed>,fuel:FuelEvidence}
     */
    public static function read(Vehicle $vehicle, ?TelemetryMessage $message): array
    {
        if ($message === null) {
            return ['reason' => 'no_unit_data'];
        }
        $configured = (string) ($vehicle->fuel_level_sensor ?? '');
        $selected = null;
        foreach ($message->sensors as $sensor) {
            if ((string) ($sensor->type ?? '') === $configured) {
                $selected = $sensor;
                break;
            }
        }

        return [
            'source' => $message,
            'raw_source' => $message->rawSource,
            'fuel' => new FuelEvidence(
                unit: $selected?->unit,
                level: self::numberOrNull($selected?->value),
            ),
        ];
    }

    private static function numberOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
    }
}
