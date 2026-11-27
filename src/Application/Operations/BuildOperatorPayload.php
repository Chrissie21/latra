<?php
namespace Latra\Application\Operations;

use Closure;
use Latra\Application\Ingestion\TelemetryMessageReader;
use Latra\Domain\Collection\OperatorPayloadPolicy;
use Latra\Domain\Collection\Vehicle;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Domain\Ingestion\TelemetryMessage;
use RuntimeException;
use Throwable;

final class BuildOperatorPayload
{
    private Closure $trip;
    private Closure $now;
    public function __construct(private OperatorPayloadRepository $repository,private LatestVehicleSource $source,
        callable $trip,callable $now,private TelemetryMessageReader $decoder) {
        $this->trip=Closure::fromCallable($trip);$this->now=Closure::fromCallable($now);
    }
    public function build(int $vehicleId,int $activity,string $mode='single'): array
    {
        $vehicle=$this->repository->vehicle($vehicleId);
        if (!$vehicle) throw new RuntimeException('Vehicle not found');
        if ($mode==='bulk') {
            $messages=$this->repository->recentMessages($vehicleId,OperatorPayloadPolicy::BULK_LIMIT);
            if (!$messages) $payload=$this->payload(
                $vehicle,
                TelemetryMessage::fromStored($vehicle->telemetrySource()),
                $activity,
            );
            else {
                $items=[];
                foreach ($messages as $message) {
                    $custom=$activity===LatraPayloadPolicy::ACTIVITY_ENGINE_OFF
                        ? ($this->trip)($vehicleId,(int)$message['wialon_msg_time']) : [];
                    $params=$this->decoder->decodeStoredParameters($message);
                    $row=$this->payload(
                        $vehicle,
                        TelemetryMessage::fromStored(
                            $message,
                            $params,
                            $this->decoder->decodeFuelEvidence($params),
                        ),
                        $activity,
                        $custom,
                    );
                    if (!empty($row['items'])) $items[]=$row['items'][0];
                }
                $payload=['vehicle_reg_no'=>$vehicle->vehicle_reg_no,'type'=>'poi','imei'=>$vehicle->imei,'items'=>$items];
            }
            return ['payload'=>$payload,'meta'=>null];
        }
        $latest=$this->repository->latestMessage($vehicleId);
        $storedParameters=$latest ? $this->decoder->decodeStoredParameters($latest) : [];
        $source=$latest
            ? TelemetryMessage::fromStored(
                $latest,
                $storedParameters,
                $this->decoder->decodeFuelEvidence($storedParameters),
            )
            : TelemetryMessage::fromStored($vehicle->telemetrySource());
        $latestTime=$latest ? (int)$latest['wialon_msg_time'] : 0;
        $liveUsed=false;$now=($this->now)();
        $sourceTime=$source->messageTime ?? 0;
        if (!OperatorPayloadPolicy::recent($latestTime,$now)) {
            try {
                $unitId=$vehicle->wialon_unit_id;
                $live=$unitId === null ? null
                    : $this->source->latest($unitId,$now-OperatorPayloadPolicy::LIVE_HISTORY_SECONDS,$now);
                $rawMessage=$live['message'] ?? null;
                if ($rawMessage && (int)($rawMessage['t'] ?? 0)>$latestTime) {
                    $unit=is_array($live['unit'] ?? null) ? $live['unit'] : [];
                    // A source record without unit metadata cannot replace the known owning identity.
                    if ($unit !== []) $vehicle=$this->withLiveIdentity(
                        $vehicle,
                        $this->decoder->decodeUnit(array_merge($unit,['lmsg'=>$rawMessage])),
                    );
                    $source=$this->decoder->decodeMessage($vehicle,$rawMessage);
                    $liveUsed=true;
                    $sourceTime=$source->messageTime ?? 0;
                }
            } catch (Throwable $error) { /* An unavailable source leaves retained evidence usable. */ }
        }
        return ['payload'=>$this->payload($vehicle,$source,$activity),'meta'=>[
            'source'=>$liveUsed ? 'live' : ($latest ? 'stored' : 'vehicle'),
            'source_time'=>$sourceTime,'is_recent'=>OperatorPayloadPolicy::recent($sourceTime,$now),
        ]];
    }
    private function payload(Vehicle $vehicle,TelemetryMessage $source,int $activity,array $custom=[]): array
    {
        $addon=LatraPayloadPolicy::buildAddonInfo($activity,$source,$custom) ?? [];
        $fuel=$activity===LatraPayloadPolicy::ACTIVITY_FUEL_REPORT
            ? LatraPayloadPolicy::buildFuelInfo($source->fuel) : [];
        return LatraPayloadPolicy::buildPayload($vehicle,$source,$activity,$addon,$fuel)->toWire();
    }

    /** Keep known local identity when a live source cannot supply unit metadata. */
    private function withLiveIdentity(Vehicle $vehicle, Vehicle $mapped): Vehicle
    {
        $identity = static fn(?string $candidate, ?string $current): ?string =>
            $candidate !== null && $candidate !== '' ? $candidate : $current;
        $unitId = $mapped->wialon_unit_id ?? $vehicle->wialon_unit_id;
        return new Vehicle(
            id: $vehicle->id,
            wialon_unit_id: $unitId,
            vehicle_reg_no: $identity($mapped->vehicle_reg_no, $vehicle->vehicle_reg_no),
            imei: $identity($mapped->imei, $vehicle->imei),
            name: $identity($mapped->name, $vehicle->name),
            hw_type: $identity($mapped->hw_type, $vehicle->hw_type),
            phone: $identity($mapped->phone, $vehicle->phone),
            lat: $vehicle->lat,
            lng: $vehicle->lng,
            altitude: $vehicle->altitude,
            speed: $vehicle->speed,
            bearing: $vehicle->bearing,
            satellite_count: $vehicle->satellite_count,
            hdop: $vehicle->hdop,
            d2d3: $vehicle->d2d3,
            rssi: $vehicle->rssi,
            lac: $vehicle->lac,
            cell_id: $vehicle->cell_id,
            mcc: $vehicle->mcc,
            mgs_id: $vehicle->mgs_id,
            last_message_time: $vehicle->last_message_time,
            last_sync_at: $vehicle->last_sync_at,
            is_active: $vehicle->is_active,
            speed_limit_kmh: $vehicle->speed_limit_kmh,
            driver_id: $vehicle->driver_id,
            odometer: $vehicle->odometer,
            engine_param: $vehicle->engine_param,
            fuel_level_sensor: $vehicle->fuel_level_sensor,
            fuel_cadence_min: $vehicle->fuel_cadence_min,
        );
    }
}
