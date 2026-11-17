<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Latra;

/**
 * Reachability probe for the configured LATRA endpoint.
 *
 * The probe sends no payload: it reports DNS/TLS/HTTP reachability and whether the
 * path presents an authentication challenge.
 */
final class LatraEndpointProbe
{
    public function __construct(private string $url, private string $username, private string $password) {}

    public function probe(): array
    {
        if ($this->url === '') {
            return ['success' => false, 'message' => 'LATRA_API_URL is not configured in .env'];
        }
        $curl = curl_init($this->url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($this->username . ':' . $this->password),
            ],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $start = microtime(true);
        curl_exec($curl);
        $error = curl_error($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $latency = (int) round((microtime(true) - $start) * 1000);
        curl_close($curl);
        if ($code === 0) {
            return ['success' => false, 'message' => 'Unreachable: ' . ($error ?: 'no response')];
        }
        $auth = in_array($code, [401, 403], true)
            ? 'Basic-auth check: credentials ' . ($code === 401 ? 'rejected' : 'insufficient') . '.'
            : 'No auth challenge at this path.';
        return ['success' => true,
            'message' => 'Endpoint reachable (HTTP ' . $code . ' in ' . $latency . ' ms). ' . $auth];
    }
}
