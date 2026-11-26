<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use InvalidArgumentException;
use Latra\Application\Collection\WialonTransport;

/** cURL transport for the Wialon ajax API with bounded response decoding. */
final class CurlWialonTransport implements WialonTransport
{
    public function post(string $url, string $encodedParams, ?int $maxResponseBytes): ?array
    {
        if ($maxResponseBytes !== null && $maxResponseBytes < 1) {
            throw new InvalidArgumentException('Positive response bound required');
        }

        $boundedBody = '';
        $handle = curl_init($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $encodedParams);
        curl_setopt($handle, CURLOPT_TIMEOUT, 20);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($handle, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
        if ($maxResponseBytes !== null) {
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($curl, string $chunk) use (
                &$boundedBody,
                $maxResponseBytes,
            ): int {
                if (strlen($boundedBody) + strlen($chunk) > $maxResponseBytes) {
                    return 0;
                }
                $boundedBody .= $chunk;
                return strlen($chunk);
            });
        }

        $response = curl_exec($handle);
        $httpCode = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200 || $response === false) {
            error_log("Wialon API HTTP Error: $httpCode");
            return null;
        }
        if ($maxResponseBytes !== null) {
            $response = $boundedBody;
        }

        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
