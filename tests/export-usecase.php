<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Export\ExportPushLog;
use Latra\Application\Export\PushLogExportRepository;
use Latra\Infrastructure\Http\CsvDownload;
$repository = new class implements PushLogExportRepository {
    public array $requests = [];
    public int $ceilings = 0;
    public function ceiling(): int { $this->ceilings++; return 900; }
    public function page(string $status, int $ceiling, ?array $cursor, int $limit): array {
        $this->requests[] = [$status,$ceiling,$cursor,$limit];
        if (count($this->requests) === 3) return [];
        $rows = [];
        $start = $cursor === null ? 900 : 400;
        for ($id=$start; $id>$start-500; $id--) $rows[] = [
            'id'=>$id,'vehicle_reg_no'=>'SYNTHETIC','activity_name'=>null,'activity_id'=>2,
            'http_code'=>200,'response_status'=>'1','latency_ms'=>4,
            'created_at'=>$cursor === null ? '2026-01-01 00:00:00' : null,
            'response_body'=>str_repeat('é',600),
        ];
        return $rows;
    }
};
$rows = iterator_to_array((new ExportPushLog($repository))->rows('sent'));
if (count($rows) !== 1000 || $repository->ceilings !== 1 || count($repository->requests) !== 3
    || $repository->requests[1] !== ['sent',900,['time'=>'2026-01-01 00:00:00','id'=>401],500]
    || $repository->requests[2] !== ['sent',900,['time'=>null,'id'=>-99],500]
    || $rows[0][2] !== 2 || $rows[0][4] !== 'Sent' || mb_strlen($rows[0][7]) !== 500) {
    throw new RuntimeException('Export ceiling, cursor, bounded paging or output contract changed');
}
$out = fopen('php://temp','w+');
CsvDownload::write($out, ['id','value'], [[1,'a,"b'],[2,'é']]);
rewind($out); $csv = stream_get_contents($out); fclose($out);
if (!str_starts_with($csv,"\xEF\xBB\xBFid,value\n") || !str_contains($csv,'"a,""b"') || !str_contains($csv,'2,é')) {
    throw new RuntimeException('CSV UTF-8 BOM or quoting changed');
}
echo "PASS bounded export orchestration, stable ceiling, timestamp/null cursors, row and CSV encoding\n";
