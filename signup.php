<?php

declare(strict_types=1);

/** @var AuthService $auth */
[,, $auth] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$result = $auth->signup(Request::jsonBody());
if (!($result['ok'] ?? false)) {
    Response::json($result, isset($result['errors']) ? 422 : 500);
}

Response::json($result, 201);
