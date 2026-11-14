<?php

declare(strict_types=1);

final class ChatService
{
    private const USER_TTL_SECONDS = 120;
    private const HISTORY_LIMIT = 80;
    private const RATE_LIMIT_MS = 500;
    private const RATE_LIMIT_PER_MINUTE = 30;
    private const STREAM_SECONDS = 55;
    private const STREAM_SLEEP_US = 1000000;
    private const EXPIRE_EVERY_SECONDS = 30;
    private const TOUCH_EVERY_SECONDS = 15;
    private const PRESENCE_EVERY_SECONDS = 10;

    private int $lastExpireAt = 0;
    private int $lastTouchAt = 0;
    private int $lastPresenceAt = 0;

    public function __construct(
        private readonly array $config,
        private readonly AuthService $auth,
        private readonly RoomService $rooms,
    ) {
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
     * @return array{ok: bool, token?: string, name?: string, publicId?: string, room?: array<string, mixed>, since?: int, history?: list<array<string, mixed>>, online?: list<string>, errors?: array<string, string>, message?: string}
     */
    public function join(string $authToken, array $input): array
    {
        $roomCode = strtoupper(trim((string) ($input['roomCode'] ?? $input['room'] ?? '')));
        if ($roomCode === '') {
            return ['ok' => false, 'errors' => ['roomCode' => 'Room code is required.']];
        }

        try {
            $account = $this->auth->requireAccount($authToken);
            $pdo = $this->pdo();
            $room = $this->rooms->requireRoom($pdo, $roomCode);
            if ($room === null) {
                return ['ok' => false, 'message' => 'Room not found.'];
            }

            $this->expireStaleUsers($pdo);

            $roomId = (int) $room['id'];
            $accountId = (int) $account['id'];
            $name = (string) $account['display_name'];
            $nameKey = mb_strtolower($name);

            // One presence row per account per room — refresh token if already inside.
            $existing = $pdo->prepare(
                'SELECT id FROM chat_users WHERE room_id = :room_id AND account_id = :account_id LIMIT 1'
            );
            $existing->execute([':room_id' => $roomId, ':account_id' => $accountId]);
            $row = $existing->fetch();

            $token = bin2hex(random_bytes(32));
            if (is_array($row)) {
                $upd = $pdo->prepare(
                    'UPDATE chat_users
                     SET token = :token, name = :name, name_key = :name_key, last_seen_at = NOW()
                     WHERE id = :id'
                );
                $upd->execute([
                    ':token' => $token,
                    ':name' => $name,
                    ':name_key' => $nameKey,
                    ':id' => (int) $row['id'],
                ]);
            } else {
                $ins = $pdo->prepare(
                    'INSERT INTO chat_users (token, room_id, account_id, name, name_key, last_seen_at)
                     VALUES (:token, :room_id, :account_id, :name, :name_key, NOW())'
                );
                $ins->execute([
                    ':token' => $token,
                    ':room_id' => $roomId,
                    ':account_id' => $accountId,
                    ':name' => $name,
                    ':name_key' => $nameKey,
                ]);
                $this->insertEvent($pdo, $roomId, 'join', $name, null);
            }

            return [
                'ok' => true,
                'token' => $token,
                'name' => $name,
                'publicId' => (string) $account['public_id'],
                'room' => [
                    'code' => (string) $room['code'],
                    'name' => (string) $room['name'],
                    'path' => '?room=' . rawurlencode((string) $room['code']),
                ],
                'since' => $this->latestEventId($pdo, $roomId),
                'history' => $this->history($pdo, $roomId),
                'online' => $this->onlineNames($pdo, $roomId),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return ['ok' => false, 'message' => 'You are already joining this room. Try again.'];
            }
            error_log('[chat-api] join: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to join right now. Please try again later.'];
        } catch (Throwable $e) {
            error_log('[chat-api] join: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to join right now. Please try again later.'];
        }
    }

    /**
     * @return array{ok: bool, name?: string, publicId?: string, room?: array<string, mixed>, since?: int, history?: list<array<string, mixed>>, online?: list<string>, message?: string}
     */
    public function resume(string $token): array
    {
        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $roomId = (int) $user['room_id'];
            $this->touchUser($pdo, (int) $user['id']);
            $this->expireStaleUsers($pdo);

            $roomStmt = $pdo->prepare('SELECT code, name FROM rooms WHERE id = :id LIMIT 1');
            $roomStmt->execute([':id' => $roomId]);
            $room = $roomStmt->fetch();

            return [
                'ok' => true,
                'name' => (string) $user['name'],
                'publicId' => (string) ($user['public_id'] ?? ''),
                'room' => is_array($room) ? [
                    'code' => (string) $room['code'],
                    'name' => (string) $room['name'],
                    'path' => '?room=' . rawurlencode((string) $room['code']),
                ] : null,
                'since' => $this->latestEventId($pdo, $roomId),
                'history' => $this->history($pdo, $roomId),
                'online' => $this->onlineNames($pdo, $roomId),
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
            $roomId = (int) $user['room_id'];

            if (!$this->allowMessage($pdo, $userId, $roomId, (string) $user['name'])) {
                return ['ok' => false, 'message' => 'You are sending messages too quickly. Please wait a moment.'];
            }

            $this->touchUser($pdo, $userId);
            $id = $this->insertEvent($pdo, $roomId, 'message', (string) $user['name'], $message);
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

            $this->removeUser($pdo, (int) $user['id'], (int) $user['room_id'], (string) $user['name']);

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
            $roomId = (int) $user['room_id'];
            $this->touchUser($pdo, (int) $user['id']);
            $this->expireStaleUsers($pdo);

            $deadline = microtime(true) + ($wait ? self::STREAM_SECONDS : 0);
            do {
                $result = $this->eventsSince($pdo, $roomId, $since);
                if ($result['events'] !== [] || !$wait) {
                    $result['online'] = $this->onlineNamesThrottled($pdo, $roomId, true);

                    return $result;
                }
                usleep(self::STREAM_SLEEP_US);
                $this->touchUserThrottled($pdo, (int) $user['id']);
                $this->expireStaleUsersThrottled($pdo);
            } while (microtime(true) < $deadline);

            return [
                'ok' => true,
                'events' => [],
                'since' => $since > 0 ? $since : $this->latestEventId($pdo, $roomId),
                'online' => $this->onlineNamesThrottled($pdo, $roomId, true),
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
            $roomId = (int) $user['room_id'];
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
            'online' => $this->onlineNamesThrottled($pdo, $roomId, true),
        ]);

        while (time() < $deadline) {
            if (connection_aborted()) {
                return;
            }

            try {
                $this->expireStaleUsersThrottled($pdo);
                $this->touchUserThrottled($pdo, (int) $user['id']);
                $batch = $this->eventsSince($pdo, $roomId, $cursor);
                if ($batch['events'] !== []) {
                    $this->sse('events', [
                        'ok' => true,
                        'events' => $batch['events'],
                        'since' => $batch['since'],
                        'online' => $this->onlineNamesThrottled($pdo, $roomId, true),
                    ]);
                    $cursor = (int) $batch['since'];
                } else {
                    $payload = [
                        'ok' => true,
                        'since' => $cursor,
                    ];
                    $online = $this->onlineNamesThrottled($pdo, $roomId, false);
                    if ($online !== null) {
                        $payload['online'] = $online;
                    }
                    $this->sse('ping', $payload);
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

            return ['ok' => true, 'online' => $this->onlineNames($pdo, (int) $user['room_id'])];
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
     * @return array{id: int|string, token: string, name: string, room_id: int|string, account_id?: int|string, public_id?: string}
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
     * @return array{id: int|string, token: string, name: string, room_id: int|string, account_id?: int|string, public_id?: string}|null
     */
    private function findUser(PDO $pdo, string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT cu.id, cu.token, cu.name, cu.room_id, cu.account_id, a.public_id
             FROM chat_users cu
             LEFT JOIN accounts a ON a.id = cu.account_id
             WHERE cu.token = :token
             LIMIT 1'
        );
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    private function touchUser(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('UPDATE chat_users SET last_seen_at = NOW() WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->lastTouchAt = time();
    }

    private function touchUserThrottled(PDO $pdo, int $userId): void
    {
        if ((time() - $this->lastTouchAt) < self::TOUCH_EVERY_SECONDS) {
            return;
        }

        $this->touchUser($pdo, $userId);
    }

    private function allowMessage(PDO $pdo, int $userId, int $roomId, string $name): bool
    {
        $stmt = $pdo->prepare('SELECT last_message_at FROM chat_users WHERE id = :id LIMIT 1');
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
             WHERE room_id = :room_id AND type = \'message\' AND user_name = :name
               AND created_at >= (NOW() - INTERVAL 1 MINUTE)'
        );
        $countStmt->execute([':room_id' => $roomId, ':name' => $name]);
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
            'SELECT id, room_id, name FROM chat_users
             WHERE last_seen_at < (NOW() - INTERVAL ' . self::USER_TTL_SECONDS . ' SECOND)'
        );
        $stmt->execute();
        $stale = $stmt->fetchAll();

        foreach ($stale as $user) {
            $this->removeUser($pdo, (int) $user['id'], (int) $user['room_id'], (string) $user['name']);
        }

        $this->lastExpireAt = time();
    }

    private function expireStaleUsersThrottled(PDO $pdo): void
    {
        if ((time() - $this->lastExpireAt) < self::EXPIRE_EVERY_SECONDS) {
            return;
        }

        $this->expireStaleUsers($pdo);
    }

    private function removeUser(PDO $pdo, int $userId, int $roomId, string $name): void
    {
        $stmt = $pdo->prepare('DELETE FROM chat_users WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->insertEvent($pdo, $roomId, 'leave', $name, null);
    }

    private function insertEvent(PDO $pdo, int $roomId, string $type, string $name, ?string $message): int
    {
        $stmt = $pdo->prepare(
            'INSERT INTO chat_events (room_id, type, user_name, message)
             VALUES (:room_id, :type, :name, :message)'
        );
        $stmt->execute([
            ':room_id' => $roomId,
            ':type' => $type,
            ':name' => $name,
            ':message' => $message,
        ]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function history(PDO $pdo, int $roomId): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, type, user_name, message, created_at
             FROM chat_events
             WHERE room_id = :room_id AND type IN (\'join\', \'message\', \'leave\')
             ORDER BY id DESC
             LIMIT ' . self::HISTORY_LIMIT
        );
        $stmt->execute([':room_id' => $roomId]);
        $rows = array_reverse($stmt->fetchAll());

        return array_map([$this, 'mapEvent'], $rows);
    }

    /**
     * @return array{ok: bool, events: list<array<string, mixed>>, since: int}
     */
    private function eventsSince(PDO $pdo, int $roomId, int $since): array
    {
        $stmt = $pdo->prepare(
            'SELECT id, type, user_name, message, created_at
             FROM chat_events
             WHERE room_id = :room_id AND id > :since AND type IN (\'join\', \'message\', \'leave\')
             ORDER BY id ASC
             LIMIT 100'
        );
        $stmt->execute([':room_id' => $roomId, ':since' => max(0, $since)]);
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
            'since' => $latest > 0 ? $latest : $this->latestEventId($pdo, $roomId),
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
    private function onlineNames(PDO $pdo, int $roomId): array
    {
        $stmt = $pdo->prepare('SELECT name FROM chat_users WHERE room_id = :room_id ORDER BY name ASC');
        $stmt->execute([':room_id' => $roomId]);
        $names = [];
        foreach ($stmt->fetchAll() as $row) {
            $names[] = (string) $row['name'];
        }

        $this->lastPresenceAt = time();

        return $names;
    }

    /**
     * @return list<string>|null
     */
    private function onlineNamesThrottled(PDO $pdo, int $roomId, bool $force): ?array
    {
        if (!$force && (time() - $this->lastPresenceAt) < self::PRESENCE_EVERY_SECONDS) {
            return null;
        }

        return $this->onlineNames($pdo, $roomId);
    }

    private function latestEventId(PDO $pdo, int $roomId): int
    {
        $stmt = $pdo->prepare('SELECT COALESCE(MAX(id), 0) AS max_id FROM chat_events WHERE room_id = :room_id');
        $stmt->execute([':room_id' => $roomId]);

        return (int) $stmt->fetchColumn();
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

    private function cleanMessage(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = str_replace("\0", '', $value);
        $value = trim($value);

        return mb_substr($value, 0, 2000);
    }
}
