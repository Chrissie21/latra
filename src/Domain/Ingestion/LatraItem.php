<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/**
 * One assembled LATRA item. Fields are already wire-formatted strings; validity is checked by
 * LatraPayloadPolicy::validatePayload after serialization, so incomplete evidence is retained.
 */
final readonly class LatraItem
{
    /** @param array<string,string|null> $fields @param array<string,string>|null $addon @param array<string,string>|null $fuel */
    public function __construct(
        public string $activityId,
        public array $fields,
        public ?array $addon = null,
        public ?array $fuel = null,
    ) {
    }

    /** Serialize in the documented IDD order at the delivery boundary. */
    public function toWire(): array
    {
        $item = [
            'latitude' => $this->fields['latitude'] ?? null,
            'longitude' => $this->fields['longitude'] ?? null,
            'timestamp' => $this->fields['timestamp'] ?? null,
            'activity_id' => $this->activityId,
            'odometer' => $this->fields['odometer'] ?? null,
            'addon_info' => $this->addon ?? '',
        ];
        foreach ([
            'altitude', 'horizontal_speed', 'bearing', 'satellite_count', 'HDOP',
            'd2d3', 'RSSI', 'LAC', 'Cell_ID', 'MGS_ID', 'MCC',
        ] as $field) {
            $item[$field] = $this->fields[$field] ?? null;
        }
        if (array_key_exists('vertical_speed', $this->fields) && $this->fields['vertical_speed'] !== null) {
            $item['vertical_speed'] = $this->fields['vertical_speed'];
        }
        if ($this->fuel !== null && $this->fuel !== []) {
            $item['fuel_info'] = $this->fuel;
        }
        return $item;
    }
}
