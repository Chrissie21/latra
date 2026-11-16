<?php
namespace Latra\Application\Notification;

interface WebhookTransport { public function send(string $url,string $body): bool; }
