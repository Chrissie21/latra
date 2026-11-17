#!/usr/bin/env php
<?php
/** Apply delivery indexes explicitly; request startup must not build large indexes. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../config.php';
$added = (new \Latra\Infrastructure\Persistence\DeliveryIndexMigration(Database::getInstance()))
    ->migrate(__DIR__ . '/../migrations/008_delivery_retention.sql', 'latra-delivery-migration');
foreach ($added as $index) {
    echo "Added {$index}\n";
}
