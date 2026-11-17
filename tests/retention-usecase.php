<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__ . '/../includes/autoload.php';
use Latra\Application\Maintenance\RetentionRepository;
use Latra\Application\Maintenance\RetainOperationalHistory;
final class MemoryRetention implements RetentionRepository {
    public bool $busy = false;
    public bool $fail = false;
    public int $releases = 0;
    public array $calls = [];
    public function acquire(): bool { return !$this->busy; }
    public function release(): void { $this->releases++; }
    public function deleteEligible(string $category, string $cutoff, ?int $maxBatches = null): int {
        $this->calls[] = [$category,$cutoff,$maxBatches];
        if ($this->fail) throw new RuntimeException('storage failed');
        return 1;
    }
    public function deleteExpiredRateLimits(int $cutoff): int { $this->calls[] = ['rate_limits',$cutoff]; return 1; }
}
function retentionAssert(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$now = 2000000000; $repo = new MemoryRetention();
$work = new RetainOperationalHistory($repo, static fn(): int => $now);
retentionAssert($work->purgeDelivered(48) === 3, 'Purge accounting changed');
retentionAssert($repo->calls === [
    ['delivered_receipts',gmdate('Y-m-d H:i:s',$now-48*3600),50],
    ['delivered_sources',gmdate('Y-m-d H:i:s',$now-48*3600),50],
    ['delivered_queue',gmdate('Y-m-d H:i:s',$now-48*3600),50],
] && $repo->releases === 1, 'Purge window/bounds/order changed');
$repo->calls = []; $work->purgeDelivered(0);
retentionAssert($repo->calls[0][1] === gmdate('Y-m-d H:i:s',$now-3600), 'Minimum one-hour window changed');
$repo->calls = [];
retentionAssert($work->archiveMessages(90) === 1 && $repo->calls === [['archive_sources',gmdate('Y-m-d H:i:s',$now-90*86400),null]], 'Daily archive plan changed');
$repo->calls = [];
retentionAssert($work->maintain(['stream'=>20,'runs'=>70,'audit'=>400]) === 10, 'Maintenance accounting changed');
retentionAssert(array_column($repo->calls,0) === ['delivered_sources','old_receipts','wialon_events','old_queue','webhook_events','automation_run_items','automation_runs','job_runs','audit_log','rate_limits'], 'Maintenance dependency order changed');
retentionAssert($repo->calls[0][1] === gmdate('Y-m-d H:i:s',$now-20*86400)
    && $repo->calls[5][1] === gmdate('Y-m-d H:i:s',$now-70*86400)
    && $repo->calls[8][1] === gmdate('Y-m-d H:i:s',$now-400*86400)
    && $repo->calls[9][1] === $now-20*86400, 'Independent maintenance ages changed');
$repo->calls = []; $repo->busy = true;
retentionAssert($work->maintain([]) === 0 && $repo->calls === [], 'Busy retention owner ignored');
$repo->busy = false; $repo->fail = true; $releases = $repo->releases;
try { $work->archiveMessages(90); throw new LogicException('Missing failure'); }
catch (RuntimeException $error) { retentionAssert($error->getMessage() === 'storage failed', 'Wrong failure'); }
retentionAssert($repo->releases === $releases + 1, 'Failed storage leaked cleanup ownership');
echo "PASS retention plans, independent age windows, dependency order, bounds and lock cleanup\n";
