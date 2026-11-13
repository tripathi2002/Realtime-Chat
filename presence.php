<?php

declare(strict_types=1);

/** @var ChatService $chat */
[$chat] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use GET.'], 405);
}

$result = $chat->presence(Request::token());

if (!($result['ok'] ?? false)) {
    Response::json($result, 401);
}

Response::json($result, 200);
