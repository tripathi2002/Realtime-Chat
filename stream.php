<?php

declare(strict_types=1);

/** @var ChatService $chat */
[$chat] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use GET.'], 405);
}

$chat->stream(Request::token(), Request::since());
