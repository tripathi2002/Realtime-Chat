<?php

declare(strict_types=1);

/** @var ChatService $chat */
[$chat] = require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method !== 'GET') {
    Response::json([
        'ok' => false,
        'message' => 'Method not allowed. Use GET.',
    ], 405);
}

$token = '';
if (isset($_GET['token']) && is_string($_GET['token'])) {
    $token = $_GET['token'];
} elseif (isset($_SERVER['HTTP_X_CHAT_TOKEN']) && is_string($_SERVER['HTTP_X_CHAT_TOKEN'])) {
    $token = $_SERVER['HTTP_X_CHAT_TOKEN'];
}

$since = isset($_GET['since']) ? (int) $_GET['since'] : 0;
$result = $chat->poll($token, $since);

if (!($result['ok'] ?? false)) {
    $status = (($result['message'] ?? '') === 'Invalid or expired chat session. Join again.') ? 401 : 500;
    Response::json($result, $status);
}

Response::json($result, 200);
