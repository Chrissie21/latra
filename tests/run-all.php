<?php
/**
 * Offline regression runner.
 *
 *   php tests/run-all.php           # offline suites; native fixtures reported as SKIP
 *   LATRA_TEST_DSN=... php tests/run-all.php   # includes the native database suites
 *
 * Every suite is a standalone script that exits 0 on success, so this only adds
 * discovery, a readable summary and a single exit code for local use and CI.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }

$root = dirname(__DIR__);
// Helpers that are required by other suites, not suites themselves.
$helpers = ['bootstrap.php', 'relay-mock.php'];

$suites = [];
foreach (glob($root . '/tests/*.php') ?: [] as $file) {
    if (in_array(basename($file), $helpers, true) || $file === __FILE__) continue;
    $suites[] = $file;
}
sort($suites);

// The native relay suites share one disposable database, and some of them prepare
// the fixture the later ones assert on. Keep the CI order instead of the alphabetical one.
$nativeOrder = [
    'durable-relay.php',
    'relay-integration.php',
    'relay-runtime.php',
    'relay-cli.php',
    'ingestion-native-repositories.php',
];
$ordered = [];
foreach ($nativeOrder as $name) {
    foreach ($suites as $file) {
        if (basename($file) === $name) $ordered[] = $file;
    }
}
foreach ($suites as $file) {
    if (!in_array(basename($file), $nativeOrder, true)) $ordered[] = $file;
}
$suites = $ordered;

// These suites refuse to touch anything but a disposable MySQL/MariaDB fixture.
$fixtureRequired = '/(database required|DATABASE required|fixture required|Require explicit reset|LATRA_TEST_DSN|LATRA_RELAY_TEST_DSN)/';
// When either fixture DSN is present, a fixture complaint is a real failure.
$fixtureConfigured = getenv('LATRA_TEST_DSN') !== false || getenv('LATRA_RELAY_TEST_DSN') !== false;

$passed = 0;
$failed = [];
$skipped = [];

foreach ($suites as $file) {
    $name = basename($file);
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $status);
    $text = implode("\n", $output);

    if ($status === 0) {
        $passed++;
        echo 'PASS ' . $name . "\n";
        continue;
    }
    if (!$fixtureConfigured && preg_match($fixtureRequired, $text)) {
        $skipped[] = $name;
        echo 'SKIP ' . $name . " (needs a disposable database)\n";
        continue;
    }
    $failed[$name] = trim($text);
    echo 'FAIL ' . $name . "\n";
}

foreach ($failed as $name => $text) {
    echo "\n--- $name ---\n" . $text . "\n";
}

printf(
    "\n%d passed, %d skipped, %d failed (of %d suites)\n",
    $passed,
    count($skipped),
    count($failed),
    count($suites),
);
if ($skipped !== []) {
    echo 'Skipped: ' . implode(', ', $skipped) . "\n";
}
exit($failed === [] ? 0 : 1);
