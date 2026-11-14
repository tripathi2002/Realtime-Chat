<?php

declare(strict_types=1);

/** @var AuthService $auth */
[,, $auth] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

$result = $auth->login(Request::jsonBody());
if (!($result['ok'] ?? false)) {
    $status = isset($result['errors']) ? 422 : 401;
    Response::json($result, $status);
}

Response::json($result, 200);
