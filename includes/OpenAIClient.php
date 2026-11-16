<?php
require_once __DIR__.'/autoload.php';

/** Environment configuration boundary; provider protocol lives in Infrastructure. */
class OpenAIClient extends \Latra\Infrastructure\AI\OpenAiChatClient {
    public function __construct() {
        parent::__construct(envValue('OPENAI_API_KEY',''),envValue('OPENAI_MODEL','gpt-4o'),
            (int)envValue('AI_TIMEOUT','45'),envValue('AI_BASE_URL','https://api.openai.com/v1/chat/completions'));
    }
}
