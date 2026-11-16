<?php
namespace Latra\Infrastructure\Notification;

use Latra\Application\Notification\WebhookTransport;

final class CurlWebhookTransport implements WebhookTransport
{
    public function send(string $url,string $body): bool
    {
        $ch=curl_init($url);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_TIMEOUT=>5,CURLOPT_SSL_VERIFYPEER=>true]);
        $ok=curl_exec($ch)!==false && (int)curl_getinfo($ch,CURLINFO_HTTP_CODE)<400;
        curl_close($ch);
        return $ok;
    }
}
