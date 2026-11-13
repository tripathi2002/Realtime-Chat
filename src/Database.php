<?php

declare(strict_types=1);

final class Database
{
    private const SCHEMA_VERSION = 1;

    private static ?PDO $pdo = null;

    private static bool $schemaReady = false;

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
        $persistent = (bool) ($db['persistent'] ?? true);

        if ($name === '' || $user === '') {
            throw new RuntimeException('Database name and user are required in config.php.');
        }

        // On Hostinger, PHP and MySQL share a machine — prefer localhost/socket over the
        // remote hostname so we do not burn max_connections_per_hour on every request.
        if (self::isRemoteHostingerHost($host)) {
            $host = 'localhost';
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                // Reuse connections across PHP-FPM workers to stay under Hostinger hourly caps.
                PDO::ATTR_PERSISTENT => $persistent,
            ]);
        } catch (PDOException $e) {
            // Persistent handles can go stale after MySQL restarts; retry once cleanly.
            if ($persistent) {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_PERSISTENT => false,
                ]);
            } else {
                throw $e;
            }
        }

        return self::$pdo;
    }

    public static function ensureChatTables(PDO $pdo): void
    {
        if (self::$schemaReady) {
            return;
        }

        $marker = sys_get_temp_dir() . '/realtime_chat_schema_v' . self::SCHEMA_VERSION . '.ready';
        if (is_file($marker) && (time() - (int) filemtime($marker)) < 86400) {
            self::$schemaReady = true;
            return;
        }

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token CHAR(64) NOT NULL,
                name VARCHAR(100) NOT NULL,
                name_key VARCHAR(100) NOT NULL,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_message_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_chat_users_token (token),
                UNIQUE KEY uq_chat_users_name_key (name_key),
                KEY idx_chat_users_last_seen (last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                type ENUM("join", "message", "leave", "typing") NOT NULL,
                user_name VARCHAR(100) NOT NULL,
                message TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_chat_events_id (id),
                KEY idx_chat_events_type_id (type, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        self::ensureColumn($pdo, 'chat_users', 'name_key', 'VARCHAR(100) NOT NULL DEFAULT \'\' AFTER name');
        self::ensureColumn($pdo, 'chat_users', 'last_message_at', 'TIMESTAMP NULL DEFAULT NULL AFTER last_seen_at');
        $pdo->exec("UPDATE chat_users SET name_key = LOWER(name) WHERE name_key = '' OR name_key IS NULL");
        self::ensureIndex($pdo, 'chat_users', 'uq_chat_users_name_key', 'UNIQUE KEY uq_chat_users_name_key (name_key)');

        @touch($marker);
        self::$schemaReady = true;
    }

    private static function isRemoteHostingerHost(string $host): bool
    {
        return (bool) preg_match('/\.hstgr\.io$/i', $host)
            || (bool) preg_match('/\.hostinger\./i', $host);
    }

    private static function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column'
        );
        $stmt->execute([':table' => $table, ':column' => $column]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function ensureIndex(PDO $pdo, string $table, string $index, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
        );
        $stmt->execute([':table' => $table, ':index' => $index]);
        if ((int) $stmt->fetchColumn() === 0) {
            try {
                $pdo->exec("ALTER TABLE {$table} ADD {$definition}");
            } catch (Throwable $e) {
                // Ignore duplicate-name collisions during rolling deploys.
                error_log('[chat-api] index ensure: ' . $e->getMessage());
            }
        }
    }
}
