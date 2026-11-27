<?php
declare(strict_types=1);
namespace Latra\Infrastructure\Simulation;

use Latra\Infrastructure\Wialon\WialonApiClient;
use Latra\Infrastructure\Wialon\CurlWialonTransport;
use Latra\Infrastructure\Wialon\WialonSourceReader;
use RuntimeException;

/** Read only one bounded current window; never boots the app or opens its database. */
final class WialonCapture
{
    public static function fetch(string $envFile, string $unitName, int $seconds): array
    {
        if (trim($unitName)==='') throw new RuntimeException('Provide --unit=<name-or-ID> for a live Wialon capture');
        if ($seconds < 60 || $seconds > 3600) throw new RuntimeException('Window must be 60–3600 seconds');
        $config = [];
        foreach (is_readable($envFile) ? file($envFile, FILE_IGNORE_NEW_LINES) : [] as $line) {
            if (!str_contains($line, '=') || str_starts_with(trim($line), '#')) continue;
            [$key,$value] = explode('=', $line, 2);
            if (in_array(trim($key), ['WIALON_BASE_URL','WIALON_TOKEN'], true)) {
                $config[trim($key)] = trim(trim($value), "\"'");
            }
        }
        $base = (string) (getenv('WIALON_BASE_URL') ?: ($config['WIALON_BASE_URL'] ?? 'https://hst-api.wialon.com'));
        $token = (string) (getenv('WIALON_TOKEN') ?: ($config['WIALON_TOKEN'] ?? ''));
        if ($token === '') throw new RuntimeException('WIALON_TOKEN is required in the environment or .env');
        $client = new WialonApiClient($base, $token, new CurlWialonTransport());
        if (!$client->login()) throw new RuntimeException('Wialon login failed');
        try {
            $unit = null;
            foreach ($client->getUnits() as $candidate) {
                if (($candidate['nm'] ?? '') === $unitName || (string) ($candidate['id'] ?? '') === $unitName) {
                    $unit = $candidate; break;
                }
            }
            if ($unit === null) throw new RuntimeException('Requested Wialon unit not found');
            $to = time(); $from = $to - $seconds;
            $messages = $client->getCompleteWindow((int) $unit['id'], $from, $to, 100, 2000, 2097152);
            $vehicle = (new WialonSourceReader())->unitFields($unit);
            $vehicle = array_intersect_key($vehicle, array_flip(['wialon_unit_id','vehicle_reg_no','imei','hw_type']));
            $vehicle['id'] = 1; // local simulation identity only, never an owner DB id
            return ['source'=>'live-wialon', 'captured_at'=>gmdate('c'), 'from'=>$from, 'to'=>$to,
                'vehicle'=>$vehicle, 'messages'=>$messages];
        } finally { $client->logout(); }
    }
}
