<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Wialon;

use Latra\Application\Collection\LoadWialonWindow;
use Latra\Application\Collection\WialonTransport;
use Latra\Application\Collection\WialonWindowSession;

/**
 * Wialon API client: session login/logout, fleet unit reads and bounded message
 * windows over an injected transport.
 *
 * Bounded window validation and paging live in the application use case
 * (`LoadWialonWindow`) and the domain policy, so this adapter only encodes the
 * protocol and owns the session id.
 */
class WialonApiClient implements WialonWindowSession
{
    private string $baseUrl;
    private ?string $sid = null;

    public function __construct(
        string $baseUrl,
        private string $token,
        private WialonTransport $transport,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function request(string $svc, array $params = [], ?int $maxResponseBytes = null): ?array
    {
        $url = $this->baseUrl;
        if (!str_ends_with($url, '/wialon/ajax.html')) {
            $url = rtrim($url, '/') . '/wialon/ajax.html';
        }
        $url .= '?svc=' . urlencode($svc);
        if ($this->sid) {
            $url .= '&sid=' . urlencode($this->sid);
        }

        $data = $this->transport->post(
            $url,
            'params=' . urlencode(json_encode($params)),
            $maxResponseBytes,
        );

        if (isset($data['error'])) {
            $errorCode = (int) $data['error'];
            if ($errorCode !== 0) {
                error_log("Wialon API Error {$errorCode}: " . ($data['reason'] ?? 'Unknown'));
                return null;
            }
        }

        return $data;
    }

    public function login(): bool
    {
        if (empty($this->token)) {
            error_log('Wialon token is empty');
            return false;
        }

        $result = $this->request('token/login', ['token' => $this->token]);
        if ($result && isset($result['eid'])) {
            $this->sid = $result['eid'];
            return true;
        }

        return false;
    }

    public function logout(): void
    {
        if ($this->sid) {
            $this->request('core/logout', []);
            $this->sid = null;
        }
    }

    /**
     * All units with every available unit field (flags 0xFFFFFFFF).
     */
    public function getUnits(): array
    {
        $result = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_unit',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name',
            ],
            'force' => 1,
            'flags' => 4294967295,
            'from' => 0,
            'to' => 4294967295,
        ]);

        return $result['items'] ?? [];
    }

    public function getMessages(int $unitId, int $from, int $to, int $limit = 100): array
    {
        $result = $this->request('messages/load_interval', [
            'itemId' => $unitId,
            'timeFrom' => $from,
            'timeTo' => $to,
            'flags' => 0,
            'flagsMask' => 0,
            'loadCount' => $limit,
        ]);

        return $result['messages'] ?? [];
    }

    /** Load one complete, bounded window in this session via the application use case. */
    public function getCompleteWindow(
        int $unitId,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
    ): array {
        return (new LoadWialonWindow($this))->execute($unitId, $from, $to, $pageSize, $maxRecords, $maxBytes);
    }

    public function loadWindow(int $unitId, int $from, int $to, int $count, int $maxBytes): ?array
    {
        return $this->request('messages/load_interval', [
            'itemId' => $unitId,
            'timeFrom' => $from,
            'timeTo' => $to,
            'flags' => 0,
            'flagsMask' => 0xFF00,
            'loadCount' => $count,
        ], $maxBytes);
    }

    public function loadPage(int $from, int $to, int $maxBytes): mixed
    {
        // The loaded-message API uses an exclusive upper boundary (verified on the pilot).
        return $this->request('messages/get_messages', [
            'indexFrom' => $from,
            'indexTo' => $to,
        ], $maxBytes);
    }

    public function unload(): void
    {
        $this->request('messages/unload', []);
    }

    /** Parameter name of the unit's "engine operation" sensor, trimmed; null when absent. */
    public function getEngineSensorParam(int $unitId): ?string
    {
        $unit = $this->getUnitData($unitId);
        foreach (($unit['sens'] ?? []) as $sensor) {
            if (($sensor['t'] ?? null) === 'engine operation') {
                $param = trim((string) ($sensor['p'] ?? ''));
                return $param !== '' ? $param : null;
            }
        }
        return null;
    }

    public function getUnitData(int $unitId): ?array
    {
        $result = $this->request('core/search_item', ['id' => $unitId, 'flags' => 0xFFFFFFFF]);
        return $result['item'] ?? null;
    }

    public function isLoggedIn(): bool
    {
        return $this->sid !== null;
    }

    public function getSid(): ?string
    {
        return $this->sid;
    }
}
