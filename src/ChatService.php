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
        private readonly FileStore $store,
    ) {
    }

    public function health(): array
    {
        return [
            'ok' => true,
            'service' => 'realtime-chat-api',
            'storage' => 'file+hourly-db-flush',
            'transport' => 'sse+long-poll',
            'message' => 'Chat API is online.',
            'pendingFlush' => $this->store->hasUnflushedData(),
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
            // Rare DB hit: validate room exists.
            $pdo = Database::connection($this->config);
            Database::ensureChatTables($pdo);
            $room = $this->rooms->requireRoom($pdo, $roomCode);
            if ($room === null) {
                return ['ok' => false, 'message' => 'Room not found.'];
            }

            $this->expireStaleUsers();

            $roomId = (int) $room['id'];
            $token = bin2hex(random_bytes(32));
            $name = (string) $account['display_name'];
            $publicId = (string) $account['public_id'];

            $presence = $this->store->upsertPresence($roomId, [
                'token' => $token,
                'accountId' => (int) $account['id'],
                'name' => $name,
                'publicId' => $publicId,
            ]);

            if ($presence['isNew']) {
                $this->store->appendEvent($roomId, [
                    'type' => 'join',
                    'name' => $name,
                    'message' => null,
                ]);
            }

            return [
                'ok' => true,
                'token' => $token,
                'name' => $name,
                'publicId' => $publicId,
                'room' => [
                    'code' => (string) $room['code'],
                    'name' => (string) $room['name'],
                    'path' => '?room=' . rawurlencode((string) $room['code']),
                ],
                'since' => $this->store->latestEventId($roomId),
                'history' => $this->history($roomId, $pdo),
                'online' => $this->store->onlineNames($roomId),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
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
            $user = $this->requireUser($token);
            $roomId = (int) $user['roomId'];
            $this->store->touchPresence($token);
            $this->expireStaleUsers();

            $room = null;
            try {
                $pdo = Database::connection($this->config);
                Database::ensureChatTables($pdo);
                $roomStmt = $pdo->prepare('SELECT code, name FROM rooms WHERE id = :id LIMIT 1');
                $roomStmt->execute([':id' => $roomId]);
                $row = $roomStmt->fetch();
                if (is_array($row)) {
                    $room = [
                        'code' => (string) $row['code'],
                        'name' => (string) $row['name'],
                        'path' => '?room=' . rawurlencode((string) $row['code']),
                    ];
                }
                $history = $this->history($roomId, $pdo);
            } catch (Throwable $e) {
                error_log('[chat-api] resume room meta: ' . $e->getMessage());
                $history = $this->store->history($roomId, self::HISTORY_LIMIT);
            }

            return [
                'ok' => true,
                'name' => (string) $user['name'],
                'publicId' => (string) ($user['publicId'] ?? ''),
                'room' => $room,
                'since' => $this->store->latestEventId($roomId),
                'history' => $history,
                'online' => $this->store->onlineNames($roomId),
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
            $user = $this->requireUser($token);
            if (!$this->allowMessage($user)) {
                return ['ok' => false, 'message' => 'You are sending messages too quickly. Please wait a moment.'];
            }

            $event = $this->store->appendEvent((int) $user['roomId'], [
                'type' => 'message',
                'name' => (string) $user['name'],
                'message' => $message,
            ]);
            $this->store->markMessageSent($token);
            $this->rememberRate($user);

            return ['ok' => true, 'id' => (int) $event['id']];
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
            $removed = $this->store->removePresence($token);
            if ($removed !== null) {
                $this->store->appendEvent((int) $removed['roomId'], [
                    'type' => 'leave',
                    'name' => (string) $removed['name'],
                    'message' => null,
                ]);
            }

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
            $user = $this->requireUser($token);
            $roomId = (int) $user['roomId'];
            $this->touchThrottled($token);
            $this->expireStaleUsersThrottled();

            $deadline = microtime(true) + ($wait ? self::STREAM_SECONDS : 0);
            do {
                $events = $this->store->eventsSince($roomId, $since, 100);
                $latest = $this->store->latestEventId($roomId);
                if ($events !== [] || !$wait) {
                    return [
                        'ok' => true,
                        'events' => $events,
                        'since' => $latest > 0 ? max($since, $latest) : $since,
                        'online' => $this->onlineThrottled($roomId, true),
                    ];
                }
                usleep(self::STREAM_SLEEP_US);
                $this->touchThrottled($token);
                $this->expireStaleUsersThrottled();
            } while (microtime(true) < $deadline);

            return [
                'ok' => true,
                'events' => [],
                'since' => $since > 0 ? $since : $this->store->latestEventId($roomId),
                'online' => $this->onlineThrottled($roomId, true),
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
            $user = $this->requireUser($token);
            $roomId = (int) $user['roomId'];
            $this->store->touchPresence($token);
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
            'online' => $this->onlineThrottled($roomId, true),
        ]);

        while (time() < $deadline) {
            if (connection_aborted()) {
                return;
            }

            try {
                $this->expireStaleUsersThrottled();
                $this->touchThrottled($token);
                $events = $this->store->eventsSince($roomId, $cursor, 100);
                if ($events !== []) {
                    $cursor = max($cursor, (int) $events[array_key_last($events)]['id']);
                    $this->sse('events', [
                        'ok' => true,
                        'events' => $events,
                        'since' => $cursor,
                        'online' => $this->onlineThrottled($roomId, true),
                    ]);
                } else {
                    $payload = ['ok' => true, 'since' => $cursor];
                    $online = $this->onlineThrottled($roomId, false);
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
            $user = $this->requireUser($token);
            $this->store->touchPresence($token);
            $this->expireStaleUsers();

            return ['ok' => true, 'online' => $this->store->onlineNames((int) $user['roomId'])];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] presence: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to load presence.'];
        }
    }

    /**
     * @return array{token: string, roomId: int, accountId: int, name: string, publicId: string, lastSeenAt?: string, lastMessageAt?: ?string}
     */
    private function requireUser(string $token): array
    {
        $user = $this->store->findPresenceByToken($token);
        if ($user === null) {
            throw new InvalidArgumentException('Invalid or expired chat session. Join again.');
        }

        return $user;
    }

    /**
     * @param array{token: string, roomId: int, name: string, lastMessageAt?: ?string} $user
     */
    private function allowMessage(array $user): bool
    {
        if (!empty($user['lastMessageAt'])) {
            $elapsedMs = (int) round((microtime(true) - strtotime((string) $user['lastMessageAt'] . ' UTC')) * 1000);
            if ($elapsedMs >= 0 && $elapsedMs < self::RATE_LIMIT_MS) {
                return false;
            }
        }

        $bucket = $this->readRateBucket($user);
        return count($bucket) < self::RATE_LIMIT_PER_MINUTE;
    }

    /**
     * @param array{token: string, roomId: int} $user
     * @return list<int>
     */
    private function readRateBucket(array $user): array
    {
        $path = $this->ratePath($user);
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        $now = time();

        return array_values(array_filter($decoded, static fn ($ts) => is_int($ts) && $ts >= ($now - 60)));
    }

    /**
     * @param array{token: string, roomId: int} $user
     */
    private function rememberRate(array $user): void
    {
        $path = $this->ratePath($user);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $bucket = $this->readRateBucket($user);
        $bucket[] = time();
        file_put_contents($path, json_encode($bucket), LOCK_EX);
    }

    /**
     * @param array{token: string, roomId: int} $user
     */
    private function ratePath(array $user): string
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string) $user['token']);

        return $this->store->root() . '/rooms/' . (int) $user['roomId'] . '/rate/' . $token . '.json';
    }

    private function expireStaleUsers(): void
    {
        $removed = $this->store->expireStale(self::USER_TTL_SECONDS);
        foreach ($removed as $user) {
            $this->store->appendEvent((int) $user['roomId'], [
                'type' => 'leave',
                'name' => (string) $user['name'],
                'message' => null,
            ]);
        }
        $this->lastExpireAt = time();
    }

    private function expireStaleUsersThrottled(): void
    {
        if ((time() - $this->lastExpireAt) < self::EXPIRE_EVERY_SECONDS) {
            return;
        }
        $this->expireStaleUsers();
    }

    private function touchThrottled(string $token): void
    {
        if ((time() - $this->lastTouchAt) < self::TOUCH_EVERY_SECONDS) {
            return;
        }
        $this->store->touchPresence($token);
        $this->lastTouchAt = time();
    }

    /**
     * @return list<string>|null
     */
    private function onlineThrottled(int $roomId, bool $force): ?array
    {
        if (!$force && (time() - $this->lastPresenceAt) < self::PRESENCE_EVERY_SECONDS) {
            return null;
        }
        $this->lastPresenceAt = time();

        return $this->store->onlineNames($roomId);
    }

    /**
     * File-first history; optionally top up from DB archive after hourly flush.
     *
     * @return list<array<string, mixed>>
     */
    private function history(int $roomId, ?PDO $pdo = null): array
    {
        $fileHistory = $this->store->history($roomId, self::HISTORY_LIMIT);
        if (count($fileHistory) >= self::HISTORY_LIMIT || $pdo === null) {
            return $fileHistory;
        }

        try {
            $need = self::HISTORY_LIMIT - count($fileHistory);
            $stmt = $pdo->prepare(
                'SELECT id, type, user_name, message, created_at
                 FROM chat_events
                 WHERE room_id = :room_id AND type IN (\'join\', \'message\', \'leave\')
                 ORDER BY id DESC
                 LIMIT ' . (int) $need
            );
            $stmt->execute([':room_id' => $roomId]);
            $dbRows = array_reverse($stmt->fetchAll());
            $dbMapped = [];
            foreach ($dbRows as $row) {
                // Negative ids = archived DB rows so they never collide with live file seq / since cursors.
                $dbMapped[] = [
                    'id' => -1 * (int) $row['id'],
                    'type' => (string) $row['type'],
                    'name' => (string) $row['user_name'],
                    'message' => $row['message'] !== null ? (string) $row['message'] : null,
                    'createdAt' => (string) $row['created_at'],
                ];
            }

            // Prefer chronological: older DB archive then newer file buffer.
            return array_slice(array_merge($dbMapped, $fileHistory), -self::HISTORY_LIMIT);
        } catch (Throwable $e) {
            error_log('[chat-api] history db: ' . $e->getMessage());

            return $fileHistory;
        }
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
