<?php

declare(strict_types=1);

final class ChatService
{
    private const USER_TTL_SECONDS = 90;

    public function __construct(private readonly array $config)
    {
    }

    public function health(): array
    {
        return [
            'ok' => true,
            'service' => 'realtime-chat-api',
            'storage' => 'mysql',
            'transport' => 'http-poll',
            'message' => 'Chat API is online.',
        ];
    }

    /**
     * @return array{ok: bool, token?: string, name?: string, since?: int, errors?: array<string, string>, message?: string}
     */
    public function join(array $input): array
    {
        $name = $this->clean($input['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            return ['ok' => false, 'errors' => ['name' => 'Name is required (max 100 chars).']];
        }

        try {
            $pdo = $this->pdo();
            $this->expireStaleUsers($pdo);

            $token = bin2hex(random_bytes(32));
            $stmt = $pdo->prepare(
                'INSERT INTO chat_users (token, name, last_seen_at) VALUES (:token, :name, NOW())'
            );
            $stmt->execute([':token' => $token, ':name' => $name]);

            $this->insertEvent($pdo, 'join', $name, null);
            $since = $this->latestEventId($pdo);

            return [
                'ok' => true,
                'token' => $token,
                'name' => $name,
                'since' => $since,
            ];
        } catch (Throwable $e) {
            error_log('[chat-api] join: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to join right now. Please try again later.'];
        }
    }

    /**
     * @return array{ok: bool, errors?: array<string, string>, message?: string}
     */
    public function sendMessage(string $token, array $input): array
    {
        $message = $this->clean($input['message'] ?? '');
        if ($message === '' || mb_strlen($message) > 2000) {
            return ['ok' => false, 'errors' => ['message' => 'Message is required (max 2000 chars).']];
        }

        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $this->touchUser($pdo, (int) $user['id']);
            $this->insertEvent($pdo, 'message', (string) $user['name'], $message);

            return ['ok' => true];
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
     * @return array{ok: bool, events?: list<array<string, mixed>>, since?: int, message?: string}
     */
    public function poll(string $token, int $since): array
    {
        try {
            $pdo = $this->pdo();
            $user = $this->requireUser($pdo, $token);
            $this->touchUser($pdo, (int) $user['id']);
            $this->expireStaleUsers($pdo);

            $stmt = $pdo->prepare(
                'SELECT id, type, user_name, message, created_at
                 FROM chat_events
                 WHERE id > :since
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
                $events[] = [
                    'id' => $id,
                    'type' => (string) $row['type'],
                    'name' => (string) $row['user_name'],
                    'message' => $row['message'] !== null ? (string) $row['message'] : null,
                    'createdAt' => (string) $row['created_at'],
                ];
            }

            return [
                'ok' => true,
                'events' => $events,
                'since' => $latest > 0 ? $latest : $this->latestEventId($pdo),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] poll: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to poll events right now.'];
        }
    }

    private function pdo(): PDO
    {
        $pdo = Database::connection($this->config);
        Database::ensureChatTables($pdo);

        return $pdo;
    }

    /**
     * @return array{id: int|string, token: string, name: string}
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

        $stmt = $pdo->prepare('SELECT id, token, name FROM chat_users WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $user = $stmt->fetch();

        return is_array($user) ? $user : null;
    }

    private function touchUser(PDO $pdo, int $userId): void
    {
        $stmt = $pdo->prepare('UPDATE chat_users SET last_seen_at = NOW() WHERE id = :id');
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

    private function insertEvent(PDO $pdo, string $type, string $name, ?string $message): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO chat_events (type, user_name, message) VALUES (:type, :name, :message)'
        );
        $stmt->execute([
            ':type' => $type,
            ':name' => $name,
            ':message' => $message,
        ]);
    }

    private function latestEventId(PDO $pdo): int
    {
        $id = $pdo->query('SELECT COALESCE(MAX(id), 0) AS max_id FROM chat_events')->fetchColumn();

        return (int) $id;
    }

    private function clean(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim(strip_tags($value));
    }
}
