<?php

declare(strict_types=1);

/** @var AuthService $auth */
[,, $auth] = require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Response::json(['ok' => false, 'message' => 'Method not allowed. Use POST.'], 405);
}

Response::json($auth->logout(Request::authToken(Request::jsonBody())), 200);
