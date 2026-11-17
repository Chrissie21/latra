<?php
require_once __DIR__ . '/autoload.php';

/**
 * Compatibility facade. Schema SQL, seeds and config reads live in Infrastructure
 * so entry points and legacy adapters never own persistence statements.
 */
class Schema extends \Latra\Infrastructure\Persistence\DatabaseSchema
{
}
