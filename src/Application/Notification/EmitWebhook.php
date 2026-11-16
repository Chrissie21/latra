<?php
namespace Latra\Application\Notification;

use Closure;
use Throwable;

/** Preserves best-effort queueing and the independent immediate notification attempt. */
final class EmitWebhook
{
    private Closure $destination;
    private Closure $timestamp;
    private Closure $log;
    public function __construct(private WebhookEventRepository $repository,private WebhookTransport $transport,
        callable $destination,callable $timestamp,callable $log)
    {
        $this->destination=Closure::fromCallable($destination);
        $this->timestamp=Closure::fromCallable($timestamp);
        $this->log=Closure::fromCallable($log);
    }
    public function emit(string $type,array $payload): void
    {
        try { $this->repository->enqueue($type,$payload); }
        catch (Throwable $error) { ($this->log)('webhook.queue_failed',['type'=>$type],true); }
        $url=($this->destination)();
        if ($url==='') return;
        $body=json_encode(['type'=>$type,'at'=>($this->timestamp)(),'data'=>$payload]);
        $ok=$this->transport->send($url,$body);
        ($this->log)('webhook.sent',['type'=>$type,'ok'=>$ok],false);
    }
}
