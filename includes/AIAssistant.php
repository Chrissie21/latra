<?php
require_once __DIR__.'/autoload.php';

/** Retained backend compatibility API; no chat page or endpoint is installed. */
class AIAssistant {
    public const MAX_MESSAGE_LENGTH = \Latra\Domain\AI\AssistantPolicy::MAX_MESSAGE_LENGTH;
    public const MAX_TOOL_ITERATIONS = \Latra\Domain\AI\AssistantPolicy::MAX_TOOL_ITERATIONS;
    public const MAX_HISTORY_MESSAGES = \Latra\Domain\AI\AssistantPolicy::MAX_HISTORY_MESSAGES;
    public const MAX_TOOL_RESULT_CHARS = \Latra\Domain\AI\AssistantPolicy::MAX_TOOL_RESULT_CHARS;
    private static function repository(): \Latra\Infrastructure\Persistence\PdoAssistantRepository {
        return new \Latra\Infrastructure\Persistence\PdoAssistantRepository(Database::getInstance());
    }
    public static function ensureSchema(): void { self::repository()->ensure(); }
    public static function chat(array $user,array $input): array {
        return (new \Latra\Application\AI\ChatWithAssistant(
            self::repository(),new OpenAIClient(),AITools::service(),
            new \Latra\Infrastructure\AI\RuntimeAssistantEffects(!defined('APP_ENV') || APP_ENV==='development',envValue('AI_AUDIT','on')==='on'),
            new \Latra\Domain\AI\AssistantSettings((int)envValue('AI_RATE_LIMIT','20'),(int)envValue('AI_MAX_TOKENS','900'),
                defined('APP_NAME') ? APP_NAME : 'LATRA-Wialon',defined('APP_VERSION') ? APP_VERSION : '1.0.0',
                defined('DISPLAY_TZ') ? DISPLAY_TZ : 'UTC',LatraMapper::ACTIVITIES),
            static fn(): float => microtime(true),
        ))->chat($user,$input);
    }
}
