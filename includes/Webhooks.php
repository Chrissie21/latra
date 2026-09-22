<?php
/**
 * Webhook sender.
 */
class Webhooks {
    public static function emit(string $type, array $payload): void {
        try {
            Database::insert('webhook_events', [
                'event_type' => $type,
                'payload' => json_encode($payload),
                'status' => 'pending',
            ]);
        } catch (Throwable $e) {
            Logger::error('webhook.queue_failed', ['type' => $type]);
        }
        $url = Schema::config('webhook_url', '');
        if ($url === '') {
            return;
        }
        $body = json_encode(['type' => $type, 'at' => gmdate('c'), 'data' => $payload]);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $ok = curl_exec($ch) !== false && (int) curl_getinfo($ch, CURLINFO_HTTP_CODE) < 400;
        curl_close($ch);
        Logger::info('webhook.sent', ['type' => $type, 'ok' => $ok]);
    }
}
