<?php

declare(strict_types=1);

/** @var ChatService $chat */
[$chat] = require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'POST') {
    Response::json([
        'ok' => false,
        'message' => 'Method not allowed. Use POST.',
    ], 405);
}

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$token = '';
if (is_array($payload) && isset($payload['token']) && is_string($payload['token'])) {
    $token = $payload['token'];
} elseif (isset($_SERVER['HTTP_X_CHAT_TOKEN']) && is_string($_SERVER['HTTP_X_CHAT_TOKEN'])) {
    $token = $_SERVER['HTTP_X_CHAT_TOKEN'];
}

$result = $chat->sendMessage($token, is_array($payload) ? $payload : []);

if (!($result['ok'] ?? false)) {
    $status = isset($result['errors']) ? 422 : (($result['message'] ?? '') === 'Invalid or expired chat session. Join again.' ? 401 : 500);
    Response::json($result, $status);
}

Response::json($result, 200);
