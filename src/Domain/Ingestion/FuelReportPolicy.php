<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

final class FuelReportPolicy
{
    public static function read(array $vehicle, ?array $data): array
    {
        if ($data === null) {
            return ['reason' => 'no_unit_data'];
        }
        $value = $unit = null;
        foreach (($data['sens'] ?? []) as $sensor) {
            if ((string) ($sensor['t'] ?? '') === (string) $vehicle['fuel_level_sensor']) {
                $value = $sensor['v'] ?? null;
                $unit = $sensor['u'] ?? null;
                break;
            }
        }
        $position=is_array($data['pos'] ?? null) ? $data['pos'] : [];
        return [
            'source' => [
                'wialon_msg_time' => is_numeric($position['t'] ?? null) && (int)$position['t']>0 ? (int)$position['t'] : null,
                'lat' => $position['y'] ?? null,
                'lng' => $position['x'] ?? null,
                'altitude' => $position['z'] ?? null,
                'speed' => $position['s'] ?? null,
                'bearing' => $position['c'] ?? null,
                'satellite_count' => $position['sc'] ?? null,
            ],
            'raw_source' => $data,
            'fuel' => ['fuel_level' => $value, 'fuel_sensor_param' => $vehicle['fuel_level_sensor'], 'unit' => $unit],
        ];
    }
}
