<?php

declare(strict_types=1);

final class ChatService
{
    private const USER_TTL_SECONDS = 120;
    private const HISTORY_LIMIT = 80;
    private const RATE_LIMIT_MS = 500;
    private const RATE_LIMIT_PER_MINUTE = 30;
    private const STREAM_SECONDS = 25;
    private const STREAM_SLEEP_US = 400000;

    public function __construct(private readonly array $config)
    {
    }

    public function health(): array
    {
        return [
            'ok' => true,
            'service' => 'realtime-chat-api',
            'storage' => 'mysql',
            'transport' => 'sse+long-poll',
            'message' => 'Chat API is online.',
        ];
    }

    /**
     * @return array{ok: bool, token?: string, name?: string, since?: int, history?: list<array<string, mixed>>, online?: list<string>, errors?: array<string, string>, message?: string}
     */
    public function join(array $input): array
    {
        $name = $this->cleanName($input['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'errors' => ['name' => 'Name is required (max 100 chars).']];
        }

        $nameKey = mb_strtolower($name);

        try {
            $pdo = $this->pdo();
            $this->expireStaleUsers($pdo);

            $exists = $pdo->prepare('SELECT id FROM chat_users WHERE name_key = :name_key LIMIT 1');
            $exists->execute([':name_key' => $nameKey]);
            if ($exists->fetch()) {
                return ['ok' => false, 'errors' => ['name' => 'That name is already in use. Pick another.']];
            }

            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare(
                'INSERT INTO chat_users (token, name, name_key, last_seen_at)
                 VALUES (:token, :name, :name_key, NOW())'
            );
            $stmt->execute([
                ':token' => $token,
                ':name' => $name,
                ':name_key' => $nameKey,
            ]);

            $this->insertEvent($pdo, 'join', $name, null);
            $history = $this->history($pdo);
            $since = $this->latestEventId($pdo);

            return [
                'ok' => true,
                'token' => $token,
                'name' => $name,
                'since' => $since,
                'history' => $history,
                'online' => $this->onlineNames($pdo),
            ];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'errors' => ['name' => 'That name is already in use. Pick another.']];
            }
            error_log('[chat-api] join: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to join right now. Please try again later.'];
        } catch (Throwable $e) {
            error_log('[chat-api] join: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to join right now. Please try again later.'];
        }
    }

    /**
     * @return array{ok: bool, name?: string, since?: int, history?: list<array<string, mixed>>, online?: list<string>, message?: string}
     */
    public function resume(string $token): array
    {
        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $this->touchUser($pdo, (int) $user['id']);
            $this->expireStaleUsers($pdo);

            return [
                'ok' => true,
                'name' => (string) $user['name'],
                'since' => $this->latestEventId($pdo),
                'history' => $this->history($pdo),
                'online' => $this->onlineNames($pdo),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] resume: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to resume session.'];
        }
    }

    /**
     * @return array{ok: bool, id?: int, errors?: array<string, string>, message?: string}
     */
    public function sendMessage(string $token, array $input): array
    {
        $message = $this->cleanMessage($input['message'] ?? '');
        if ($message === '' || mb_strlen($message) > 2000) {
            return ['ok' => false, 'errors' => ['message' => 'Message is required (max 2000 chars).']];
        }

        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $userId = (int) $user['id'];

            if (!$this->allowMessage($pdo, $userId)) {
                return ['ok' => false, 'message' => 'You are sending messages too quickly. Please wait a moment.'];
            }

            $this->touchUser($pdo, $userId);
            $id = $this->insertEvent($pdo, 'message', (string) $user['name'], $message);
            $this->markMessageSent($pdo, $userId);

            return ['ok' => true, 'id' => $id];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] message: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to send message right now.'];
        }
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    public function leave(string $token): array
    {
        try {
            $pdo = $this->pdo();
            $user = $this->findUser($pdo, $token);
            if ($user === null) {
                return ['ok' => true];
            }

            $this->removeUser($pdo, (int) $user['id'], (string) $user['name']);

            return ['ok' => true];
        } catch (Throwable $e) {
            error_log('[chat-api] leave: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to leave right now.'];
        }
    }

    /**
     * @return array{ok: bool, events?: list<array<string, mixed>>, since?: int, online?: list<string>, message?: string}
     */
    public function poll(string $token, int $since, bool $wait = false): array
    {
        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $this->touchUser($pdo, (int) $user['id']);
            $this->expireStaleUsers($pdo);

            $deadline = microtime(true) + ($wait ? self::STREAM_SECONDS : 0);
            do {
                $result = $this->eventsSince($pdo, $since);
                if ($result['events'] !== [] || !$wait) {
                    $result['online'] = $this->onlineNames($pdo);

                    return $result;
                }
                usleep(self::STREAM_SLEEP_US);
                $this->touchUser($pdo, (int) $user['id']);
            } while (microtime(true) < $deadline);

            return [
                'ok' => true,
                'events' => [],
                'since' => $since > 0 ? $since : $this->latestEventId($pdo),
                'online' => $this->onlineNames($pdo),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] poll: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to poll events right now.'];
        }
    }

    public function stream(string $token, int $since): void
    {
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', 'off');
        @ini_set('implicit_flush', '1');
        while (ob_get_level() > 0) {
            ob_end_flush();
        }

        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        header_remove('X-Powered-By');

        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $this->touchUser($pdo, (int) $user['id']);
        } catch (InvalidArgumentException $e) {
            $this->sse('error', ['ok' => false, 'message' => $e->getMessage()]);
            return;
        } catch (Throwable $e) {
            error_log('[chat-api] stream auth: ' . $e->getMessage());
            $this->sse('error', ['ok' => false, 'message' => 'Unable to open stream.']);
            return;
        }

        $deadline = time() + self::STREAM_SECONDS;
        $cursor = $since;

        $this->sse('ready', [
            'ok' => true,
            'since' => $cursor,
            'online' => $this->onlineNames($pdo),
        ]);

        while (time() < $deadline) {
            if (connection_aborted()) {
                return;
            }

            try {
                $this->expireStaleUsers($pdo);
                $this->touchUser($pdo, (int) $user['id']);
                $batch = $this->eventsSince($pdo, $cursor);
                if ($batch['events'] !== []) {
                    $this->sse('events', [
                        'ok' => true,
                        'events' => $batch['events'],
                        'since' => $batch['since'],
                        'online' => $this->onlineNames($pdo),
                    ]);
                    $cursor = (int) $batch['since'];
                } else {
                    $this->sse('ping', [
                        'ok' => true,
                        'since' => $cursor,
                        'online' => $this->onlineNames($pdo),
                    ]);
                }
            } catch (Throwable $e) {
                error_log('[chat-api] stream loop: ' . $e->getMessage());
                $this->sse('error', ['ok' => false, 'message' => 'Stream interrupted.']);
                return;
            }

            usleep(self::STREAM_SLEEP_US);
        }

        $this->sse('reconnect', ['ok' => true, 'since' => $cursor]);
    }

    /**
     * @return array{ok: bool, online?: list<string>, message?: string}
     */
    public function presence(string $token): array
    {
        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $this->touchUser($pdo, (int) $user['id']);
            $this->expireStaleUsers($pdo);

            return ['ok' => true, 'online' => $this->onlineNames($pdo)];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] presence: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to load presence.'];
        }
    }

    private function pdo(): PDO
    {
        $pdo = Database::connection($this->config);
        Database::ensureChatTables($pdo);

        return $pdo;
    }

    /**
     * @return array{id: int|string, token: string, name: string, name_key?: string}
     */
    private function requireUser(PDO $pdo, string $token): array
    {
        $user = $this->findUser($pdo, $token);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid or expired chat session. Join again.');
        }

        return $user;
    }

    /**
     * @return array{id: int|string, token: string, name: string}|null
     */
    private function findUser(PDO $pdo, string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $pdo->prepare('SELECT id, token, name, name_key FROM chat_users WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    private function touchUser(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('UPDATE chat_users SET last_seen_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    private function allowMessage(PDO $pdo, int $userId): bool
    {
        $stmt = $pdo->prepare('SELECT name, last_message_at FROM chat_users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return false;
        }

        if (!empty($row['last_message_at'])) {
            $elapsedMs = (int) round((microtime(true) - strtotime((string) $row['last_message_at'])) * 1000);
            if ($elapsedMs >= 0 && $elapsedMs < self::RATE_LIMIT_MS) {
                return false;
            }
        }

        $countStmt = $pdo->prepare(
            'SELECT COUNT(*) FROM chat_events
             WHERE type = \'message\' AND user_name = :name
               AND created_at >= (NOW() - INTERVAL 1 MINUTE)'
        );
        $countStmt->execute([':name' => (string) $row['name']]);
        if ((int) $countStmt->fetchColumn() >= self::RATE_LIMIT_PER_MINUTE) {
            return false;
        }

        return true;
    }

    private function markMessageSent(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('UPDATE chat_users SET last_message_at = NOW(), last_seen_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
    }

    private function expireStaleUsers(PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'SELECT id, name FROM chat_users
             WHERE last_seen_at < (NOW() - INTERVAL ' . self::USER_TTL_SECONDS . ' SECOND)'
        );
        $stmt->execute();
        $stale = $stmt->fetchAll();

        foreach ($stale as $user) {
            $this->removeUser($pdo, (int) $user['id'], (string) $user['name']);
        }
    }

    private function removeUser(PDO $pdo, int $userId, string $name): void
    {
        $stmt = $pdo->prepare('DELETE FROM chat_users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->insertEvent($pdo, 'leave', $name, null);
    }

    private function insertEvent(PDO $pdo, string $type, string $name, ?string $message): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO chat_events (type, user_name, message) VALUES (:type, :name, :message)'
        );
        $stmt->execute([
            ':type' => $type,
            ':name' => $name,
            ':message' => $message,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(PDO $pdo): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, type, user_name, message, created_at
             FROM chat_events
             WHERE type IN (\'join\', \'message\', \'leave\')
             ORDER BY id DESC
             LIMIT ' . self::HISTORY_LIMIT
        );
        $stmt->execute();
        $rows = array_reverse($stmt->fetchAll());

        return array_map([$this, 'mapEvent'], $rows);
    }

    /**
     * @return array{ok: bool, events: list<array<string, mixed>>, since: int}
     */
    private function eventsSince(PDO $pdo, int $since): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, type, user_name, message, created_at
             FROM chat_events
             WHERE id > :since AND type IN (\'join\', \'message\', \'leave\')
             ORDER BY id ASC
             LIMIT 100'
        );
        $stmt->execute([':since' => max(0, $since)]);
        $rows = $stmt->fetchAll();

        $events = [];
        $latest = $since;
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $latest = max($latest, $id);
            $events[] = $this->mapEvent($row);
        }

        return [
            'ok' => true,
            'events' => $events,
            'since' => $latest > 0 ? $latest : $this->latestEventId($pdo),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapEvent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'name' => (string) $row['user_name'],
            'message' => $row['message'] !== null ? (string) $row['message'] : null,
            'createdAt' => (string) $row['created_at'],
        ];
    }

    /**
     * @return list<string>
     */
    private function onlineNames(PDO $pdo): array
    {
        $stmt = $pdo->query('SELECT name FROM chat_users ORDER BY name ASC');
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[] = (string) $row['name'];
        }

        return $names;
    }

    private function latestEventId(PDO $pdo): int
    {
        $id = $pdo->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM chat_events')->fetchColumn();

        return (int) $id;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sse(string $event, array $payload): void
    {
        echo 'event: ' . $event . "\n";
        echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
        @flush();
    }

    private function cleanName(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return mb_substr($value, 0, 100);
    }

    private function cleanMessage(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        // Keep characters like "<" intact; XSS is avoided by textContent on the client.
        $value = str_replace("\0", '', $value);
        $value = trim($value);

        return mb_substr($value, 0, 2000);
    }
}
