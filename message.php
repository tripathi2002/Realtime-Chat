<?php

declare(strict_types=1);

/** @var ChatService $chat */
[$chat] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$payload = Request::jsonBody();
$result = $chat->sendMessage(Request::token($payload), $payload);

if (!($result['ok'] ?? false)) {
    $status = isset($result['errors']) ? 422 : 500;
    if (($result['message'] ?? '') === 'Invalid or expired chat session. Join again.') {
        $status = 401;
    } elseif (($result['message'] ?? '') === 'You are sending messages too quickly. Please wait a moment.') {
        $status = 429;
    }
    Response::json($result, $status);
}

Response::json($result, 200);
