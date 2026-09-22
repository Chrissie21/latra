<?php
/**
 * LATRA API client.
 */
class LatraAdapter {
    private static int $sentThisMinute = 0;
    private static int $minuteBucket = 0;
    private static int $consecutiveFailures = 0;
    private static int $openUntil = 0;

    public static function submit(array $payload, string $idempotencyKey = ''): array {
        $url = Vault::latraUrl();
        if ($url === '') {
            return self::fail(0, 'LATRA_API_URL is not configured');
        }
        if (!self::allow()) {
            return self::fail(429, 'Local rate limit: LATRA per-minute cap reached');
        }
        // Stop repeated failed calls for a short cooldown.
        if (self::$consecutiveFailures >= 5 && time() < self::$openUntil) {
            return self::fail(503, 'LATRA circuit breaker open', true);
        }

        if (!empty($payload['items'][0]) && $idempotencyKey !== '') {
            $payload['items'][0]['idempotency_key'] = $idempotencyKey;
        }

        $body = json_encode($payload);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode(Vault::latraUser() . ':' . Vault::latraPass()),
                'X-Idempotency-Key: ' . $idempotencyKey,
            ],
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $start = microtime(true);
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $latency = (int) round((microtime(true) - $start) * 1000);
        if ($response === false) {
            $response = $curlError ?: '';
        }
        $reachable = $httpCode > 0;
        if (!$reachable) {
            self::$consecutiveFailures++;
            if (self::$consecutiveFailures >= 5) {
                self::$openUntil = time() + 60;
            }
        } else {
            self::$consecutiveFailures = 0;
            self::$openUntil = 0;
        }
        $responseData = json_decode((string) $response, true) ?: [];
        $ok = $httpCode === 200 && ($responseData['status'] ?? null) === '1';
        return compact('payload', 'response', 'responseData', 'httpCode', 'latency', 'ok', 'curlError');
    }

    public static function recordPush(array $msg, int $activityId, array $result, string $claimId = '', int $attempt = 1): void {
        Database::insert('push_log', [
            'vehicle_id' => $msg['vehicle_id'],
            'activity_id' => $activityId,
            'message_log_id' => $msg['msg_id'] ?? $msg['id'] ?? $msg['message_id'] ?? null,
            'payload' => json_encode($result['payload']),
            'response_status' => $result['responseData']['status'] ?? null,
            'response_body' => substr((string) ($result['response'] ?: $result['curlError']), 0, 8000),
            'http_code' => $result['httpCode'],
            'latency_ms' => $result['latency'],
            'claim_id' => $claimId ?: null,
            'attempt' => $attempt,
        ]);
    }

    private static function allow(): bool {
        $nowMin = intdiv(time(), 60);
        if (self::$minuteBucket !== $nowMin) {
            self::$minuteBucket = $nowMin;
            self::$sentThisMinute = 0;
        }
        $cap = Schema::configInt('latra_rate_per_minute', 300);
        if (self::$sentThisMinute >= $cap) {
            return false;
        }
        self::$sentThisMinute++;
        return true;
    }

    private static function fail(int $http, string $msg, bool $breaker = false, bool $skipped = false): array {
        if ($breaker) {
            self::$consecutiveFailures++;
            if (self::$consecutiveFailures >= 5) {
                self::$openUntil = time() + 60;
            }
        }
        return [
            'payload' => [],
            'response' => $msg,
            'responseData' => [],
            'httpCode' => $http,
            'latency' => 0,
            'ok' => false,
            'curlError' => $msg,
            'skipped' => $skipped,
        ];
    }
}
