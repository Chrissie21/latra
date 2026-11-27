<?php
/**
 * Relay payload mapping. Lives in the application layer because it combines domain policies with
 * per-vehicle enrollment/eligibility state; it performs no alerts, HTTP calls or source-history writes.
 */
declare(strict_types=1);

namespace Latra\Application\Collection;

use InvalidArgumentException;
use Latra\Application\Ingestion\TelemetryMessageReader;
use Latra\Application\Ingestion\TelemetrySignalReader;
use Latra\Domain\Collection\RelayEnrollmentPolicy;
use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\ActivityDetectionPolicy;
use Latra\Domain\Ingestion\RelayActivitySignals;
use Latra\Domain\Ingestion\GpsValidationPolicy as GpsValidator;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

final class RelayPayloadMapper {
    public function __construct(private Vehicle $vehicle, private array $enabled, private array $allowed,
        private float $speedLimit, private int $overspeedSamples, private int $batteryLowMv,
        private string $profile, private TelemetrySignalReader $signalReader,
        private TelemetryMessageReader $messageReader,
        private int $eventCooldown = 0, private array $parameterAliases = []) {
        if (!in_array($profile, RelayEnrollmentPolicy::PROFILES, true)
            || $overspeedSamples < 1 || $batteryLowMv < 1) {
            throw new InvalidArgumentException('Explicit supported mapping policy required');
        }
    }

    public function map(array $raw, array $state): array {
        $message = $this->messageReader->decodeMessage($this->vehicle, $raw, $this->parameterAliases);
        $params = $message->signalParameters;
        // Missing required source data must be retained even if activity gates suppress output.
        $validation=GpsValidator::validate($message->toValidationArray(
            $this->vehicle->imei, $this->vehicle->vehicle_reg_no,
        ));
        $errors=LatraPayloadPolicy::validatePayload(
            LatraPayloadPolicy::buildPayload($this->vehicle, $message, 1)->toWire(),
        );
        if (!$validation['valid'] || $errors) {
            $reasons=array_merge(array_map(static fn(array $error): string => $error['field'].': '.$error['reason'],$validation['errors']),$errors);
            throw new \Latra\Domain\Exception\RequiresReview(implode('; ',array_unique($reasons)));
        }
        $signals = $this->signalReader->read(
            $params, $this->profile, $this->vehicle->engine_param, $message->speedKmh,
        );
        $external = $signals->externalPowerVolts();
        $internal = $signals->internalBatteryVolts();
        $voltage = [];
        if ($external !== null) $voltage['ext_power_voltage'] = (string) $external;
        if ($internal !== null) $voltage['int_battery_voltage'] = (string) $internal;
        $activities = RelayActivitySignals::detect($signals);
        $activities[ActivityDetectionPolicy::MOVEMENT] = true;
        // Retain the existing detector's supported activity, alongside independent signal matches.
        // Zero voltage alone is not authoritative unplug evidence in this new path.
        $detected = ActivityDetectionPolicy::detect($signals, $state, [
            'speed' => $message->speedKmh, 'activity_enabled' => $this->enabled,
            'speed_limit' => $this->speedLimit,
        ]);
        $activity = (int) $detected['activity_id'];
        // Prefer the existing exact event-name mapping over a shorter substring (passenger_panic vs panic).
        $eventName = $signals->rawEventName();
        if ($eventName !== null) $activity = ActivityDetectionPolicy::EVENT_MAP[$eventName];
        $over = $message->speedKmh !== null && $message->speedKmh > $this->speedLimit;
        $state['overspending_active'] = $over ? (int) ($state['overspending_active'] ?? 0) + 1 : 0;
        if ($state['overspending_active'] < $this->overspeedSamples) unset($activities[ActivityDetectionPolicy::SPEEDING]);
        if ($over && $state['overspending_active'] >= $this->overspeedSamples) {
            $activities[ActivityDetectionPolicy::SPEEDING] = true;
        }
        if ($activity !== ActivityDetectionPolicy::SPEEDING || $state['overspending_active'] >= $this->overspeedSamples) {
            $activities[$activity] = true;
        }
        $engine = $signals->engineOn();
        $explicitEngine = $eventName === null ? null : (ActivityDetectionPolicy::EVENT_MAP[$eventName] ?? null);
        if ($engine === null && in_array($explicitEngine, [2,3], true)) $engine = $explicitEngine === 2;
        $priorEngine = $state['engine_status'] ?? null;
        if ($engine !== null) {
            if ($priorEngine === 'off' && $engine) $activities[ActivityDetectionPolicy::ENGINE_ON] = true;
            if ($priorEngine === 'on' && !$engine) $activities[ActivityDetectionPolicy::ENGINE_OFF] = true;
            $state['engine_status'] = $engine ? 'on' : 'off';
        }
        $maneuver = $signals->fmbManeuver();
        if ($maneuver !== null) {
            $driving = ['acceleration' => ActivityDetectionPolicy::HASH_ACCEL,
                'braking' => ActivityDetectionPolicy::HASH_BRAKING,
                'turning' => ActivityDetectionPolicy::HASH_TURNING];
            $activities[$driving[$maneuver]] = true;
        }
        if ($signals->fmbPowerCut()) $activities[ActivityDetectionPolicy::POWER_DISC] = true;
        if ($signals->fmbExcessiveIdle()) $activities[ActivityDetectionPolicy::EXCESSIVE_IDLE] = true;
        // Reuse the configured voltage rule; a zero/missing voltage is unknown, not low battery.
        if ($internal !== null && $internal > 0 && $internal * 1000 < $this->batteryLowMv) {
            $activities[ActivityDetectionPolicy::BATTERY_LOW] = true;
        }

        // A compact trip accumulator replaces the successful-telemetry history dependency.
        $trip = $state['trip'] ?? null;
        if ($engine === true && ($priorEngine === 'off' || ($trip === null && $explicitEngine === 2))) {
            $trip = ['from' => $message->messageTime, 'distance' => 0, 'sum' => 0, 'count' => 0, 'max' => 0];
        }
        if ($trip !== null) {
            if ($message->latitude !== null && $message->longitude !== null) {
                if (isset($trip['lat'], $trip['lng'])) $trip['distance'] += LatraPayloadPolicy::haversineKm(
                    $trip['lat'], $trip['lng'], $message->latitude, $message->longitude);
                $trip['lat'] = $message->latitude;
                $trip['lng'] = $message->longitude;
            }
            if ($message->speedKmh !== null) {
                $trip['sum'] += $message->speedKmh; $trip['count']++;
                $trip['max'] = max($trip['max'], $message->speedKmh);
            }
        }
        $payload = null;
        ksort($activities);
        foreach (array_keys($activities) as $id) {
            if (empty($this->enabled[$id]) || !in_array($id, $this->allowed, true)) continue;
            if ($this->eventCooldown > 0 && !in_array($id, [1,2,3,9,10,14,18,19,20,21,22,23], true)) {
                // Preserve the event engine's wall-clock cooldown when creating durable event intent.
                if (time() - (int) ($state['momentary_at'][$id] ?? 0) < $this->eventCooldown) continue;
                $state['momentary_at'][$id] = time();
            }
            $custom = [];
            if ($id === ActivityDetectionPolicy::ENGINE_OFF) {
                // An initial engine-on baseline does not establish a trip start.
                if ($trip !== null) $custom = ['distance' => round($trip['distance'], 2),
                    'duration' => round((($message->messageTime ?? 0) - $trip['from']) / 60, 2),
                    'avgSpeed' => $trip['count'] ? round($trip['sum'] / $trip['count'], 2) : null,
                    'maxSpeed' => $trip['max']];
            }
            $addon = LatraPayloadPolicy::buildAddonInfo($id, $message, $custom) ?? [];
            if (in_array($id, [ActivityDetectionPolicy::BATTERY_LOW, ActivityDetectionPolicy::POWER_DISC], true)) {
                // Power addon_info is optional; include only measured rails in volts.
                $addon = [
                    'ext_power_voltage' => $voltage['ext_power_voltage'] ?? null,
                    'int_battery_voltage' => $voltage['int_battery_voltage'] ?? null,
                ];
                $addon = array_filter($addon, static fn($value): bool => $value !== null);
            }
            $fuel = $id === ActivityDetectionPolicy::FUEL_REPORT ? LatraPayloadPolicy::buildFuelInfo($message->fuel) : [];
            // Do not borrow the vehicle's latest GPS fix for a historical source report.
            $one = LatraPayloadPolicy::buildPayload($this->vehicle, $message, $id, $addon, $fuel);
            $errors = LatraPayloadPolicy::validatePayload($one->toWire());
            if ($errors) throw new \Latra\Domain\Exception\RequiresReview(implode('; ',array_unique($errors)));
            $payload = $payload === null ? $one : $payload->withItem($one->items[0]);
        }
        $state['trip'] = $engine === false ? null : $trip;
        return ['payload' => $payload?->toWire(), 'state' => $state, 'suppressed' => $payload === null];
    }
}
