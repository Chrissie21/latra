<?php
/**
 * Wialon API client.
 */
class WialonAPI {
    private string $baseUrl;
    private string $token;
    private ?string $sid = null;
    private int $lastError = 0;

    public function __construct(string $baseUrl = WIALON_BASE_URL, string $token = WIALON_TOKEN) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->token = $token;
    }

    /**
     * Execute Wialon API request
     */
    public function request(string $svc, array $params = []): ?array {
        $url = $this->baseUrl;
        if (!str_ends_with($url, '/wialon/ajax.html')) {
            $url = rtrim($url, '/') . '/wialon/ajax.html';
        }
        $url .= '?svc=' . urlencode($svc);

        if ($this->sid) {
            $url .= '&sid=' . urlencode($this->sid);
        }

        $postData = 'params=' . urlencode(json_encode($params));

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/x-www-form-urlencoded'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200 || $response === false) {
            error_log("Wialon API HTTP Error: $httpCode");
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data['error'])) {
            $this->lastError = (int) $data['error'];
            if ($this->lastError !== 0) {
                error_log("Wialon API Error {$this->lastError}: " . ($data['reason'] ?? 'Unknown'));
                return null;
            }
        }

        return $data;
    }

    /**
     * Login with token
     */
    public function login(): bool {
        if (empty($this->token)) {
            error_log("Wialon token is empty");
            return false;
        }

        $result = $this->request('token/login', [
            'token' => $this->token
        ]);

        if ($result && isset($result['eid'])) {
            $this->sid = $result['eid'];
            return true;
        }

        return false;
    }

    /**
     * Logout
     */
    public function logout(): void {
        if ($this->sid) {
            $this->request('core/logout', []);
            $this->sid = null;
        }
    }

    /**
     * Get all units (vehicles)
     * flags: 13644935 = base + position + last message + custom fields
     */
    public function getUnits(): array {
        $result = $this->request('core/search_items', [
            'spec' => [
                'itemsType' => 'avl_unit',
                'propName' => 'sys_name',
                'propValueMask' => '*',
                'sortType' => 'sys_name'
            ],
            'force' => 1,
            'flags' => 4294967295, // Get all available unit information including uid, ph, hw
            'from' => 0,
            'to' => 4294967295
        ]);

        return $result['items'] ?? [];
    }

    /**
     * Get single unit by ID with position data
     */
    public function getUnit(int $unitId): ?array {
        $result = $this->request('core/search_item', [
            'id' => $unitId,
            'flags' => 1025 // name + position
        ]);

        return $result['item'] ?? null;
    }

    /**
     * Get messages for a unit in time range
     */
    public function getMessages(int $unitId, int $from, int $to, int $limit = 100): array {
        $result = $this->request('messages/load_interval', [
            'itemId' => $unitId,
            'timeFrom' => $from,
            'timeTo' => $to,
            'flags' => 0,
            'flagsMask' => 0,
            'loadCount' => $limit
        ]);

        return $result['messages'] ?? [];
    }

    /**
     * Get last messages for unit
     */
    public function getLastMessages(int $unitId, int $limit = 10): array {
        $to = time();
        $from = $to - 86400; // Last 24 hours
        return $this->getMessages($unitId, $from, $to, $limit);
    }

    /**
     * Get unit sensors
     */
    public function getSensors(int $unitId): array {
        $result = $this->request('unit/get_sensors', [
            'itemId' => $unitId
        ]);

        return $result ?? [];
    }

    /**
     * Get the parameter name of the unit's Engine ignition sensor
     * (type 'engine operation') if configured. Returns null when absent.
     */
    public function getEngineSensorParam(int $unitId): ?string {
        $unit = $this->getUnitData($unitId);
        foreach (($unit['sens'] ?? []) as $sensor) {
            if (($sensor['t'] ?? null) === 'engine operation') {
                $param = trim((string) ($sensor['p'] ?? ''));
                return $param !== '' ? $param : null;
            }
        }
        return null;
    }

    /**
     * Get unit data (extended info)
     */
    public function getUnitData(int $unitId): ?array {
        $result = $this->request('core/search_item', [
            'id' => $unitId,
            'flags' => 0xFFFFFFFF
        ]);

        return $result['item'] ?? null;
    }

    /**
     * Execute raw request
     */
    public function rawRequest(string $svc, array $params = []): ?array {
        return $this->request($svc, $params);
    }

    /**
     * Check if logged in
     */
    public function isLoggedIn(): bool {
        return $this->sid !== null;
    }

    /**
     * Get last error code
     */
    public function getLastError(): int {
        return $this->lastError;
    }

    /**
     * Get session ID
     */
    public function getSid(): ?string {
        return $this->sid;
    }
}
