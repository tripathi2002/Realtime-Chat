<?php

declare(strict_types=1);

/** @var ChatService $chat */
[$chat] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use GET.'], 405);
}

$wait = isset($_GET['wait']) && (string) $_GET['wait'] === '1';
$result = $chat->poll(Request::token(), Request::since(), $wait);

if (!($result['ok'] ?? false)) {
    $status = (($result['message'] ?? '') === 'Invalid or expired chat session. Join again.') ? 401 : 500;
    Response::json($result, $status);
}

Response::json($result, 200);
