<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(array $config): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        $db = $config['db'] ?? null;
        if (!is_array($db)) {
            throw new RuntimeException('Database config missing. Set db.* in config.php.');
        }

        $host = (string) ($db['host'] ?? 'localhost');
        $port = (string) ($db['port'] ?? '3306');
        $name = (string) ($db['name'] ?? '');
        $user = (string) ($db['user'] ?? '');
        $pass = (string) ($db['pass'] ?? '');
        $charset = (string) ($db['charset'] ?? 'utf8mb4');

        if ($name === '' || $user === '') {
            throw new RuntimeException('Database name and user are required in config.php.');
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        self::$pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$pdo;
    }

    public static function ensureChatTables(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token CHAR(64) NOT NULL,
                name VARCHAR(100) NOT NULL,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_chat_users_token (token),
                KEY idx_chat_users_last_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type ENUM("join", "message", "leave") NOT NULL,
                user_name VARCHAR(100) NOT NULL,
                message TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_chat_events_id (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
