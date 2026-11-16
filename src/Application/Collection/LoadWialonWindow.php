<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Latra\Domain\Collection\WialonWindowPolicy;

/**
 * Loads one complete, bounded Wialon message window by paging through the
 * source session. A window is only returned when every page is complete, every
 * timestamp falls inside the requested range and the cumulative byte ceiling
 * holds; the session is always unloaded afterwards.
 */
final class LoadWialonWindow
{
    public function __construct(private WialonWindowSession $session)
    {
    }

    public function execute(
        int $unitId,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
    ): array {
        WialonWindowPolicy::assertRequest($unitId, $from, $to, $pageSize, $maxRecords, $maxBytes);

        try {
            $loaded = $this->session->loadWindow($unitId, $from, $to, min($pageSize, $maxRecords), $maxBytes);
            $total = WialonWindowPolicy::assertTotal(
                is_array($loaded) ? ($loaded['count'] ?? null) : null,
                $maxRecords,
            );

            $messages = [];
            $bytes = 0;
            for ($offset = 0; $offset < $total; $offset += $pageSize) {
                $page = $offset === 0
                    ? ($loaded['messages'] ?? null)
                    : $this->session->loadPage($offset, min($total, $offset + $pageSize), $maxBytes);

                $page = WialonWindowPolicy::assertPage($page, min($pageSize, $total - $offset));
                foreach ($page as $message) {
                    $message = WialonWindowPolicy::assertMessage($message, $from, $to);
                    $bytes = WialonWindowPolicy::addBytes($bytes, $message, $maxBytes);
                    $messages[] = $message;
                }
            }

            WialonWindowPolicy::assertEmptyConsistency($total, $loaded['messages'] ?? null);
            return $messages;
        } finally {
            $this->session->unload();
        }
    }
}
