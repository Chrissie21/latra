<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/**
 * One canonical source envelope decoded from a vendor message.
 *
 * Every measurement carries its unit in the property name (or in FuelEvidence). Missing evidence
 * stays null. Raw vendor evidence and the opaque parameter view needed by the signal reader are
 * retained separately so Domain policies never select source keys.
 */
final readonly class TelemetryMessage
{
    /** @param list<SensorReading> $sensors @param array<string,mixed> $rawSource @param array<string,mixed> $signalParameters */
    public function __construct(
        public ?int $messageTime = null,
        public ?int $messageId = null,
        public ?int $receivedTime = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?int $altitudeM = null,
        public ?float $speedKmh = null,
        public ?float $bearingDeg = null,
        public ?int $satelliteCount = null,
        public ?int $fixMode = null,
        public ?float $hdop = null,
        public ?int $rssi = null,
        public ?int $lac = null,
        public ?int $cellId = null,
        public ?int $mcc = null,
        public ?float $odometerKm = null,
        public ?float $verticalSpeedMps = null,
        public ?string $driverId = null,
        public ?float $idleTimeSeconds = null,
        public ?float $tripDistanceKm = null,
        public ?float $tripDurationMinutes = null,
        public ?float $avgSpeedKmh = null,
        public ?float $maxSpeedKmh = null,
        public ?float $externalPowerVolts = null,
        public ?float $internalBatteryVolts = null,
        public ?float $batteryPct = null,
        public ?float $batteryMv = null,
        public FuelEvidence $fuel = new FuelEvidence(),
        public array $sensors = [],
        public array $rawSource = [],
        public array $signalParameters = [],
    ) {
    }

    /**
     * Rebuild canonical evidence from an already-normalized stored message.
     * Only canonical field names are read here; vendor decoding belongs to Infrastructure.
     *
     * @param array<string,mixed> $fields
     * @param array<string,mixed> $parameters canonical parameters extracted by the source reader
     */
    public static function fromStored(array $fields, array $parameters = [], ?FuelEvidence $fuel = null): self
    {
        $time = $fields['wialon_msg_time'] ?? $fields['last_message_time'] ?? null;
        return new self(
            messageTime: self::intOrNull($time),
            messageId: self::intOrNull($fields['mgs_id'] ?? null),
            receivedTime: self::intOrNull($fields['data_received_at'] ?? null),
            latitude: self::floatOrNull($fields['lat'] ?? null),
            longitude: self::floatOrNull($fields['lng'] ?? null),
            altitudeM: self::intOrNull($fields['altitude'] ?? null),
            speedKmh: self::floatOrNull($fields['speed'] ?? null),
            bearingDeg: self::floatOrNull($fields['bearing'] ?? null),
            satelliteCount: self::intOrNull($fields['satellite_count'] ?? null),
            fixMode: self::intOrNull($fields['d2d3'] ?? null),
            hdop: self::floatOrNull($fields['hdop'] ?? null),
            rssi: self::intOrNull($fields['rssi'] ?? null),
            lac: self::intOrNull($fields['lac'] ?? null),
            cellId: self::intOrNull($fields['cell_id'] ?? null),
            mcc: self::intOrNull($fields['mcc'] ?? null),
            odometerKm: self::floatOrNull($fields['odometer'] ?? $parameters['odometer'] ?? null),
            verticalSpeedMps: self::floatOrNull($fields['vertical_speed'] ?? $parameters['vertical_speed'] ?? null),
            driverId: self::stringOrNull($fields['driver_id'] ?? $parameters['driver_id'] ?? null),
            idleTimeSeconds: self::floatOrNull($parameters['idle_time'] ?? null),
            externalPowerVolts: self::floatOrNull($parameters['pwr_ext'] ?? null),
            internalBatteryVolts: self::floatOrNull($parameters['pwr_int'] ?? null),
            batteryPct: self::floatOrNull($fields['battery_pct'] ?? null),
            batteryMv: self::floatOrNull($fields['battery_mv'] ?? null),
            fuel: $fuel ?? new FuelEvidence(),
            signalParameters: $parameters,
        );
    }

    /** Canonical shape consumed by GpsValidationPolicy; no source keys are exposed. */
    public function toValidationArray(?string $imei = null, ?string $registration = null): array
    {
        return [
            'wialon_msg_time' => $this->messageTime,
            'imei' => $imei,
            'vehicle_reg_no' => $registration,
            'lat' => $this->latitude,
            'lng' => $this->longitude,
            'altitude' => $this->altitudeM,
            'speed' => $this->speedKmh,
            'bearing' => $this->bearingDeg,
            'satellite_count' => $this->satelliteCount,
            'hdop' => $this->hdop,
            'rssi' => $this->rssi,
            'lac' => $this->lac,
            'cell_id' => $this->cellId,
            'mcc' => $this->mcc,
        ];
    }

    private static function intOrNull(mixed $value): ?int
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (int) $value;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return ($value === null || $value === '' || !is_numeric($value)) ? null : (float) $value;
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }
}
