<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
use Latra\Application\AI\AssistantReadQuery;
use Latra\Application\AI\AssistantReadRepository;
use Latra\Application\AI\AssistantRuntime;
use Latra\Application\AI\AssistantLogReader;
use Latra\Application\AI\ReadOperationalTools;
function toolAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
$repo=new class implements AssistantReadRepository {
    public array $calls=[];
    public function one(AssistantReadQuery $query,array $params=[],bool $filtered=false): ?array { $this->calls[]=[$query->value,$params,$filtered];return null; }
    public function many(AssistantReadQuery $query,array $params=[],bool $filtered=false): array { $this->calls[]=[$query->value,$params,$filtered];return []; }
};
$runtime=new class implements AssistantRuntime {
    public function config(string $key,string $fallback): string { return $fallback; }
    public function sourceConfigured(string $name): bool { return false; }
    public function deviceSummary(): array { return []; }
};
$logs=new class implements AssistantLogReader {
    public array $calls=[];
    public function lines(int $limit,int $hours,?string $term): array { $this->calls[]=[$limit,$hours,$term];return ['synthetic log']; }
};
$tools=new ReadOperationalTools($repo,$runtime,$logs,'UTC','Y-m-d H:i:s',static fn(): int => 2000000000);
toolAssert($tools->execute('run_shell',['command'=>'anything'])===['ok'=>false,'error'=>'Unknown tool: run_shell'] && $repo->calls===[], 'Unknown operation escaped approved tool dispatch');
toolAssert($tools->execute('search_vehicle',['term'=>'abc'])===['ok'=>true,'data'=>[]]
    && end($repo->calls)[1]===['%abc%','%abc%','%abc%'], 'Vehicle search binding/shape changed');
$tools->execute('get_recent_latra_submissions',['vehicle_id'=>7,'limit'=>9999]);
toolAssert(end($repo->calls)[1]===[7,50] && end($repo->calls)[2]===true, 'Vehicle filter or submission limit changed');
$tools->execute('get_scheduler_runs',['job_key'=>'synthetic','limit'=>0]);
toolAssert(end($repo->calls)[1]===['synthetic',1] && end($repo->calls)[2]===true, 'Job filter or minimum limit changed');
$tools->execute('get_recent_events',['hours'=>999,'activity_id'=>4,'limit'=>500]);
toolAssert(end($repo->calls)[1]===[1,2000000000-168*3600,4,50] && end($repo->calls)[2]===true, 'Event exclusion/time/work bounds changed');
$tools->execute('get_error_logs',['hours'=>999,'limit'=>999]);
toolAssert($logs->calls===[[100,168,null]], 'Log bounds changed');
$names=array_column(array_column($tools->schemas(),'function'),'name');
toolAssert(count($names)>20 && count(array_unique($names))===count($names), 'Approved tool catalog incomplete/ambiguous');
foreach ($tools->schemas() as $schema) {
    $args=[];
    foreach ($schema['function']['parameters']['required'] ?? [] as $key) $args[$key]=$key==='term' ? 'fixture' : 7;
    $result=$tools->execute($schema['function']['name'],$args);
    toolAssert(isset($result['ok']) && (!isset($result['error']) || $result['error']==='Tool failed: Vehicle not found'), 'Approved tool failed unexpectedly: '.$schema['function']['name'].' '.json_encode($result));
}
$dir=sys_get_temp_dir().'/latra-ai-log-'.bin2hex(random_bytes(6));mkdir($dir,0700);
try {
    file_put_contents($dir.'/error.log',"2026-01-01 00:00:00 old\n2033-05-18 03:33:20 synthetic recent\n");
    file_put_contents($dir.'/private.txt','must not be read');
    $reader=new Latra\Infrastructure\AI\FilesystemAssistantLogReader($dir,static fn(): int => 2000000000);
    toolAssert($reader->lines(5,24,null)===['2033-05-18 03:33:20 synthetic recent'], 'Log reader escaped fixed files or age bounds');
} finally { foreach (glob($dir.'/*') ?: [] as $file) unlink($file);rmdir($dir); }
echo "PASS approved read-only tool catalog/dispatch, parameter/work bounds, missing evidence and fixed log scope\n";
