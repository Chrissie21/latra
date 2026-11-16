<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/**
 * Buffers one high-frequency source record against an active emergency event.
 *
 * A record is identified by (event_id, source time, vehicle); persistence
 * reports a duplicate as "not buffered" so the caller keeps counting records
 * without failing the batch, and the active event's counter advances only for
 * a new record.
 */
final class BufferEmergencyRecord
{
    public function __construct(private EmergencyEventRepository $repository)
    {
    }

    public function buffer(array $event, int $vehicleId, array $message): bool
    {
        $position = $message['pos'] ?? [];
        $buffered = $this->repository->createRecord([
            'event_id' => $event['event_id'],
            'emergency_event_id' => $event['id'],
            'vehicle_id' => $vehicleId,
            'wialon_msg_time' => $message['t'] ?? time(),
            'lat' => isset($position['y']) ? round((float) $position['y'], 8) : null,
            'lng' => isset($position['x']) ? round((float) $position['x'], 8) : null,
            'altitude' => $position['z'] ?? null,
            'speed' => isset($position['s']) ? round((float) $position['s'], 2) : null,
            'bearing' => $position['c'] ?? null,
            'satellite_count' => $position['sc'] ?? null,
            'hdop' => $message['p']['hdop'] ?? null,
            'raw_data' => json_encode($message),
            'latra_status' => 'pending',
        ]);

        if (!$buffered) {
            return false;
        }

        $this->repository->incrementRecordCount((int) $event['id']);
        return true;
    }
}
