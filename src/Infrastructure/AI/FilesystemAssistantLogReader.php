<?php
namespace Latra\Infrastructure\AI;

use Closure;
use Latra\Application\AI\AssistantLogReader;

final class FilesystemAssistantLogReader implements AssistantLogReader
{
    private Closure $now;
    public function __construct(private string $directory,callable $now) { $this->now=Closure::fromCallable($now); }
    public function lines(int $limit, int $hours, ?string $term): array {
        $cutoff = gmdate('Y-m-d H:i:s', ($this->now)() - $hours * 3600);
        $files = [];
        foreach (glob($this->directory . '/error.log') as $f) {
            $files[] = $f;
        }
        foreach (glob($this->directory . '/app-*.log') as $f) {
            $files[] = $f;
        }
        $lines = [];
        foreach ($files as $file) {
            if (filesize($file) > 30 * 1024 * 1024) {
                continue; // never slurp huge logs
            }
            $fh = @fopen($file, 'r');
            if (!$fh) {
                continue;
            }
            while (($line = fgets($fh)) !== false) {
                if ($term !== null && stripos($line, $term) === false) {
                    continue;
                }
                // crude cutoff check: skip lines before the cut-off hour if a timestamp is present
                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}/', $line, $m) && strcmp($m[0], $cutoff) < 0) {
                    continue;
                }
                $lines[] = trim(mb_substr($line, 0, 400));
            }
            fclose($fh);
        }
        $lines = array_reverse($lines);
        return array_slice($lines, 0, $limit);
    }
}
