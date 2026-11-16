<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Ingestion;

use Latra\Application\Emergency\EmergencyEventEffects;

/**
 * Preserves the existing emergency lifecycle side effects: the automation log
 * entries and outbound webhooks, with their original payload shapes.
 */
final class WebhookEmergencyEventEffects implements EmergencyEventEffects
{
    public function started(array $event): void
    {
        \Automation::log('emergency.start', [
            'event' => $event['event_id'],
            'vehicle' => (int) $event['vehicle_id'],
            'type' => $event['event_type'],
        ]);
        \Webhooks::emit('emergency_start', [
            'event' => $event['event_id'],
            'vehicle' => (int) $event['vehicle_id'],
            'type' => $event['event_type'],
        ]);
    }

    public function ended(array $event): void
    {
        \Automation::log('emergency.end', [
            'event' => $event['event_id'],
            'vehicle' => (int) $event['vehicle_id'],
            'type' => $event['event_type'],
        ]);
        \Webhooks::emit('emergency_end', [
            'event' => $event['event_id'],
            'vehicle' => (int) $event['vehicle_id'],
        ]);
    }
}
