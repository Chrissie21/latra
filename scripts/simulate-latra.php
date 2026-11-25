<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
require __DIR__ . '/../includes/autoload.php';
try {
    \Latra\Infrastructure\Simulation\SimulationCommand::run(getopt('',
        ['retry:','input:','unit:','seconds:','output:','receiver:','profile:','speed-limit:']), dirname(__DIR__));
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage()."\n"); exit(1); }
