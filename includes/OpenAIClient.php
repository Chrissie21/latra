<?php
/**
 * Server-side OpenAI Chat Completions client.
 *
 * The API key lives only on the server (env / .env) and is never sent to the
 * browser. Uses curl with a bounded timeout and reports structured errors.
 */

class OpenAIClient {

    private string $apiKey;
    private string $model;
    private string $baseUrl = 'https://api.openai.com/v1/chat/completions';
    private int $timeout;

    public function __construct() {
        $this->apiKey = envValue('OPENAI_API_KEY', '');
        $this->model = envValue('OPENAI_MODEL', 'gpt-4o');
        $this->timeout = (int) envValue('AI_TIMEOUT', '45');
        $url = envValue('AI_BASE_URL', 'https://api.openai.com/v1/chat/completions');
        if ($url !== '') {
            $this->baseUrl = $url;
        }
    }

    public function isConfigured(): bool {
        return $this->apiKey !== '';
    }

    public function model(): string {
        return $this->model;
    }

    /**
     * Send a chat completion request.
     *
     * @return array {
     *   ok: bool,
     *   message?: array{role:string, content:?string, tool_calls?:array},
     *   usage?: array, latency_ms?: int, http?: int, error?: array{code:string, message:string}
     * }
     */
    public function chat(array $messages, array $tools = [], int $maxTokens = 800, float $temperature = 0.2): array {
        if (!$this->isConfigured()) {
            return $this->error(
                'AI_NOT_CONFIGURED',
                'The AI service is not configured. An administrator must set OPENAI_API_KEY.'
            );
        }

        $body = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => max(64, $maxTokens),
        ];
        if ($tools) {
            $body['tools'] = $tools;
            $body['tool_choice'] = 'auto';
        }

        $start = microtime(true);
        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => max(5, min(20, (int) ($this->timeout / 3))),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($raw === false) {
            return $this->error('AI_SERVICE_UNAVAILABLE', 'The AI service request failed to send.' . ($curlErr !== '' ? ' (' . $curlErr . ')' : ''), $latency, $http);
        }

        $data = json_decode($raw, true);
        if ($http < 200 || $http >= 300) {
            $code = $data['error']['code'] ?? ('AI_HTTP_' . $http);
            $message = $data['error']['message'] ?? 'The AI service returned HTTP ' . $http . '.';
            return $this->error((string) $code, (string) $message, $latency, $http);
        }

        $choice = $data['choices'][0] ?? null;
        if (!$choice) {
            return $this->error('AI_EMPTY_RESPONSE', 'The AI service returned an empty response.', $latency, $http);
        }

        return [
            'ok' => true,
            'message' => $choice['message'] ?? ['role' => 'assistant', 'content' => null],
            'usage' => $data['usage'] ?? null,
            'latency_ms' => $latency,
            'http' => $http,
        ];
    }

    private function error(string $code, string $message, ?int $latencyMs = null, int $http = 0): array {
        return [
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
            'latency_ms' => $latencyMs,
            'http' => $http,
        ];
    }
}