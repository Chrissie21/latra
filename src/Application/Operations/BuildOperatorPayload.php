<?php
namespace Latra\Application\Operations;

use Closure;
use RuntimeException;
use Throwable;
use Latra\Domain\Collection\OperatorPayloadPolicy;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

final class BuildOperatorPayload
{
    private Closure $trip;
    private Closure $now;
    public function __construct(private OperatorPayloadRepository $repository,private LatestVehicleSource $source,
        callable $trip,callable $now) { $this->trip=Closure::fromCallable($trip);$this->now=Closure::fromCallable($now); }
    public function build(int $vehicleId,int $activity,string $mode='single'): array
    {
        $vehicle=$this->repository->vehicle($vehicleId);
        if (!$vehicle) throw new RuntimeException('Vehicle not found');
        if ($mode==='bulk') {
            $messages=$this->repository->recentMessages($vehicleId,OperatorPayloadPolicy::BULK_LIMIT);
            if (!$messages) $payload=$this->payload($vehicle,$vehicle,$activity,[]);
            else {
                $items=[];
                foreach ($messages as $message) {
                    $custom=$activity===LatraPayloadPolicy::ACTIVITY_ENGINE_OFF
                        ? ($this->trip)($vehicleId,(int)$message['wialon_msg_time']) : [];
                    $row=$this->payload($vehicle,$message,$activity,OperatorPayloadPolicy::parameters($message),$custom);
                    if (!empty($row['items'])) $items[]=$row['items'][0];
                }
                $payload=['vehicle_reg_no'=>$vehicle['vehicle_reg_no'] ?? null,'type'=>'poi','imei'=>$vehicle['imei'] ?? null,'items'=>$items];
            }
            return ['payload'=>$payload,'meta'=>null];
        }
        $latest=$this->repository->latestMessage($vehicleId);
        $selected=$latest ?: $vehicle;
        $params=$latest ? OperatorPayloadPolicy::parameters($latest) : [];
        $latestTime=$latest ? (int)$latest['wialon_msg_time'] : 0;
        $liveUsed=false;$now=($this->now)();
        if (!OperatorPayloadPolicy::recent($latestTime,$now)) {
            try {
                $live=$this->source->latest((int)($vehicle['wialon_unit_id'] ?? 0),$now-OperatorPayloadPolicy::LIVE_HISTORY_SECONDS,$now);
                $message=$live['message'] ?? null;
                if ($message && (int)($message['t'] ?? 0)>$latestTime) {
                    $unit=is_array($live['unit'] ?? null) ? $live['unit'] : [];
                    $row=LatraPayloadPolicy::mapVehicle(array_merge($unit,['lmsg'=>$message]));
                    $row['wialon_msg_time']=(int)$message['t'];
                    // A source record without unit metadata cannot replace the known owning identity.
                    $updates=array_filter($row,static fn($value): bool => $value!==null);
                    if (!$unit) foreach (['wialon_unit_id','vehicle_reg_no','imei','name','hw_type','phone'] as $key) unset($updates[$key]);
                    $vehicle=array_merge($vehicle,$updates);$selected=$row;$params=$message['p'] ?? [];$liveUsed=true;
                }
            } catch (Throwable $error) { /* An unavailable source leaves retained evidence usable. */ }
        }
        $sourceTime=(int)($selected['wialon_msg_time'] ?? $selected['last_message_time'] ?? 0);
        return ['payload'=>$this->payload($vehicle,$selected,$activity,$params),'meta'=>[
            'source'=>$liveUsed ? 'live' : ($latest ? 'stored' : 'vehicle'),
            'source_time'=>$sourceTime,'is_recent'=>OperatorPayloadPolicy::recent($sourceTime,$now),
        ]];
    }
    private function payload(array $vehicle,array $source,int $activity,array $params,array $custom=[]): array
    {
        $addon=LatraPayloadPolicy::buildAddonInfo($activity,$params,$custom) ?? [];
        $fuel=$activity===LatraPayloadPolicy::ACTIVITY_FUEL_REPORT ? LatraPayloadPolicy::buildFuelInfo($params) : [];
        return LatraPayloadPolicy::buildPayload($vehicle,$source,$activity,$addon,$fuel);
    }
}
