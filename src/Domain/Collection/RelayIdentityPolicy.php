<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use Latra\Domain\Exception\InvalidSubmission;
use RuntimeException;

/**
 * Relay identity rules: which vehicle a collection unit belongs to, whether a
 * payload claims the vehicle that owns it, and which enrollment budgets are
 * mandatory. Values and failure messages are unchanged from the legacy
 * coordinator so operators keep the same diagnostics.
 */
final class RelayIdentityPolicy
{
    /** Every enrollment budget must be an explicit positive integer. */
    public const BUDGET_KEYS = ['max_records', 'max_bytes', 'max_pending_records', 'max_pending_bytes'];

    /**
     * Vehicle id embedded in a collection unit, or null when the unit is not a
     * relay unit. Manual and stream suffixes share the same owner.
     */
    public static function unitVehicleId(string $unit): ?int
    {
        return preg_match('/^(?:manual:)?wialon:([0-9]+)(?::.*)?$/D', $unit, $match)
            ? (int) $match[1]
            : null;
    }

    /**
     * A payload may only be queued for the vehicle it names. Absent identity
     * fields are accepted; present ones must match verbatim (string-compared,
     * as the legacy SQL-backed check was).
     */
    public static function assertOwns(array $vehicle, array $payload): void
    {
        $vehicleReg = $payload['vehicle_reg_no'] ?? null;
        $imei = $payload['imei'] ?? null;
        if (($vehicleReg !== null && $vehicleReg !== '' && (string) $vehicleReg !== (string) ($vehicle['vehicle_reg_no'] ?? ''))
            || ($imei !== null && $imei !== '' && (string) $imei !== (string) ($vehicle['imei'] ?? ''))
        ) {
            throw new InvalidSubmission('Relay payload identity does not match the owning vehicle');
        }
    }

    /**
     * Reject an enrollment row whose budgets are missing or non-positive
     * before it can shape a queue. Numeric strings are accepted because the
     * legacy row check cast the stored value.
     */
    public static function assertBudgets(array $settings): void
    {
        foreach (self::BUDGET_KEYS as $key) {
            if ((int) ($settings[$key] ?? 0) < 1) {
                throw new RuntimeException('Explicit relay resource budgets required');
            }
        }
    }
}
