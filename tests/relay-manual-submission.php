<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Collection\QueueManualRelayPayload;
use Latra\Application\Collection\RelayWindowCollector;
$collector = new class implements RelayWindowCollector {
    public array $calls = [];
    public function collectWindow(string $unit, int $from, int $to, int $pageSize, int $maxRecords, int $maxBytes, callable $fetchPage, callable $map): array {
        $page = $fetchPage(0, 1);
        try { $mapped=$map($page['messages'][0],[]); }
        catch(Latra\Domain\Exception\RequiresReview $e) { return ['queued'=>1,'rejected'=>1]; }
        $this->calls[] = [$unit, $from, $to, $pageSize, $maxRecords, $maxBytes, $mapped];
        return ['queued'=>1];
    }
};
$queue = new QueueManualRelayPayload($collector, 8192);
$vehicle = ['id'=>7, 'wialon_unit_id'=>123, 'imei'=>'123456789012345','vehicle_reg_no'=>'SYNTHETIC'];
$payload = ['type'=>'poi','imei'=>$vehicle['imei'],'vehicle_reg_no'=>'SYNTHETIC','items'=>[['activity_id'=>'1',
    'timestamp'=>(string)((time()-1)*1000),'latitude'=>'-6.8','longitude'=>'39.2','altitude'=>'50',
    'horizontal_speed'=>'20','bearing'=>'90','satellite_count'=>'12','vertical_speed'=>'0','HDOP'=>'1',
    'd2d3'=>'2','RSSI'=>'0','LAC'=>'0','Cell_ID'=>'0','MGS_ID'=>'1','MCC'=>'0','odometer'=>'0',
    'addon_info'=>'']]];
$result = $queue->queue($vehicle, $payload);
if ($result['success'] !== true || $result['queued'] !== 1 || count($collector->calls) !== 1
    || !str_starts_with($collector->calls[0][0], 'manual:wialon:123:')
    || $collector->calls[0][6]['payload'] !== $payload) throw new RuntimeException('Manual submission contract changed');
foreach ([$payload+[], array_replace($payload,['imei'=>'wrong']), array_replace($payload,['items'=>[]])] as $i=>$candidate) {
    if ($i === 0) { $queue->queue($vehicle, $candidate); continue; }
    try { $r=$queue->queue($vehicle,$candidate); if($i===2 && $r['review']) continue; throw new LogicException('Invalid manual payload queued'); }
    catch (Latra\Domain\Exception\InvalidSubmission) {}
}
if ($collector->calls[0][0] !== $collector->calls[1][0]) throw new RuntimeException('Manual replay identity changed');
echo "PASS manual submission identity, immutable source mapping and invalid-payload rejection\n";
