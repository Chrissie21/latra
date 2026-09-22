<?php
/**
 * AI Assistant chat endpoint.
 *
 * POST only, JSON body: { message, conversation_id?, context?: { page?, vehicle_id? } }.
 * Requires an authenticated session + valid CSRF token. Returns a JSON payload
 * per the assistant response contract. Raw exceptions are never exposed.
 */

require_once 'config.php';
require_once 'includes/Database.php';
require_once 'includes/Auth.php';
require_once 'includes/Schema.php';
require_once 'includes/LatraMapper.php';
require_once 'includes/AITools.php';
require_once 'includes/OpenAIClient.php';
require_once 'includes/AIAssistant.php';
require_once 'includes/DeviceMonitor.php';
require_once 'includes/Logger.php';

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => ['code' => 'UNAUTHORIZED', 'message' => 'Sign in required.']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => ['code' => 'METHOD_NOT_ALLOWED', 'message' => 'POST required.']]);
    exit;
}

Auth::requireCsrf();

try {
    Schema::ensure();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => ['code' => 'DATABASE_ERROR', 'message' => 'I couldn\'t retrieve the required system information. Please try again.']]);
    exit;
}

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody !== false ? $rawBody : '', true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => ['code' => 'VALIDATION_ERROR', 'message' => 'Invalid JSON request body.']]);
    exit;
}

try {
    $result = AIAssistant::chat(Auth::user(), $input);
} catch (Throwable $e) {
    error_log('ai_chat endpoint error: ' . $e->getMessage());
    $result = ['success' => false, 'error' => ['code' => 'INTERNAL_ERROR', 'message' => 'The AI service is temporarily unavailable. Please try again.']];
}

if (!empty($result['success'])) {
    http_response_code(200);
} elseif (($result['error']['code'] ?? '') === 'RATE_LIMITED') {
    http_response_code(429);
} else {
    http_response_code(200);
}
echo json_encode($result);