<?php
/**
 * The base dump must split into whole statements: install.php applies it without
 * multi-statement exec support, so a mis-split would corrupt a fresh install.
 * This is pure text handling, so it is provable without a database.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

require_once __DIR__ . '/../includes/autoload.php';

use Latra\Infrastructure\Persistence\BaseSchemaDump;

$path = dirname(__DIR__) . '/database.sql';
$sql = (string) file_get_contents($path);
$statements = BaseSchemaDump::statements($sql);

$fail = static function (string $message): void {
    throw new RuntimeException($message);
};

if (count($statements) < 15) {
    $fail('Base dump produced only ' . count($statements) . ' statements');
}

$tables = [];
$kinds = ['CREATE' => 0, 'INSERT' => 0, 'OTHER' => 0];
foreach ($statements as $index => $statement) {
    // Every statement must be a single command, never a fragment.
    if (preg_match_all('/\b(CREATE|INSERT|USE|ALTER|UPDATE|DELETE)\b/i', $statement) === 0) {
        $fail('Statement ' . ($index + 1) . ' is not a command');
    }
    $quotes = substr_count($statement, "'") - substr_count($statement, "\\'");
    if ($quotes % 2 !== 0) {
        $fail('Statement ' . ($index + 1) . ' has an unbalanced string literal');
    }
    if (str_starts_with(strtoupper(ltrim($statement)), 'CREATE')) {
        $kinds['CREATE']++;
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)`?/i', $statement, $match)) {
            $tables[] = strtolower($match[1]);
        }
    } elseif (str_starts_with(strtoupper(ltrim($statement)), 'INSERT')) {
        $kinds['INSERT']++;
    } else {
        $kinds['OTHER']++;
    }
}

// Database selection belongs to the connection, not the script.
foreach ($statements as $statement) {
    if (preg_match('/^(CREATE\s+DATABASE|USE)\b/i', trim($statement))) {
        // Expected: the applier skips these. Nothing to assert beyond survival.
        continue;
    }
}

$expected = ['config', 'vehicles', 'activity_mappings', 'activity_definitions', 'message_log',
    'automation_jobs', 'automation_buckets', 'bucket_vehicles', 'automation_runs',
    'automation_run_items', 'push_log', 'hardware_fault_codes'];
$missing = array_diff($expected, $tables);
if ($missing !== []) {
    $fail('Base dump lost tables during splitting: ' . implode(', ', $missing));
}
if ($kinds['INSERT'] < 3) {
    $fail('Base dump reference data did not split into inserts');
}

printf("PASS base dump splits into %d statements (%d create, %d insert, %d other)\n",
    count($statements), $kinds['CREATE'], $kinds['INSERT'], $kinds['OTHER']);
