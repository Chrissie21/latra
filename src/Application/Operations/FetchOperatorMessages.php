<?php
namespace Latra\Application\Operations;

use Closure;
use RuntimeException;
use Throwable;

/** Operator imports retain their existing bounds; relay mode always uses complete-window collection. */
final class FetchOperatorMessages
{
    private Closure $poll;
    private Closure $import;
    private Closure $sourceFactory;
    private Closure $now;
    public function __construct(private OperatorReadRepository $repository,private bool $relayMode,
        callable $poll,callable $import,callable $sourceFactory,callable $now)
    {
        $this->poll=Closure::fromCallable($poll);$this->import=Closure::fromCallable($import);
        $this->sourceFactory=Closure::fromCallable($sourceFactory);$this->now=Closure::fromCallable($now);
    }
    public function one(int $vehicleId): array
    {
        if (!$vehicleId) throw new RuntimeException('Vehicle ID required');
        $vehicle=$this->repository->vehicle($vehicleId);
        if (!$vehicle) throw new RuntimeException('Vehicle not found');
        if ($this->relayMode) {
            $collected=($this->poll)($vehicle,86400,100);
            return ['success'=>true,'message'=>$collected['fetched'].' source reports collected; '.$collected['rejected'].' newly held for review.']+$collected;
        }
        $source=($this->sourceFactory)();
        if (!$source->open()) throw new RuntimeException('Wialon login failed');
        try {
            $now=($this->now)();
            $messages=$source->messages((int)$vehicle['wialon_unit_id'],$now-86400,$now,100);
        } finally { $source->close(); }
        $imported=($this->import)($vehicle,$messages);
        $inserted=$imported['inserted']+$imported['rejected'];$rejected=$imported['rejected'];
        return ['success'=>true,'message'=>"$inserted messages fetched and stored ($rejected invalid/rejected)"];
    }
    public function bulk(array $vehicleIds,callable $progress): void
    {
        if (!$vehicleIds) throw new RuntimeException('No vehicle IDs provided');
        $total=count($vehicleIds);$success=0;$failed=0;
        $progress(['type'=>'init','total'=>$total,'message'=>'Connected. Starting vehicle fetch...']);
        $source=$this->relayMode ? null : ($this->sourceFactory)();
        if ($source!==null && !$source->open()) { $progress(['type'=>'error','message'=>'Wialon login failed']);return; }
        try {
            foreach (array_values($vehicleIds) as $index=>$id) {
                $vehicle=$this->repository->vehicle((int)$id);
                if (!$vehicle) {
                    $progress(['type'=>'progress','current'=>$index+1,'total'=>$total,'vehicle'=>'Vehicle #'.$id,'inserted'=>0,'status'=>'fail']);
                    $failed++;continue;
                }
                try {
                    if ($this->relayMode) {
                        $collected=($this->poll)($vehicle,86400,100);
                        $progress(['type'=>'progress','current'=>$index+1,'total'=>$total,'vehicle'=>$vehicle['vehicle_reg_no'],'status'=>'ok']+$collected);
                    } else {
                        $now=($this->now)();
                        $messages=$source->messages((int)$vehicle['wialon_unit_id'],$now-86400,$now,100);
                        $imported=($this->import)($vehicle,$messages);
                        $progress(['type'=>'progress','current'=>$index+1,'total'=>$total,'vehicle'=>$vehicle['vehicle_reg_no'],
                            'inserted'=>$imported['inserted']+$imported['rejected'],'status'=>'ok']);
                    }
                    $success++;
                } catch (Throwable $error) {
                    $progress(['type'=>'progress','current'=>$index+1,'total'=>$total,'vehicle'=>$vehicle['vehicle_reg_no'],'inserted'=>0,'status'=>'fail']);$failed++;
                }
            }
        } finally { $source?->close(); }
        $progress(['type'=>'done','success'=>$success,'failed'=>$failed]);
    }
}
