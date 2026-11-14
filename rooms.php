<?php

declare(strict_types=1);

/** @var RoomService $rooms */
[,,, $rooms] = require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$code = isset($_GET['code']) ? strtoupper(trim((string) $_GET['code'])) : '';

if ($method === 'POST') {
    $result = $rooms->create(Request::authToken(Request::jsonBody()), Request::jsonBody());
    if (!($result['ok'] ?? false)) {
        $status = isset($result['errors']) ? 422 : ((($result['message'] ?? '') === 'Please sign in to continue.') ? 401 : 500);
        Response::json($result, $status);
    }
    Response::json($result, 201);
}

if ($method === 'GET' && $code !== '') {
    $result = $rooms->getByCode($code);
    if (!($result['ok'] ?? false)) {
        Response::json($result, 404);
    }
    Response::json($result, 200);
}

if ($method === 'GET') {
    $result = $rooms->listMine(Request::authToken());
    if (!($result['ok'] ?? false)) {
        Response::json($result, 401);
    }
    Response::json($result, 200);
}

Response::json(['ok' => false, 'message' => 'Method not allowed.'], 405);
