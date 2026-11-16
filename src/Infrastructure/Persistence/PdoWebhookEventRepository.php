<?php
namespace Latra\Infrastructure\Persistence;

use Closure;
use Latra\Application\Notification\WebhookEventRepository;

final class PdoWebhookEventRepository implements WebhookEventRepository
{
    private Closure $connection;
    /** Defer connecting until enqueue, so the application can observe queue failures. */
    public function __construct(callable $connection) { $this->connection=Closure::fromCallable($connection); }
    public function enqueue(string $type,array $payload): void
    {
        $pdo=($this->connection)();
        $pdo->prepare("INSERT INTO webhook_events (event_type,payload,status) VALUES (?,?,'pending')")->execute([$type,json_encode($payload)]);
    }
}
