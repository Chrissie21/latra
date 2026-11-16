<?php
namespace Latra\Application\AI;

use Closure;
use Throwable;
use Latra\Domain\AI\AssistantPolicy;
use Latra\Domain\AI\AssistantSettings;

final class ChatWithAssistant
{
    private Closure $clock;
    public function __construct(private AssistantRepository $repository,private ChatClient $client,
        private AssistantTools $tools,private AssistantEffects $effects,private AssistantSettings $settings,callable $clock)
    { $this->clock=Closure::fromCallable($clock); }
    private function prompt(string $role,array $context): string {
        try { $jobs=$this->repository->jobs(); } catch (Throwable $error) { $jobs=[]; }
        return AssistantPolicy::systemPrompt($role,$context,$this->settings,$jobs);
    }
    public function chat(array $user, array $input): array {
        $this->repository->ensure();
        $userId = (int) ($user['uid'] ?? 0);
        $role = (string) ($user['role'] ?? 'operator');

        // --- validate request -------------------------------------------------
        $text = trim((string) ($input['message'] ?? ''));
        if ($text === '') {
            return AssistantPolicy::fail('VALIDATION_ERROR', 'Please enter a message.');
        }
        if (mb_strlen($text) > AssistantPolicy::MAX_MESSAGE_LENGTH) {
            return AssistantPolicy::fail('VALIDATION_ERROR', 'Message is too long (max ' . AssistantPolicy::MAX_MESSAGE_LENGTH . ' characters).');
        }

        $conversationId = AssistantPolicy::sanitizeConversationId($input['conversation_id'] ?? '');
        $context = AssistantPolicy::sanitizeContext($input['context'] ?? []);

        // --- rate limiting (per user) -----------------------------------------
        $rateMax = max(1,$this->settings->rateMax);
        if (!$this->effects->allowRequest($userId,$rateMax)) {
            return AssistantPolicy::fail('RATE_LIMITED', 'Too many AI requests. Please wait a moment and try again.');
        }

        // --- conversation history (owned by this user) -------------------------
        $existing=$this->repository->conversation($conversationId,$userId);
        if (!$existing) $this->repository->createConversation($conversationId,$userId,mb_substr(mb_strtolower($text),0,60));
        $history=$this->repository->history($conversationId,$userId,AssistantPolicy::MAX_HISTORY_MESSAGES);

        $history = array_reverse($history);

        $userMsg = $text;
        if (!empty($context['page'])) {
            $userMsg = "Current page: {$context['page']}"
                . ($context['vehicle_id'] ? " (vehicle_id={$context['vehicle_id']})" : '')
                . "\n\nUser question: " . $text;
        }

        // --- persist the user message ------------------------------------------
        $this->store($conversationId, $userId, 'user', 'chat', $userMsg);

        // --- build OpenAI messages ---------------------------------------------
        $messages = [['role' => 'system', 'content' => $this->prompt($role,$context)]];
        $messages = array_merge($messages, AssistantPolicy::normalizedHistory($history));
        $messages[] = ['role' => 'user', 'content' => $userMsg];

        $client = $this->client;
        if (!$client->isConfigured()) {
            $this->store($conversationId, $userId, 'assistant', 'chat', 'AI service is not configured on the server.');
            return AssistantPolicy::fail('AI_NOT_CONFIGURED', 'The AI service is not configured. An administrator must set OPENAI_API_KEY.');
        }

        $tools = $this->tools->schemas();
        $maxTokens = max(128,$this->settings->maxTokens);
        $toolsUsed = [];
        $start = ($this->clock)();

        try {
            for ($i = 0; $i < AssistantPolicy::MAX_TOOL_ITERATIONS; $i++) {
                $resp = $client->chat($messages, $tools, $maxTokens);

                if (!$resp['ok']) {
                    $code = $resp['error']['code'] ?? 'AI_SERVICE_UNAVAILABLE';
                    $latency = $resp['latency_ms'] ?? null;
                    $reason = (string) ($resp['error']['message'] ?? '');
                    $public = 'The AI service is temporarily unavailable.';
                    if ($reason !== '') {
                        $public .= ' Reason: ' . AssistantPolicy::truncate($reason, 220);
                    }
                    $this->store($conversationId, $userId, 'assistant', 'chat', 'AI service unavailable. ' . $reason, $toolsUsed, $latency);
                    $this->effects->logAttempt($conversationId, $user, $latency, $toolsUsed, $resp['http'] ?? 0);
                    return AssistantPolicy::fail((string) $code, $public);
                }

                $assistantMsg = $resp['message'];
                $toolCalls = $assistantMsg['tool_calls'] ?? [];

                if (empty($toolCalls)) {
                    $content = (string) ($assistantMsg['content'] ?? '');
                    if (trim($content) === '') {
                        $latency = $resp['latency_ms'] ?? null;
                        $this->store($conversationId, $userId, 'assistant', 'chat', 'The AI service returned an empty response.', $toolsUsed, $latency);
                        $this->effects->logAttempt($conversationId, $user, $latency, $toolsUsed, $resp['http'] ?? 0);
                        return AssistantPolicy::fail('AI_EMPTY_RESPONSE', 'The AI service returned an empty response. Please try again.');
                    }
                    $tokens = isset($resp['usage']['total_tokens']) ? (int) $resp['usage']['total_tokens'] : null;
                    $latency = $resp['latency_ms'] ?? null;
                    $this->store($conversationId, $userId, 'assistant', 'chat', $content, $toolsUsed, $latency, $tokens);
                    $this->effects->logAttempt($conversationId, $user, $latency, $toolsUsed, $resp['http'] ?? 0, $tokens);
                    return AssistantPolicy::ok($conversationId, $content);
                }

                // include the assistant tool-call step in the conversation
                $messages[] = $assistantMsg;

                foreach ($toolCalls as $tc) {
                    $fn = $tc['function'] ?? null;
                    $name = (string) ($fn['name'] ?? '');
                    $rawId = (string) ($tc['id'] ?? 'call_' . $i);
                    $argsRaw = (string) ($fn['arguments'] ?? '{}');
                    $args = json_decode($argsRaw, true);
                    if (!is_array($args)) {
                        $args = [];
                    }

                    $validation = AssistantPolicy::validateToolArgs($name,$args,$tools);
                    if (!$validation['valid']) {
                        $result = ['ok' => false, 'error' => $validation['error']];
                    } else {
                        $result = $this->tools->execute($name, $args);
                        $toolsUsed[] = $name;
                    }

                    $contentJson = AssistantPolicy::truncate(
                        json_encode($result, JSON_UNESCAPED_SLASHES),
                        AssistantPolicy::MAX_TOOL_RESULT_CHARS
                    );
                    $this->store($conversationId, $userId, 'tool', 'tool', $contentJson, [$name]);
                    $messages[] = [
                        'role' => 'tool',
                        'tool_call_id' => $rawId,
                        'content' => $contentJson,
                    ];
                }
            }

            $this->store($conversationId, $userId, 'assistant', 'chat', 'Tool-step limit reached.', $toolsUsed);
            return AssistantPolicy::fail('AI_LOOP_LIMIT', 'The assistant reached its tool-step limit. Please rephrase and try again.');
        } catch (Throwable $e) {
            $this->effects->failure('ai.chat error: ' . $e->getMessage());
            $clientMsg = 'I couldn\'t retrieve the required system information. Please try again.';
            $this->store($conversationId, $userId, 'assistant', 'chat', $clientMsg, null, (int) round((($this->clock)() - $start) * 1000));
            return AssistantPolicy::fail('DATABASE_ERROR', $clientMsg);
        }
    }

    private function store(
        string $conversationId,
        int $userId,
        string $role,
        string $kind,
        ?string $content,
        ?array $toolsUsed = null,
        ?int $latencyMs = null,
        ?int $tokens = null
    ): void {
        try {
            $model = $this->client->model();
            $this->repository->store([
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'role' => $role,
                'kind' => $kind,
                'content' => $content !== null ? mb_substr($content, 0, 20000) : null,
                'tools_used' => $toolsUsed !== null ? implode(',', array_unique($toolsUsed)) : null,
                'model' => $model,
                'latency_ms' => $latencyMs,
                'tokens' => $tokens,
            ]);
        } catch (Throwable $e) {
            $this->effects->failure('ai chat log write skipped: ' . $e->getMessage());
        }
    }
}
