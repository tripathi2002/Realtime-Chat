<?php

declare(strict_types=1);

header_remove('X-Powered-By');

require_once __DIR__ . '/src/Response.php';
require_once __DIR__ . '/src/Cors.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Request.php';
require_once __DIR__ . '/src/ChatService.php';

$configFile = is_file(__DIR__ . '/config.php')
    ? __DIR__ . '/config.php'
    : __DIR__ . '/config.example.php';

/** @var array $config */
$config = require $configFile;

Cors::apply($config['allowed_origins'] ?? []);

return [new ChatService($config), $config];
