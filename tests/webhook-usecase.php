<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\Notification\EmitWebhook;
use Latra\Application\Notification\WebhookEventRepository;
use Latra\Application\Notification\WebhookTransport;
function webhookAssert(bool $ok,string $message): void { if (!$ok) throw new RuntimeException($message); }
$repo=new class implements WebhookEventRepository {
    public array $queued=[]; public bool $fail=false;
    public function enqueue(string $type,array $payload): void {
        if ($this->fail) throw new RuntimeException('fixture queue failure');
        $this->queued[]=[$type,$payload];
    }
};
$transport=new class implements WebhookTransport {
    public array $sent=[]; public bool $ok=true;
    public function send(string $url,string $body): bool { $this->sent[]=[$url,$body]; return $this->ok; }
};
$url='';$logs=[];
$work=new EmitWebhook($repo,$transport,static function() use (&$url): string { return $url; },
    static fn(): string => '2026-01-01T00:00:00+00:00',
    static function(string $event,array $context,bool $error) use (&$logs): void { $logs[]=[$event,$context,$error]; });
$work->emit('fixture',['count'=>3]);
webhookAssert($repo->queued===[['fixture',['count'=>3]]] && $transport->sent===[] && $logs===[], 'Disabled webhook did not retain event or attempted HTTP');
$url='https://synthetic.invalid/webhook';$work->emit('fixture',['count'=>3]);
webhookAssert($transport->sent=== [[$url,'{"type":"fixture","at":"2026-01-01T00:00:00+00:00","data":{"count":3}}']]
    && end($logs)===['webhook.sent',['type'=>'fixture','ok'=>true],false], 'Webhook payload or result logging changed');
$repo->fail=true;$transport->ok=false;$work->emit('fixture',['count'=>3]);
webhookAssert(count($transport->sent)===2 && array_slice($logs,-2)===[
    ['webhook.queue_failed',['type'=>'fixture'],true],['webhook.sent',['type'=>'fixture','ok'=>false],false],
], 'Queue failure stopped independent notification or transport failure evidence changed');
echo "PASS webhook queue/notification sequencing, disabled destination, payload shape and independent failure evidence\n";
