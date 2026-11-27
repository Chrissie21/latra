<?php
namespace Latra\Application\Operations;

use Closure;
use RuntimeException;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

final class ReadSubmissionEvidence
{
    private Closure $buildPayload;
    public function __construct(private OperatorReadRepository $repository,callable $buildPayload) { $this->buildPayload=Closure::fromCallable($buildPayload); }
    public function response(int $logId): mixed
    {
        $log=$this->repository->receipt($logId);
        if (!$log) throw new RuntimeException('Log not found');
        return json_decode($log['response_body'] ?? '{}',true) ?: ['response'=>$log['response_body']];
    }
    public function payload(?int $logId,int $messageId): mixed
    {
        if ($logId!==null) {
            $log=$this->repository->receipt($logId);
            if (!$log) throw new RuntimeException('Log not found');
            return json_decode($log['payload'] ?? '{}',true) ?: ['payload'=>$log['payload']];
        }
        $message=$this->repository->message($messageId);
        if (!$message) throw new RuntimeException('Message not found');
        $payload=json_decode($message['latra_payload'] ?? '',true);
        if (!is_array($payload) || !$payload) {
            $vehicle=$this->repository->vehicle((int)($message['vehicle_id'] ?? 0));
            if (!$vehicle) throw new RuntimeException('Vehicle not found');
            $payload=($this->buildPayload)($vehicle,$message,(int)$message['activity_id'],
                json_decode($message['addon_info'] ?? '[]',true),json_decode($message['fuel_info'] ?? '[]',true));
        }
        return ['payload'=>$payload];
    }
    public function activityStatistics(): array
    {
        $labels=[];$values=[];
        foreach ($this->repository->activityStatistics() as $row) {
            $labels[]=LatraPayloadPolicy::getActivityName((int)$row['activity_id']);$values[]=$row['c'];
        }
        return ['labels'=>$labels,'values'=>$values];
    }
}
