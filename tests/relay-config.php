<?php
/** Delivery is relay-only; a retired or mistyped mode must fail closed. */
if (PHP_SAPI !== 'cli') exit(1);
$root=sys_get_temp_dir().'/latra-relay-config-'.bin2hex(random_bytes(6));mkdir($root,0700);
mkdir($root.'/includes',0700);
copy(__DIR__.'/../config.php',$root.'/config.php');
copy(__DIR__.'/../includes/autoload.php',$root.'/includes/autoload.php');
try {
    foreach (['relay' => true, 'legacy' => false, 'unknown' => false] as $mode => $accepted) {
        file_put_contents($root.'/.env',"DELIVERY_ARCHITECTURE=$mode\n");
        $pipes=[];$p=proc_open([PHP_BINARY,'-r','$_SERVER["SCRIPT_NAME"]="config-test.php"; require "config.php"; echo DELIVERY_ARCHITECTURE;'],
            [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,$root);
        if(!is_resource($p))throw new RuntimeException('Cannot execute config fixture');
        $out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
        if($accepted ? ($exit!==0||$out!=='relay') : $exit===0) {
            throw new RuntimeException('Relay-only delivery configuration did not fail closed for ' . $mode);
        }
    }
    echo "PASS relay-only delivery configuration and fail-closed retired modes\n";
} finally {
    foreach(glob($root.'/logs/*')?:[] as $p)unlink($p);
    if(is_dir($root.'/logs'))rmdir($root.'/logs');
    unlink($root.'/includes/autoload.php');
    if(is_dir($root.'/includes'))rmdir($root.'/includes');
    unlink($root.'/.env');unlink($root.'/config.php');rmdir($root);
}
