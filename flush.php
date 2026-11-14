<?php

declare(strict_types=1);

/**
 * Hourly flush: move file-buffered chat events into MySQL, then clean files.
 *
 * Hostinger cron (example):
 *   0 * * * * curl -fsS "https://vibhu.pro/sit/realtime-chat/api/v2/flush.php?key=YOUR_FLUSH_KEY"
 * or:
 *   0 * * * * php /home/.../public_html/sit/realtime-chat/api/v2/flush.php --key=YOUR_FLUSH_KEY
 */

/** @var array $config */
/** @var FlushService $flush */
[, $config, , , $flush] = require __DIR__ . '/bootstrap.php';

$expected = (string) ($config['flush']['key'] ?? '');
$provided = '';

if (PHP_SAPI === 'cli') {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--key=')) {
            $provided = substr($arg, 6);
        }
        if ($arg === '--force') {
            $_GET['force'] = '1';
        }
    }
} else {
    $provided = (string) ($_GET['key'] ?? $_SERVER['HTTP_X_FLUSH_KEY'] ?? '');
}

if ($expected === '' || !hash_equals($expected, $provided)) {
    Response::json([
        'ok' => false,
        'error' => 'unauthorized',
        'message' => 'Invalid flush key.',
    ], 401);
}

$force = isset($_GET['force']) && (string) $_GET['force'] === '1';
$result = $flush->run($force);
$status = ($result['ok'] ?? false) ? 200 : (($result['error'] ?? '') === 'lock_busy' ? 409 : 500);

Response::json($result, $status);
