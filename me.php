<?php

declare(strict_types=1);

/** @var AuthService $auth */
[,, $auth] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use GET.'], 405);
}

$result = $auth->me(Request::authToken());
if (!($result['ok'] ?? false)) {
    Response::json($result, 401);
}

Response::json($result, 200);
