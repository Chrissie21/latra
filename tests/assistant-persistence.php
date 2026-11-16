<?php
/** Synthetic SQL fixture for conversation ownership and every approved operational query. */
require __DIR__.'/bootstrap.php';
use Latra\Infrastructure\Persistence\PdoAssistantRepository;
use Latra\Infrastructure\Persistence\PdoAssistantReadRepository;
use Latra\Application\AI\ReadOperationalTools;
class AssistantTestPDO extends LocalTestPDO {
    public function exec(string $sql): int|false {
        $sql=preg_replace('/\b(?:INT|BIGINT) PRIMARY KEY AUTO_INCREMENT\b/','INTEGER PRIMARY KEY',$sql);
        $sql=preg_replace('/,\s*INDEX\s+\w+\s*\([^)]*\)/','',$sql);
        return parent::exec(str_replace([' ENGINE=InnoDB',' ON UPDATE CURRENT_TIMESTAMP'],'',$sql));
    }
    public function prepare(string $sql,array $options=[]): PDOStatement|false {
        $sql=preg_replace('/DATE_SUB\(NOW\(\), INTERVAL (\?|[0-9]+) (HOUR|DAY|MINUTE)\)/',"datetime('now','-' || $1 || ' $2')",$sql);
        return parent::prepare(str_replace('CURDATE()',"date('now')",$sql),$options);
    }
}
$pdo=getenv('LATRA_TEST_DSN') ? testDatabase() : new AssistantTestPDO();
$tables=[
'vehicles'=>'id INTEGER PRIMARY KEY,wialon_unit_id INTEGER,vehicle_reg_no VARCHAR(40),name TEXT,imei TEXT,hw_type TEXT,phone TEXT,is_active INTEGER,last_sync_at DATETIME,lat REAL,lng REAL,altitude REAL,speed REAL,bearing REAL,satellite_count INTEGER,hdop REAL,last_message_time BIGINT',
'activity_mappings'=>'vehicle_id INTEGER,activity_id INTEGER,is_enabled INTEGER,push_count INTEGER,fail_count INTEGER',
'activity_definitions'=>'id INTEGER PRIMARY KEY,activity_name TEXT',
'device_status'=>'vehicle_id INTEGER PRIMARY KEY,is_online INTEGER,last_comm_at DATETIME,internal_battery_low INTEGER,internal_battery_pct REAL,external_power_disconnected INTEGER,tampered INTEGER',
'message_log'=>'id INTEGER PRIMARY KEY,vehicle_id INTEGER,wialon_msg_time BIGINT,activity_id INTEGER,speed REAL,latra_status VARCHAR(20)',
'push_log'=>'id INTEGER PRIMARY KEY,vehicle_id INTEGER,activity_id INTEGER,http_code INTEGER,response_status VARCHAR(10),latency_ms INTEGER,created_at DATETIME,response_body TEXT,attempt INTEGER,lat_lng_status TEXT',
'jobs'=>'job_key VARCHAR(60) PRIMARY KEY,label TEXT,interval_seconds INTEGER,is_enabled INTEGER,last_run_at DATETIME,next_run_at DATETIME,locked_until DATETIME,last_status VARCHAR(20),last_error TEXT,run_count INTEGER',
'job_runs'=>'id INTEGER PRIMARY KEY,job_key VARCHAR(60),status VARCHAR(20),started_at DATETIME,finished_at DATETIME,duration_ms INTEGER,items_processed INTEGER,error TEXT',
'automation_buckets'=>'id INTEGER PRIMARY KEY,bucket_name TEXT',
'automation_runs'=>'id INTEGER PRIMARY KEY,bucket_id INTEGER,status VARCHAR(20),started_at DATETIME,finished_at DATETIME,total_count INTEGER,success_count INTEGER,failed_count INTEGER,error_message TEXT,triggered_by VARCHAR(10),duration_seconds INTEGER',
'outbox'=>'id INTEGER PRIMARY KEY,status VARCHAR(20),created_at DATETIME',
'emergency_events'=>'id INTEGER PRIMARY KEY,vehicle_id INTEGER,event_type TEXT,activity_id INTEGER,status VARCHAR(20),started_at DATETIME,ended_at DATETIME,trigger_reason TEXT,max_speed_kmh REAL,record_count INTEGER',
];
foreach (['ai_chat_log','ai_conversations','automation_run_items','push_log','outbox','message_log','device_status','activity_mappings','activity_definitions','job_runs','jobs','automation_runs','automation_buckets','emergency_events','vehicles'] as $name) $pdo->exec('DROP TABLE IF EXISTS '.$name);
foreach ($tables as $name=>$schema) $pdo->exec("CREATE TABLE $name ($schema)");
$conversation=new PdoAssistantRepository($pdo);$conversation->ensure();$conversation->ensure();
$conversation->createConversation('synthetic_123',7,'Synthetic');
expect($conversation->conversation('synthetic_123',7)!==null && $conversation->conversation('synthetic_123',8)===null,'Conversation lookup leaked another owner');
foreach ([['user','chat','first'],['tool','tool','not history'],['assistant','chat','last']] as [$role,$kind,$content]) {
    $conversation->store(['conversation_id'=>'synthetic_123','user_id'=>7,'role'=>$role,'kind'=>$kind,'content'=>$content,'tools_used'=>null,'model'=>'synthetic','latency_ms'=>1,'tokens'=>2]);
}
expect($conversation->history('synthetic_123',7,2)===[['role'=>'assistant','content'=>'last'],['role'=>'user','content'=>'first']]
    && $conversation->history('synthetic_123',8,8)===[],'Bounded chat-only owned history changed');
$now=time();$date=gmdate(DATE_FORMAT,$now);
$seed=static function(string $table,array $values) use ($pdo): void {
    $slots=implode(',',array_fill(0,count($values),'?'));
    $pdo->prepare("INSERT INTO $table VALUES ($slots)")->execute($values);
};
$seed('vehicles',[1,123,'SYNTHETIC','Synthetic','synthetic','fixture','',1,$date,-6.8,39.2,5,0,90,8,1,$now]);
$pdo->exec("INSERT INTO activity_definitions VALUES (2,'Engine ON')");
$pdo->exec('INSERT INTO activity_mappings VALUES (1,2,1,0,0)');
$seed('device_status',[1,1,$date,0,80,0,0]);
$seed('message_log',[1,1,$now,2,0,'sent']);
$seed('push_log',[1,1,2,200,'1',10,$date,'fixture',1,null]);
$seed('jobs',['fixture','Synthetic',180,1,$date,null,null,'ok',null,1]);
$seed('job_runs',[1,'fixture','failed',$date,$date,10,0,'synthetic']);
$pdo->exec("INSERT INTO automation_buckets VALUES (1,'Synthetic bucket')");
$seed('automation_runs',[1,1,'completed',$date,$date,1,1,0,null,'schedule',1]);
$seed('outbox',[1,'pending',$date]);
$seed('emergency_events',[1,1,'fixture',4,'active',$date,null,'synthetic',80,1]);
$runtime=new Latra\Infrastructure\AI\ConfiguredAssistantRuntime(static fn(string $key,string $fallback): string => $fallback,
    static fn(): array => (new Latra\Infrastructure\Persistence\PdoHealthRepository($pdo))->deviceSummary(),['wialon'=>false,'latra'=>false]);
$logs=new class implements Latra\Application\AI\AssistantLogReader { public function lines(int $limit,int $hours,?string $term): array { return []; } };
$tools=new ReadOperationalTools(new PdoAssistantReadRepository(static fn(): PDO => $pdo),$runtime,$logs,'UTC',DATE_FORMAT,static fn(): int => $now);
foreach ($tools->schemas() as $schema) {
    $args=[];
    foreach ($schema['function']['parameters']['required'] ?? [] as $key) $args[$key]=$key==='term' ? 'SYNTHETIC' : ($key==='activity_id' ? 2 : 1);
    $result=$tools->execute($schema['function']['name'],$args);
    expect(($result['ok'] ?? false)===true,'Approved tool SQL failed: '.$schema['function']['name'].' '.json_encode($result));
}
foreach (['get_recent_latra_submissions'=>['vehicle_id'=>1],'get_recent_events'=>['activity_id'=>2],
    'get_scheduler_runs'=>['job_key'=>'fixture'],'get_gps_health'=>['vehicle_id'=>1],'get_device_health'=>['vehicle_id'=>1]] as $name=>$args) {
    $result=$tools->execute($name,$args);expect($result['ok']===true,'Filtered approved query failed: '.$name.' '.json_encode($result));
}
expect($tools->execute('get_failed_latra_submissions',[])['data']['total_failures']===0,'Historical HTTP failure report counted successful receipts');
expect($tools->execute('get_vehicle_details',['vehicle_id'=>999])===['ok'=>false,'error'=>'Tool failed: Vehicle not found'],'Missing vehicle became invented tool data');
$pdo->exec('DELETE FROM automation_buckets');
expect($tools->execute('get_automation_runs',[])['data'][0]['bucket']==='bucket#1','Missing bucket label lost the original bucket identity');
echo "PASS conversation ownership/history and all approved assistant reads on synthetic SQL fixtures\n";
echo $pdo instanceof LocalTestPDO ? "SQLite SQL/state checks only; MySQL/MariaDB remains unverified by this run.\n" : "Disposable native MySQL/MariaDB SQL/state checks passed.\n";
