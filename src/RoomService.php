<?php

declare(strict_types=1);

final class RoomService
{
    public function __construct(
        private readonly array $config,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * @return array{ok: bool, room?: array<string, mixed>, errors?: array<string, string>, message?: string}
     */
    public function create(string $authToken, array $input): array
    {
        $name = $this->cleanName($input['name'] ?? '');
        if ($name === '') {
            return ['ok' => false, 'errors' => ['name' => 'Room name is required.']];
        }

        try {
            $account = $this->auth->requireAccount($authToken);
            $pdo = $this->pdo();
            $code = $this->newRoomCode($pdo);

            $stmt = $pdo->prepare(
                'INSERT INTO rooms (code, name, created_by)
                 VALUES (:code, :name, :created_by)'
            );
            $stmt->execute([
                ':code' => $code,
                ':name' => $name,
                ':created_by' => $account['id'],
            ]);

            return [
                'ok' => true,
                'room' => $this->mapRoom([
                    'code' => $code,
                    'name' => $name,
                    'created_at' => date('Y-m-d H:i:s'),
                    'created_by_public_id' => $account['public_id'],
                ]),
            ];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] room create: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to create room right now.'];
        }
    }

    /**
     * @return array{ok: bool, room?: array<string, mixed>, message?: string}
     */
    public function getByCode(string $code): array
    {
        try {
            $pdo = $this->pdo();
            $room = $this->findRoom($pdo, $code);
            if ($room === null) {
                return ['ok' => false, 'message' => 'Room not found.'];
            }

            return ['ok' => true, 'room' => $this->mapRoom($room)];
        } catch (Throwable $e) {
            error_log('[chat-api] room get: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to load room.'];
        }
    }

    /**
     * @return array{ok: bool, rooms?: list<array<string, mixed>>, message?: string}
     */
    public function listMine(string $authToken): array
    {
        try {
            $account = $this->auth->requireAccount($authToken);
            $pdo = $this->pdo();
            $stmt = $pdo->prepare(
                'SELECT r.code, r.name, r.created_at, a.public_id AS created_by_public_id
                 FROM rooms r
                 INNER JOIN accounts a ON a.id = r.created_by
                 WHERE r.created_by = :account_id
                 ORDER BY r.id DESC
                 LIMIT 50'
            );
            $stmt->execute([':account_id' => $account['id']]);
            $rooms = array_map([$this, 'mapRoom'], $stmt->fetchAll());

            return ['ok' => true, 'rooms' => $rooms];
        } catch (InvalidArgumentException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            error_log('[chat-api] room list: ' . $e->getMessage());

            return ['ok' => false, 'message' => 'Unable to load rooms.'];
        }
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    public function requireRoom(PDO $pdo, string $code): ?array
    {
        return $this->findRoom($pdo, $code);
    }

    private function pdo(): PDO
    {
        $pdo = Database::connection($this->config);
        Database::ensureChatTables($pdo);

        return $pdo;
    }

    /**
     * @return array{id: int, code: string, name: string, created_at?: string, created_by_public_id?: string}|null
     */
    private function findRoom(PDO $pdo, string $code): ?array
    {
        $code = strtoupper(trim($code));
        if ($code === '' || !preg_match('/^[A-Z0-9]{6,12}$/', $code)) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT r.id, r.code, r.name, r.created_at, a.public_id AS created_by_public_id
             FROM rooms r
             LEFT JOIN accounts a ON a.id = r.created_by
             WHERE r.code = :code
             LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_by_public_id' => (string) ($row['created_by_public_id'] ?? ''),
        ];
    }

    private function newRoomCode(PDO $pdo): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ($attempt = 0; $attempt < 12; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $stmt = $pdo->prepare('SELECT id FROM rooms WHERE code = :code LIMIT 1');
            $stmt->execute([':code' => $code]);
            if (!$stmt->fetch()) {
                return $code;
            }
        }

        return strtoupper(bin2hex(random_bytes(4)));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapRoom(array $row): array
    {
        $code = (string) $row['code'];

        return [
            'code' => $code,
            'name' => (string) $row['name'],
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'createdBy' => (string) ($row['created_by_public_id'] ?? ''),
            'path' => '?room=' . rawurlencode($code),
        ];
    }

    private function cleanName(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');

        return mb_substr($value, 0, 100);
    }
}
