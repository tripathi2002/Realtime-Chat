<?php

declare(strict_types=1);

final class Database
{
    private const SCHEMA_VERSION = 2;

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

        if (self::isRemoteHostingerHost($host)) {
            $host = 'localhost';
        }

        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $name, $charset);

        try {
            self::$pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => $persistent,
            ]);
        } catch (PDOException $e) {
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
            'CREATE TABLE IF NOT EXISTS accounts (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                public_id VARCHAR(32) NOT NULL,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                display_name VARCHAR(100) NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_accounts_public_id (public_id),
                UNIQUE KEY uq_accounts_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS auth_sessions (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token CHAR(64) NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_auth_sessions_token (token),
                KEY idx_auth_sessions_account (account_id),
                KEY idx_auth_sessions_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS rooms (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                code VARCHAR(12) NOT NULL,
                name VARCHAR(100) NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_rooms_code (code),
                KEY idx_rooms_created_by (created_by)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_users (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                token CHAR(64) NOT NULL,
                room_id BIGINT UNSIGNED NOT NULL,
                account_id BIGINT UNSIGNED NOT NULL,
                name VARCHAR(100) NOT NULL,
                name_key VARCHAR(100) NOT NULL,
                last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                last_message_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_chat_users_token (token),
                UNIQUE KEY uq_chat_users_room_account (room_id, account_id),
                KEY idx_chat_users_room_seen (room_id, last_seen_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS chat_events (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                room_id BIGINT UNSIGNED NOT NULL,
                type ENUM("join", "message", "leave", "typing") NOT NULL,
                user_name VARCHAR(100) NOT NULL,
                message TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_chat_events_room_id (room_id, id),
                KEY idx_chat_events_type_id (type, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        // Rolling upgrades from v1 anonymous schema.
        self::ensureColumn($pdo, 'chat_users', 'room_id', 'BIGINT UNSIGNED NULL AFTER token');
        self::ensureColumn($pdo, 'chat_users', 'account_id', 'BIGINT UNSIGNED NULL AFTER room_id');
        self::ensureColumn($pdo, 'chat_users', 'name_key', 'VARCHAR(100) NOT NULL DEFAULT \'\' AFTER name');
        self::ensureColumn($pdo, 'chat_users', 'last_message_at', 'TIMESTAMP NULL DEFAULT NULL AFTER last_seen_at');
        self::ensureColumn($pdo, 'chat_events', 'room_id', 'BIGINT UNSIGNED NULL AFTER id');
        self::ensureIndex($pdo, 'chat_users', 'uq_chat_users_room_account', 'UNIQUE KEY uq_chat_users_room_account (room_id, account_id)');
        self::ensureIndex($pdo, 'chat_events', 'idx_chat_events_room_id', 'KEY idx_chat_events_room_id (room_id, id)');
        // v1 used a global unique display name; rooms allow the same name in different rooms.
        self::dropIndexIfExists($pdo, 'chat_users', 'uq_chat_users_name_key');

        @touch($marker);
        self::$schemaReady = true;
    }

    private static function dropIndexIfExists(PDO $pdo, string $table, string $index): void
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND INDEX_NAME = :index'
        );
        $stmt->execute([':table' => $table, ':index' => $index]);
        if ((int) $stmt->fetchColumn() > 0) {
            try {
                $pdo->exec("ALTER TABLE {$table} DROP INDEX {$index}");
            } catch (Throwable $e) {
                error_log('[chat-api] index drop: ' . $e->getMessage());
            }
        }
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
                error_log('[chat-api] index ensure: ' . $e->getMessage());
            }
        }
    }
}
