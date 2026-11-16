<?php
namespace Latra\Infrastructure\Maintenance;

final class FilesystemLogPruner
{
    public function __construct(private string $directory) {}
    public function before(int $cutoff): void
    {
        foreach (glob($this->directory . '/*.log') ?: [] as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
    }
}
