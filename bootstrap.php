<?php

declare(strict_types=1);

header_remove('X-Powered-By');

require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/Cors.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Request.php';
require_once __DIR__ . '/src/FileStore.php';
require_once __DIR__ . '/src/FlushService.php';
require_once __DIR__ . '/src/AuthService.php';
require_once __DIR__ . '/src/RoomService.php';
require_once __DIR__ . '/src/ChatService.php';

$configFile = is_file(__DIR__ . '/config.php')
    ? __DIR__ . '/config.php'
    : __DIR__ . '/config.example.php';

/** @var array $config */
$config = require $configFile;

Cors::apply($config['allowed_origins'] ?? []);

$storageRoot = (string) ($config['storage']['path'] ?? (__DIR__ . '/storage'));
$store = new FileStore($storageRoot);
$auth = new AuthService($config);
$rooms = new RoomService($config, $auth);
$chat = new ChatService($config, $auth, $rooms, $store);
$flush = new FlushService($config, $store);

return [$chat, $config, $auth, $rooms, $flush, $store];
