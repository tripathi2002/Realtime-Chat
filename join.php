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

$result = $chat->join(is_array($payload) ? $payload : []);

if (!($result['ok'] ?? false)) {
    $status = isset($result['errors']) ? 422 : 500;
    Response::json($result, $status);
}

Response::json($result, 200);
