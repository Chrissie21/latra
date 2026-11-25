<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/** Only an explicitly reported 2D/3D mode is a measurement; unknown remains null. */
final class SatelliteModePolicy
{
    /** Keys a device or parameter resolver may use to report the fix mode. */
    public const SOURCE_KEYS = ['d2d3', 'satellite_mode', 'fix_type', 'gps_mode'];

    /**
     * @param array $position Wialon `pos` object of the message
     * @param array $params   resolved message parameters
     */
    public static function fromMessage(array $position = [], array $params = []): ?int
    {
        foreach (self::SOURCE_KEYS as $key) {
            $value = $position[$key] ?? $params[$key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $mode = (string) $value;
            if ($mode === '3' || strcasecmp($mode, '3d') === 0) {
                return 3;
            }
            if ($mode === '2' || strcasecmp($mode, '2d') === 0) {
                return 2;
            }
        }
        return null;
    }
}
