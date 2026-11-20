<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Delivery;

use InvalidArgumentException;

/**
 * Real LATRA delivery transport.
 *
 * Reuses the wire format from the retired direct-send adapter: POST JSON
 * with Basic authentication and the persisted delivery key, where acceptance is
 * HTTP 200 with {"status":"1"}. Actual LATRA acceptance remains unverified.
 * A started attempt can outlast the flush loop's soft deadline.
 *
 * Authentication material, retry scheduling, attempt budgets and review
 * classification remain upstream; this adapter only performs one HTTP attempt
 * and reports what the endpoint answered.
 */
final class LatraRelayTransport
{
    private const MAX_RESPONSE_BYTES = 65536;

    public function __construct(
        private string $url,
        private string $username,
        private string $password,
        private int $timeoutSeconds = 10,
        private int $connectTimeoutSeconds = 8,
    ) {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)
            || (string) parse_url($url, PHP_URL_HOST) === ''
            || preg_match('/\s/', $url) === 1) {
            throw new InvalidArgumentException('An explicit http(s) LATRA URL is required');
        }
        if ($username === '' || $password === '') {
            throw new InvalidArgumentException('LATRA credentials are required for the real transport');
        }
        if ($timeoutSeconds < 1 || $connectTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Positive transport timeouts required');
        }
    }

    /** @return array{code:int,data:array|null,error:string} */
    public function __invoke(string $body, string $key): array
    {
        if ($key === '') {
            throw new InvalidArgumentException('A persisted delivery key is required');
        }

        $response = '';
        $curl = curl_init($this->url);
        if ($curl === false) {
            return ['code' => 0, 'data' => null, 'error' => 'Unable to initialise the HTTP client'];
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
                'X-Idempotency-Key: ' . $key,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
                if (strlen($response) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    return 0;
                }
                $response .= $chunk;
                return strlen($chunk);
            },
        ]);

        $executed = curl_exec($curl);
        $error = $executed === false ? (curl_error($curl) ?: 'HTTP request failed') : '';
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        // Credentials never travel in an error string; only transport facts do.
        $decoded = $error === '' ? json_decode($response, true) : null;
        return [
            'code' => $code,
            'data' => is_array($decoded) ? $decoded : null,
            'error' => $error,
        ];
    }
}
