<?php
namespace Latra\Application\Notification;

interface WebhookEventRepository { public function enqueue(string $type,array $payload): void; }
