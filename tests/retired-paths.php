<?php
/** Retired writers must not return through wrappers or autoloadable src classes. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
$retired = [
    'Application/Ingestion/IngestVehicleMessages', 'Application/Ingestion/IngestionRepository',
    'Application/Ingestion/IngestionEffects', 'Application/Ingestion/QueueOutboxMessage',
    'Application/Export/ExportPushLog', 'Application/Export/PushLogExportRepository',
    'Infrastructure/Http/CsvDownload', 'Infrastructure/Persistence/PdoPushLogExportRepository',
    'Application/Ingestion/OutboxWriter', 'Domain/Ingestion/OutboxIntentPolicy',
    'Domain/Ingestion/NewestSourceMessagePolicy', 'Infrastructure/Ingestion/ServiceIngestionEffects',
    'Infrastructure/Persistence/DatabaseIngestionRepository', 'Infrastructure/Persistence/PdoOutboxWriter',
    'Infrastructure/Persistence/PdoIngestionMessageRepository', 'Infrastructure/Persistence/PdoVehicleStateRepository',
];
foreach ($retired as $name) {
    if (is_file(__DIR__ . '/../src/' . $name . '.php')) throw new RuntimeException('Retired implementation remains: ' . $name);
    $class = 'Latra\\' . str_replace('/', '\\', $name);
    if (class_exists($class) || interface_exists($class)) throw new RuntimeException('Retired symbol still autoloads: ' . $class);
}
foreach (['Export','RelayMockTransport','Normaliser','Automation','IngestPipeline','Outbox','EventEngine','EventDetector','DeviceDetect','RelayMapper',
    'AIAssistant','AITools','OpenAIClient'] as $name) {
    if (is_file(__DIR__ . '/../includes/' . $name . '.php')) throw new RuntimeException('Retired adapter remains: ' . $name);
}
echo "PASS retired ingestion/outbox and AI adapters cannot be loaded\n";
