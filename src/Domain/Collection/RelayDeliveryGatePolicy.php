<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

/**
 * Delivery gate for queued relay payloads: every activity in a pending
 * payload must still be enabled for its vehicle and covered by the current
 * allowed set, so a disabled activity is never delivered.
 */
final class RelayDeliveryGatePolicy
{
    /** @param array<int,mixed> $enabled activity id => truthy enabled flag */
    public static function allows(array $enabled, array $allowed, array $activityIds): bool
    {
        foreach ($activityIds as $activityId) {
            if (empty($enabled[$activityId]) || !in_array($activityId, $allowed, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Activity ids in a stored pending payload. Undecodable or missing data is
     * treated as an empty payload.
     */
    public static function activityIds(string $payload): array
    {
        $decoded = json_decode($payload, true);
        $items = is_array($decoded) ? ($decoded['items'] ?? []) : [];
        if (!is_array($items)) {
            return [];
        }
        return array_map('intval', array_column($items, 'activity_id'));
    }
}
