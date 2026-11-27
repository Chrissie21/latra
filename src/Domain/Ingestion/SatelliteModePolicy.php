<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/** Only an explicitly reported 2D/3D mode is a measurement; unknown remains null. */
final class SatelliteModePolicy
{
    /** Interpret one already-selected source value. Key selection belongs to Infrastructure. */
    public static function interpret(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_scalar($value)) {
            return null;
        }
        $mode = (string) $value;
        if ($mode === '3' || strcasecmp($mode, '3d') === 0) {
            return 3;
        }
        if ($mode === '2' || strcasecmp($mode, '2d') === 0) {
            return 2;
        }
        return null;
    }
}
