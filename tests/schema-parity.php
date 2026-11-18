<?php
/**
 * Schema parity: the runtime bootstrap, the base dump and the SQL migrations must
 * describe one database.
 *
 * A table added only to migrations/*.sql works for an existing deployment that ran
 * the SQL by hand, but a fresh install composes Schema::ensure() alone. That drift
 * shipped once already (the durable relay tables), so it is checked statically here
 * instead of relying on a native database run.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);

$root = dirname(__DIR__);

/** @return array<string,bool> */
function schemaTables(string $source): array
{
    preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?([a-z_][a-z0-9_]*)`?/i', $source, $matches);
    return array_fill_keys(array_map('strtolower', $matches[1]), true);
}

function schemaFail(string $message): void
{
    throw new RuntimeException($message);
}

$baseDump = schemaTables((string) file_get_contents($root . '/database.sql'));

$runtimeSources = [
    'DatabaseSchema.php' => $root . '/src/Infrastructure/Persistence/DatabaseSchema.php',
    'PdoAuthRepository.php' => $root . '/src/Infrastructure/Persistence/PdoAuthRepository.php',
    'PdoSecretRepository.php' => $root . '/src/Infrastructure/Persistence/PdoSecretRepository.php',
];
$runtime = [];
foreach ($runtimeSources as $label => $path) {
    $runtime += schemaTables((string) file_get_contents($path));
}

$migrations = [];
$migrationFiles = glob($root . '/migrations/*.sql') ?: [];
sort($migrationFiles);
if (count($migrationFiles) < 10) {
    schemaFail('Expected the numbered migration set, found ' . count($migrationFiles));
}
$highestMigration = 0;
foreach ($migrationFiles as $file) {
    $name = basename($file);
    $highestMigration = max($highestMigration, (int) substr($name, 0, 3));
    $migrations += schemaTables((string) file_get_contents($file));
}

// 1. Anything a migration creates must also exist on the fresh-install path,
//    unless it is part of the base dump every deployment starts from.
foreach (array_keys($migrations) as $table) {
    if (!isset($runtime[$table]) && !isset($baseDump[$table])) {
        schemaFail("migrations create `$table` but the runtime bootstrap never does");
    }
}

// 2. The repository layer must only read tables the runtime can create.
//    Base tables come from the dump; everything else is bootstrap responsibility.
$referenced = [];
foreach (glob($root . '/src/Infrastructure/Persistence/Pdo*Repository.php') ?: [] as $file) {
    $sql = (string) file_get_contents($file);
    preg_match_all('/(?:FROM|JOIN|INTO|UPDATE)\s+`?([a-z_][a-z0-9_]*)`?/i', $sql, $matches);
    foreach ($matches[1] as $table) {
        $referenced[strtolower($table)] = true;
    }
}
// Column names that a SET/subquery construct makes look like a table.
$notTables = ['ciphertext', 'cnt', 'current_timestamp', 'profile', 'value', 'dual'];
foreach (array_keys($referenced) as $table) {
    if (in_array($table, $notTables, true)) continue;
    if (!isset($runtime[$table]) && !isset($baseDump[$table])) {
        schemaFail("repositories read `$table` but no schema source creates it");
    }
}

// 3. The advertised version must track the migration set, so an upgrade is visible.
$schemaSource = (string) file_get_contents($root . '/src/Infrastructure/Persistence/DatabaseSchema.php');
if (!preg_match('/const\s+VERSION\s*=\s*(\d+)/', $schemaSource, $version)) {
    schemaFail('DatabaseSchema::VERSION is missing');
}
if ((int) $version[1] !== $highestMigration) {
    schemaFail("DatabaseSchema::VERSION is {$version[1]} but the newest migration is {$highestMigration}");
}

printf(
    "PASS schema parity: %d runtime, %d base-dump and %d migration tables agree; version %d\n",
    count($runtime),
    count($baseDump),
    count($migrations),
    $highestMigration,
);
