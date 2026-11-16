<?php
namespace Latra\Infrastructure\AI;

use Latra\Application\AI\AssistantEffects;

/** Binds assistant effects to the migrated security APIs and existing operational logger. */
final class RuntimeAssistantEffects implements AssistantEffects
{
    public function __construct(private bool $development,private bool $audit) {}
    public function allowRequest(int $user,int $max): bool { return \Auth::rateLimit('ai:'.$user.':'.\Auth::clientKey('ip'),$max,60); }
    public function logAttempt(string $id,array $user,?int $latency,array $tools,int $http,?int $tokens=null): void
    {
        if ($this->development) \Logger::info('ai.chat',[
            'user'=>(int)($user['uid'] ?? 0),'conversation'=>$id,'tools'=>array_slice($tools,0,8),
            'latency_ms'=>$latency,'http'=>$http,'tokens'=>$tokens,
        ]);
        if ($this->audit) \Auth::audit('ai.chat',$id,'latency_ms='.($latency ?? 0).' tools='.implode(',',array_slice($tools,0,8)));
    }
    public function failure(string $message): void { error_log($message); }
}
