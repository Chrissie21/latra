<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Collection\MapRelayReview;
$calls=[];
$map=new MapRelayReview('wialon:12',
    static function () use (&$calls): array {$calls[]='events';return [8,9];},
    static function () use (&$calls): array {$calls[]='poll';return [1,2];},
    static fn(array $source,array $allowed,bool $events):array=>['source'=>$source,'allowed'=>$allowed,'events'=>$events]);
foreach ([':events'=>[[8,9],true],':poll'=>[[1,2],false],':activity:4'=>[[4],false]] as $suffix=>[$allowed,$events]) {
    $actual=$map->map(['t'=>123],'wialon:12'.$suffix);
    if ($actual!==['source'=>['t'=>123],'allowed'=>$allowed,'events'=>$events]) throw new RuntimeException('Review mapping scope changed');
}
if ($calls!==['events','poll']) throw new RuntimeException('Review mapper loaded irrelevant activities');
foreach (['wialon:123:poll','wialon:99:events','manual:wialon:12','wialon:12:unknown'] as $unit) {
    try {$map->map([],$unit);throw new LogicException('Unexpected recovery stream accepted');}
    catch (RuntimeException $error) {if($error instanceof LogicException)throw $error;}
}
echo "PASS review mapping preserves stream scope and rejects foreign/unsupported units\n";
