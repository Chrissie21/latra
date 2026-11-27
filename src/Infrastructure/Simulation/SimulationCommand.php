<?php
declare(strict_types=1);
namespace Latra\Infrastructure\Simulation;

use Latra\Application\Collection\RelayPayloadMapper;
use Latra\Domain\Delivery\VtsBatchPolicy;
use Latra\Infrastructure\Delivery\LoopbackRelayTransport;
use Latra\Infrastructure\Persistence\VehicleRowDecoder;
use Latra\Infrastructure\Wialon\WialonSignalReader;
use Latra\Infrastructure\Wialon\WialonSourceReader;
use RuntimeException;

/** Isolated test harness: real mapper + HTTP, with inspectable file outbox and no app database. */
final class SimulationCommand
{
    public static function run(array $options, string $root): void
    {
        $output = (string) ($options['output'] ?? '');
        $url = (string) ($options['receiver'] ?? 'http://127.0.0.1:8090/data-integration/integration/gps');
        $transport = new LoopbackRelayTransport($url); // validates destination before any source read
        $retry = isset($options['retry']);
        if ($retry) $output = (string) $options['retry'];
        if ($output === '' || ($retry ? !is_dir($output) : (file_exists($output) || !mkdir($output, 0700, true)))) {
            throw new RuntimeException('Provide --output=<new-directory> or --retry=<existing-directory>');
        }
        $lock = fopen($output . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('This simulation is already running');
        chmod($output . '/.lock',0600);
        $write = static function(string $file, mixed $value) use ($output): void {
            self::writeJson($output . '/' . $file, $value);
        };
        if ($retry) {
            $pending = json_decode(file_get_contents($output . '/pending.json'), true, 512, JSON_THROW_ON_ERROR);
            self::dispatch($pending, $transport, $write);
            if ($pending !== []) throw new RuntimeException('Saved requests remain unconfirmed; inspect delivery-results.json');
            echo "Retried saved requests; inspect $output/pending.json and delivery-results.json\n";
            return;
        }
        if (isset($options['input'])) {
            $input = (string) $options['input'];
            if (!is_file($input) || filesize($input) > 4194304) throw new RuntimeException('Input must be a JSON capture of at most 4 MiB');
            $capture = json_decode(file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
        } else {
            $capture = WialonCapture::fetch($root . '/.env', (string) ($options['unit'] ?? ''), (int) ($options['seconds'] ?? 600));
        }
        if (!is_array($capture['vehicle'] ?? null) || !is_array($capture['messages'] ?? null)
            || !array_is_list($capture['messages']) || count($capture['messages']) > 2000) {
            throw new RuntimeException('Capture requires vehicle and bounded messages array');
        }
        $write('source.json', $capture);
        $mapper = new RelayPayloadMapper(VehicleRowDecoder::decode($capture['vehicle']), array_fill_keys(range(1,24),true), range(1,24),
            (float) ($options['speed-limit'] ?? 80), 2, 3200, (string) ($options['profile'] ?? 'generic'),
            new WialonSignalReader(), new WialonSourceReader());
        $messages = $capture['messages'];
        usort($messages, static fn($a,$b)=>($a['t']??0)<=>($b['t']??0));
        $state = []; $reports = []; $review = []; $nextId = 1;
        foreach ($messages as $raw) {
            try {
                $mapped = $mapper->map($raw,$state);
                $state = $mapped['state'];
                if ($mapped['payload'] === null) continue;
                $payload=$mapped['payload'];
                foreach ($payload['items'] as &$item) $item['MGS_ID']=(string)($nextId*32+(int)$item['activity_id']);
                unset($item);
                $reports[]=['id'=>$nextId++, 'delivery_key'=>hash('sha256', json_encode($capture['vehicle']).json_encode($raw)),
                    'source_time'=>$raw['t'], 'payload'=>json_encode($payload,JSON_THROW_ON_ERROR),
                    'status'=>'pending','attempts'=>0,'next_attempt'=>0];
            } catch (\Throwable $e) { $review[]=['raw'=>$raw,'reason'=>$e->getMessage()]; }
        }
        $write('mapping-review.json',$review);
        $write('mapped-reports.json',$reports);
        $results=[]; $batches=[];
        while ($reports !== []) {
            $batch=VtsBatchPolicy::assemble($reports,time());
            if ($batch===null) break;
            $stored=json_encode($batch,JSON_THROW_ON_ERROR);
            $members=$batch[VtsBatchPolicy::META];
            $body=VtsBatchPolicy::wireBody($stored);
            $key=hash('sha256',$body);
            $batches[]=['key'=>$key,'body'=>$body,'members'=>$members];
            array_splice($reports,0,count($members));
        }
        // Exact bodies saved before HTTP; ambiguous/failed responses remain in pending.json.
        $write('batches.json',$batches);
        $write('tail.json',$reports);
        $write('pending.json',$batches);
        $results = self::dispatch($batches, $transport, $write);
        $summary=['source'=>$capture['source']??'supplied-capture','source_messages'=>count($messages),
            'mapping_review'=>count($review),'requests_attempted'=>count($results),
            'requests_accepted'=>count(array_filter($results,static fn($r)=>$r['accepted'])),
            'pending_requests'=>count($batches),'tail_reports'=>count($reports),
            'receiver'=>$url,'profile'=>$options['profile']??'generic'];
        $write('summary.json',$summary);
        echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\nEvidence: $output\n";
        if ($messages===[]) throw new RuntimeException('No Wialon messages in the selected window; no data was fabricated');
        if ($review!==[] || $batches!==[]) throw new RuntimeException('Inspect mapping-review.json or pending.json before continuing');
    }

    private static function dispatch(array &$batches, LoopbackRelayTransport $transport, callable $write): array
    {
        $results = [];
        foreach ($batches as $i=>$batch) {
            $errors=\Latra\Domain\Ingestion\LatraPayloadPolicy::validatePayload(json_decode($batch['body'],true,512,JSON_THROW_ON_ERROR));
            if($errors) { $write('delivery-review.json',['key'=>$batch['key'],'errors'=>$errors]); break; }
            $response=$transport($batch['body'],$batch['key']);
            $accepted=$response['code']===200 && (string)($response['data']['status']??'')==='1';
            $results[]=['key'=>$batch['key'],'accepted'=>$accepted,'response'=>$response];
            if (!$accepted) break;
            unset($batches[$i]);
            $write('pending.json',array_values($batches));
        }
        $write('delivery-results.json',$results);
        return $results;
    }

    private static function writeJson(string $path, mixed $value): void
    {
        $body = json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temp = $path . '.tmp';
        $handle = fopen($temp, 'wb');
        if ($handle === false) throw new RuntimeException('Evidence storage unavailable');
        try {
            chmod($temp,0600);
            if (fwrite($handle,$body)!==strlen($body) || !fflush($handle) || !fsync($handle)) {
                throw new RuntimeException('Evidence write failed');
            }
        } finally { fclose($handle); }
        if (!rename($temp,$path)) throw new RuntimeException('Evidence finalization failed');
    }
}
