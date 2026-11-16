<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\AI\AssistantRepository;
use Latra\Application\AI\ChatClient;
use Latra\Application\AI\AssistantTools;
use Latra\Application\AI\AssistantEffects;
use Latra\Application\AI\ChatWithAssistant;
use Latra\Domain\AI\AssistantSettings;
function aiAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$repo=new class implements AssistantRepository {
    public array $stored=[]; public array $ownership=[];
    public function ensure(): void {}
    public function conversation(string $id,int $user): ?array { $this->ownership[]=[$id,$user];return ['id'=>1,'title'=>'fixture']; }
    public function createConversation(string $id,int $user,string $title): void { throw new RuntimeException('Existing conversation was recreated'); }
    public function history(string $id,int $user,int $limit): array { return [['role'=>'user','content'=>'newer'],['role'=>'user','content'=>'older']]; }
    public function jobs(): array { return [['job_key'=>'fixture','label'=>'Synthetic job']]; }
    public function store(array $entry): void { $this->stored[]=$entry; }
};
$client=new class implements ChatClient {
    public bool $configured=true; public array $responses=[]; public array $calls=[];
    public function isConfigured(): bool { return $this->configured; }
    public function model(): string { return 'synthetic-model'; }
    public function chat(array $messages,array $tools=[],int $maxTokens=800,float $temperature=0.2): array {
        $this->calls[]=[$messages,$tools,$maxTokens];
        return array_shift($this->responses) ?? ['ok'=>true,'message'=>['role'=>'assistant','content'=>'fixture answer']];
    }
};
$tools=new class implements AssistantTools {
    public array $calls=[];
    public function schemas(): array { return [['type'=>'function','function'=>['name'=>'fixture','parameters'=>['properties'=>['vehicle_id'=>['type'=>'integer']],'required'=>['vehicle_id']]]]]; }
    public function execute(string $name,array $args): array { $this->calls[]=[$name,$args];return ['ok'=>true,'data'=>['status'=>'synthetic']]; }
};
$effects=new class implements AssistantEffects {
    public bool $allowed=true;public array $limits=[];public array $attempts=[];public array $errors=[];
    public function allowRequest(int $user,int $max): bool { $this->limits[]=[$user,$max];return $this->allowed; }
    public function logAttempt(string $id,array $user,?int $latency,array $tools,int $http,?int $tokens=null): void { $this->attempts[]=[$id,$user,$latency,$tools,$http,$tokens]; }
    public function failure(string $message): void { $this->errors[]=$message; }
};
aiAssert(\Latra\Domain\AI\AssistantPolicy::validateToolArgs('empty',['unexpected'=>1],[['function'=>['name'=>'empty','parameters'=>['properties'=>(object)[]]]]])===['valid'=>false,'error'=>'Unexpected argument: unexpected'], 'Empty-object schema validation did not reject unknown arguments');
$settings=new AssistantSettings(20,900,'Synthetic app','1.0','UTC',[2=>'Engine on']);
$chat=new ChatWithAssistant($repo,$client,$tools,$effects,$settings,static fn(): float => 1000.0);
$user=['uid'=>7,'role'=>'viewer'];$request=['message'=>'What happened?','conversation_id'=>'synthetic_123'];
aiAssert($chat->chat($user,['message'=>''])['error']['code']==='VALIDATION_ERROR' && $client->calls===[], 'Empty message reached provider');
$effects->allowed=false;
aiAssert($chat->chat($user,$request)['error']['code']==='RATE_LIMITED' && $repo->ownership===[], 'Rate-limited request accessed history');
$effects->allowed=true;$client->configured=false;
aiAssert($chat->chat($user,$request)['error']['code']==='AI_NOT_CONFIGURED' && $repo->ownership===[['synthetic_123',7]], 'Unconfigured service or history ownership changed');
$client->configured=true;
$toolCall=static fn(array $args): array => ['ok'=>true,'message'=>['role'=>'assistant','content'=>null,'tool_calls'=>[['id'=>'synthetic_call','function'=>['name'=>'fixture','arguments'=>json_encode($args)]]]]];
$client->responses=[$toolCall(['vehicle_id'=>1]),['ok'=>true,'message'=>['role'=>'assistant','content'=>'fixture answer'],'usage'=>['total_tokens'=>42],'latency_ms'=>10,'http'=>200]];
$result=$chat->chat($user,$request);
aiAssert($result===['success'=>true,'conversation_id'=>'synthetic_123','message'=>['role'=>'assistant','content'=>'fixture answer']]
    && $tools->calls===[['fixture',['vehicle_id'=>1]]], 'Tool loop response or dispatch changed');
$messages=$client->calls[0][0];
aiAssert($messages[1]===['role'=>'user','content'=>"older\n\nnewer"] && str_contains($messages[0]['content'],'Synthetic app')
    && str_contains($messages[0]['content'],'READ-ONLY') && !str_contains($messages[0]['content'],'{self::'), 'Prompt rules/interpolation or normalized history changed');
$client->responses=[$toolCall(['unexpected'=>1]),['ok'=>true,'message'=>['role'=>'assistant','content'=>'invalid tool rejected']]];
$chat->chat($user,$request);
aiAssert(count($tools->calls)===1 && str_contains($client->calls[3][0][4]['content'],'Missing required argument'), 'Invalid tool arguments executed');
$client->responses=array_fill(0,5,$toolCall(['vehicle_id'=>1]));
aiAssert($chat->chat($user,$request)['error']['code']==='AI_LOOP_LIMIT' && count($tools->calls)===6, 'Tool iteration limit changed');
$client->responses=[['ok'=>true,'message'=>['role'=>'assistant','content'=>' ']]];
aiAssert($chat->chat($user,$request)['error']['code']==='AI_EMPTY_RESPONSE', 'Empty provider response accepted');
$client->responses=[['ok'=>false,'error'=>['code'=>'fixture_error','message'=>'fixture reason'],'http'=>503]];
aiAssert($chat->chat($user,$request)['error']['code']==='fixture_error' && end($repo->stored)['model']==='synthetic-model', 'Provider error or model evidence lost');
echo "PASS assistant ownership, validation/rate gates, prompt/history, tool validation/loop bounds and provider outcomes\n";
