<?php
require_once __DIR__.'/autoload.php';

/** Compatibility boundary for notification configuration and effects. */
class Webhooks {
    public static function emit(string $type,array $payload): void {
        (new \Latra\Application\Notification\EmitWebhook(
            new \Latra\Infrastructure\Persistence\PdoWebhookEventRepository(static fn(): PDO => Database::getInstance()),
            new \Latra\Infrastructure\Notification\CurlWebhookTransport(),
            static fn(): string => Schema::config('webhook_url',''), static fn(): string => gmdate('c'),
            static function(string $event,array $context,bool $error): void {
                if ($error) Logger::error($event,$context); else Logger::info($event,$context);
            },
        ))->emit($type,$payload);
    }
}
