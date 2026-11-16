<?php
namespace Latra\Infrastructure\Security;

use Latra\Domain\Security\ClientDevicePolicy;

final class FileLoginAttemptLogger
{
    public function __construct(private ?string $directory, private array $server, private bool $resolveDns) {}
    public function log(string $username, string $result, array $extra = []): void {
        if ($this->directory === null) {
            return;
        }
        $ua = (string) ($this->server['HTTP_USER_AGENT'] ?? '');
        $dev = ClientDevicePolicy::parse($ua);

        $row = array_merge($extra, [
            'ts'               => gmdate('Y-m-d\TH:i:s\Z'),
            'event'            => 'login.attempt',
            'username'         => $username,
            'result'           => $result,
            'ip'               => (string) ($this->server['REMOTE_ADDR'] ?? ''),
            'x_forwarded_for'  => (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? ''),
            'x_real_ip'        => (string) ($this->server['HTTP_X_REAL_IP'] ?? ''),
            'cf_connecting_ip' => (string) ($this->server['HTTP_CF_CONNECTING_IP'] ?? ''),
            'os'               => $dev['os'],
            'os_version'       => $dev['os_version'],
            'browser'          => $dev['browser'],
            'browser_version'  => $dev['browser_version'],
            'device'           => $dev['device'],
            'ua'               => $ua,
            'accept_language'  => (string) ($this->server['HTTP_ACCEPT_LANGUAGE'] ?? ''),
            'referer'          => (string) ($this->server['HTTP_REFERER'] ?? ''),
            'origin'           => (string) ($this->server['HTTP_ORIGIN'] ?? ''),
            'https'            => !empty($this->server['HTTPS']) || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
            'http_version'     => (string) ($this->server['SERVER_PROTOCOL'] ?? ''),
            'method'           => (string) ($this->server['REQUEST_METHOD'] ?? ''),
        ]);

        // Optional reverse-DNS hostname of the client IP (adds latency; opt-in).
        if ($this->resolveDns && $row['ip'] !== '') {
            $dns = @gethostbyaddr($row['ip']);
            $row['dns_hostname'] = ($dns && $dns !== $row['ip']) ? $dns : '';
        }

        @file_put_contents($this->directory . '/login.log', json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
}
