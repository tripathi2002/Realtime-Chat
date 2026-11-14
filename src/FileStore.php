<?php

declare(strict_types=1);

/**
 * File-backed hot storage for presence + events.
 * Avoids MySQL on every poll/stream/message (Hostinger connection caps).
 */
final class FileStore
{
    public function __construct(private readonly string $root)
    {
        $this->ensureDir($this->root);
        $this->ensureDir($this->root . '/rooms');
        $this->ensureDir($this->root . '/sessions');
        $this->ensureDir($this->root . '/flush');
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @param array{type: string, name: string, message: ?string, createdAt?: string} $event
     * @return array{id: int, type: string, name: string, message: ?string, createdAt: string}
     */
    public function appendEvent(int $roomId, array $event): array
    {
        return $this->withRoomLock($roomId, function () use ($roomId, $event) {
            $id = $this->nextSeq($roomId);
            $row = [
                'id' => $id,
                'roomId' => $roomId,
                'type' => (string) $event['type'],
                'name' => (string) $event['name'],
                'message' => $event['message'] ?? null,
                'createdAt' => (string) ($event['createdAt'] ?? gmdate('Y-m-d H:i:s')),
                'flushed' => false,
            ];
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line === false) {
                throw new RuntimeException('Unable to encode event.');
            }
            $path = $this->eventsPath($roomId);
            if (file_put_contents($path, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Unable to write event file.');
            }

            return [
                'id' => $id,
                'type' => $row['type'],
                'name' => $row['name'],
                'message' => $row['message'],
                'createdAt' => $row['createdAt'],
            ];
        });
    }

    /**
     * @return list<array{id: int, type: string, name: string, message: ?string, createdAt: string}>
     */
    public function eventsSince(int $roomId, int $since, int $limit = 100): array
    {
        $events = [];
        foreach ($this->readEvents($roomId) as $row) {
            if ((int) $row['id'] <= $since) {
                continue;
            }
            if (!in_array($row['type'], ['join', 'message', 'leave'], true)) {
                continue;
            }
            $events[] = $this->publicEvent($row);
            if (count($events) >= $limit) {
                break;
            }
        }

        return $events;
    }

    /**
     * @return list<array{id: int, type: string, name: string, message: ?string, createdAt: string}>
     */
    public function history(int $roomId, int $limit = 80): array
    {
        $all = [];
        foreach ($this->readEvents($roomId) as $row) {
            if (!in_array($row['type'], ['join', 'message', 'leave'], true)) {
                continue;
            }
            $all[] = $this->publicEvent($row);
        }

        if (count($all) <= $limit) {
            return $all;
        }

        return array_slice($all, -$limit);
    }

    public function latestEventId(int $roomId): int
    {
        $seqPath = $this->seqPath($roomId);
        if (!is_file($seqPath)) {
            return 0;
        }

        return max(0, (int) trim((string) file_get_contents($seqPath)));
    }

    /**
     * @param array{token: string, accountId: int, name: string, publicId: string, lastMessageAt?: ?string} $session
     * @return array{isNew: bool}
     */
    public function upsertPresence(int $roomId, array $session): array
    {
        $token = (string) $session['token'];
        $accountId = (int) $session['accountId'];
        $isNew = true;

        $this->withRoomLock($roomId, function () use ($roomId, $session, $token, $accountId, &$isNew) {
            $presence = $this->readJson($this->presencePath($roomId), []);
            foreach ($presence as $existingToken => $row) {
                if (!is_array($row)) {
                    continue;
                }
                if ((int) ($row['accountId'] ?? 0) === $accountId) {
                    $isNew = false;
                    if ($existingToken !== $token) {
                        unset($presence[$existingToken]);
                        @unlink($this->sessionPath($existingToken));
                    }
                }
            }

            $presence[$token] = [
                'token' => $token,
                'accountId' => $accountId,
                'name' => (string) $session['name'],
                'publicId' => (string) $session['publicId'],
                'lastSeenAt' => gmdate('Y-m-d H:i:s'),
                'lastMessageAt' => $session['lastMessageAt'] ?? ($presence[$token]['lastMessageAt'] ?? null),
            ];
            $this->writeJson($this->presencePath($roomId), $presence);
        });

        $this->writeJson($this->sessionPath($token), [
            'token' => $token,
            'roomId' => $roomId,
            'accountId' => $accountId,
            'name' => (string) $session['name'],
            'publicId' => (string) $session['publicId'],
        ]);

        return ['isNew' => $isNew];
    }

    /**
     * @return array{token: string, roomId: int, accountId: int, name: string, publicId: string, lastSeenAt?: string, lastMessageAt?: ?string}|null
     */
    public function findPresenceByToken(string $token): ?array
    {
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }

        $session = $this->readJson($this->sessionPath($token), null);
        if (!is_array($session) || !isset($session['roomId'])) {
            return null;
        }

        $roomId = (int) $session['roomId'];
        $presence = $this->readJson($this->presencePath($roomId), []);
        if (!isset($presence[$token]) || !is_array($presence[$token])) {
            return null;
        }

        return [
            'token' => $token,
            'roomId' => $roomId,
            'accountId' => (int) $presence[$token]['accountId'],
            'name' => (string) $presence[$token]['name'],
            'publicId' => (string) ($presence[$token]['publicId'] ?? $session['publicId'] ?? ''),
            'lastSeenAt' => (string) ($presence[$token]['lastSeenAt'] ?? ''),
            'lastMessageAt' => $presence[$token]['lastMessageAt'] ?? null,
        ];
    }

    public function touchPresence(string $token): void
    {
        $user = $this->findPresenceByToken($token);
        if ($user === null) {
            return;
        }
        $roomId = (int) $user['roomId'];
        $this->withRoomLock($roomId, function () use ($roomId, $token) {
            $presence = $this->readJson($this->presencePath($roomId), []);
            if (!isset($presence[$token])) {
                return;
            }
            $presence[$token]['lastSeenAt'] = gmdate('Y-m-d H:i:s');
            $this->writeJson($this->presencePath($roomId), $presence);
        });
    }

    public function markMessageSent(string $token): void
    {
        $user = $this->findPresenceByToken($token);
        if ($user === null) {
            return;
        }
        $roomId = (int) $user['roomId'];
        $this->withRoomLock($roomId, function () use ($roomId, $token) {
            $presence = $this->readJson($this->presencePath($roomId), []);
            if (!isset($presence[$token])) {
                return;
            }
            $now = gmdate('Y-m-d H:i:s');
            $presence[$token]['lastSeenAt'] = $now;
            $presence[$token]['lastMessageAt'] = $now;
            $this->writeJson($this->presencePath($roomId), $presence);
        });
    }

    /**
     * @return array{token: string, roomId: int, name: string}|null
     */
    public function removePresence(string $token): ?array
    {
        $user = $this->findPresenceByToken($token);
        if ($user === null) {
            @unlink($this->sessionPath($token));

            return null;
        }

        $roomId = (int) $user['roomId'];
        $this->withRoomLock($roomId, function () use ($roomId, $token) {
            $presence = $this->readJson($this->presencePath($roomId), []);
            unset($presence[$token]);
            $this->writeJson($this->presencePath($roomId), $presence);
        });
        @unlink($this->sessionPath($token));

        return [
            'token' => $token,
            'roomId' => $roomId,
            'name' => (string) $user['name'],
        ];
    }

    /**
     * @return list<string>
     */
    public function onlineNames(int $roomId): array
    {
        $presence = $this->readJson($this->presencePath($roomId), []);
        $names = [];
        foreach ($presence as $row) {
            if (is_array($row) && isset($row['name'])) {
                $names[] = (string) $row['name'];
            }
        }
        sort($names);

        return $names;
    }

    /**
     * @return list<array{token: string, roomId: int, name: string}>
     */
    public function expireStale(int $ttlSeconds): array
    {
        $removed = [];
        $cutoff = time() - $ttlSeconds;

        foreach ($this->roomIds() as $roomId) {
            $this->withRoomLock($roomId, function () use ($roomId, $cutoff, &$removed) {
                $presence = $this->readJson($this->presencePath($roomId), []);
                $changed = false;
                foreach ($presence as $token => $row) {
                    if (!is_array($row)) {
                        unset($presence[$token]);
                        $changed = true;
                        continue;
                    }
                    $seen = strtotime((string) ($row['lastSeenAt'] ?? '') . ' UTC');
                    if ($seen === false || $seen < $cutoff) {
                        $removed[] = [
                            'token' => (string) $token,
                            'roomId' => $roomId,
                            'name' => (string) ($row['name'] ?? 'Someone'),
                        ];
                        unset($presence[$token]);
                        @unlink($this->sessionPath((string) $token));
                        $changed = true;
                    }
                }
                if ($changed) {
                    $this->writeJson($this->presencePath($roomId), $presence);
                }
            });
        }

        return $removed;
    }

    /**
     * Collect unflushed events across rooms (does not delete yet).
     *
     * @return list<array<string, mixed>>
     */
    public function collectUnflushedEvents(): array
    {
        $out = [];
        foreach ($this->roomIds() as $roomId) {
            foreach ($this->readEvents($roomId) as $row) {
                if (!empty($row['flushed'])) {
                    continue;
                }
                $out[] = $row;
            }
        }
        usort($out, static fn ($a, $b) => ((int) $a['id']) <=> ((int) $b['id']));

        return $out;
    }

    /**
     * After successful DB insert: mark flushed and optionally compact file.
     * On failure callers must NOT call this (negative scenario: keep file data).
     *
     * Keys must be room-scoped because event ids are per-room sequences.
     *
     * @param list<array{roomId: int, id: int}> $flushedKeys
     */
    public function markEventsFlushed(array $flushedKeys, bool $cleanFile): array
    {
        $keySet = [];
        foreach ($flushedKeys as $key) {
            $roomId = (int) ($key['roomId'] ?? 0);
            $id = (int) ($key['id'] ?? 0);
            if ($roomId > 0 && $id > 0) {
                $keySet[$roomId . ':' . $id] = true;
            }
        }
        $result = ['rooms' => 0, 'kept' => 0, 'removed' => 0];

        foreach ($this->roomIds() as $roomId) {
            $this->withRoomLock($roomId, function () use ($roomId, $keySet, $cleanFile, &$result) {
                $rows = $this->readEvents($roomId);
                if ($rows === []) {
                    return;
                }
                $result['rooms']++;
                $kept = [];
                foreach ($rows as $row) {
                    $id = (int) ($row['id'] ?? 0);
                    $mapKey = $roomId . ':' . $id;
                    if (isset($keySet[$mapKey])) {
                        $row['flushed'] = true;
                        $result['removed']++;
                        if (!$cleanFile) {
                            $kept[] = $row;
                        }
                    } else {
                        $kept[] = $row;
                        $result['kept']++;
                    }
                }
                $this->rewriteEvents($roomId, $kept);
            });
        }

        return $result;
    }

    public function hasUnflushedData(): bool
    {
        return $this->collectUnflushedEvents() !== [];
    }

    /**
     * @return list<int>
     */
    public function roomIds(): array
    {
        $dir = $this->root . '/rooms';
        if (!is_dir($dir)) {
            return [];
        }
        $ids = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            if (is_dir($dir . '/' . $entry) && ctype_digit($entry)) {
                $ids[] = (int) $entry;
            }
        }
        sort($ids);

        return $ids;
    }

    public function withFlushLock(callable $fn): mixed
    {
        $this->ensureDir($this->root . '/flush');
        $lockPath = $this->root . '/flush/flush.lock';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Unable to open flush lock.');
        }
        if (!flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            throw new RuntimeException('Flush already running.');
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(int $roomId): array
    {
        $path = $this->eventsPath($roomId);
        if (!is_file($path)) {
            return [];
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return [];
        }
        $rows = [];
        while (($line = fgets($fh)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded) && isset($decoded['id'])) {
                $rows[] = $decoded;
            }
        }
        fclose($fh);

        return $rows;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function rewriteEvents(int $roomId, array $rows): void
    {
        $path = $this->eventsPath($roomId);
        if ($rows === []) {
            if (is_file($path)) {
                file_put_contents($path, '', LOCK_EX);
            }

            return;
        }
        $buf = '';
        foreach ($rows as $row) {
            $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($line !== false) {
                $buf .= $line . "\n";
            }
        }
        if (file_put_contents($path, $buf, LOCK_EX) === false) {
            throw new RuntimeException('Unable to compact event file.');
        }
    }

    private function nextSeq(int $roomId): int
    {
        $path = $this->seqPath($roomId);
        $this->ensureDir(dirname($path));
        $fh = fopen($path, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Unable to open sequence file.');
        }
        flock($fh, LOCK_EX);
        $raw = stream_get_contents($fh);
        $current = (int) trim((string) $raw);
        $next = $current + 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, (string) $next);
        fflush($fh);
        flock($fh, LOCK_UN);
        fclose($fh);

        return $next;
    }

    private function withRoomLock(int $roomId, callable $fn): mixed
    {
        $dir = $this->roomDir($roomId);
        $this->ensureDir($dir);
        $lockPath = $dir . '/room.lock';
        $fh = fopen($lockPath, 'c+');
        if ($fh === false) {
            throw new RuntimeException('Unable to lock room storage.');
        }
        if (!flock($fh, LOCK_EX)) {
            fclose($fh);
            throw new RuntimeException('Unable to acquire room lock.');
        }
        try {
            return $fn();
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    private function roomDir(int $roomId): string
    {
        return $this->root . '/rooms/' . $roomId;
    }

    private function eventsPath(int $roomId): string
    {
        return $this->roomDir($roomId) . '/events.jsonl';
    }

    private function presencePath(int $roomId): string
    {
        return $this->roomDir($roomId) . '/presence.json';
    }

    private function seqPath(int $roomId): string
    {
        return $this->roomDir($roomId) . '/seq';
    }

    private function sessionPath(string $token): string
    {
        return $this->root . '/sessions/' . $token . '.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path, mixed $default): mixed
    {
        if (!is_file($path)) {
            return $default;
        }
        $raw = file_get_contents($path);
        if ($raw === false || trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $this->ensureDir(dirname($path));
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Unable to encode JSON file.');
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $json) === false) {
            throw new RuntimeException('Unable to write temp JSON file.');
        }
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to publish JSON file.');
        }
    }

    private function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create storage directory: ' . $dir);
        }
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, type: string, name: string, message: ?string, createdAt: string}
     */
    private function publicEvent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'message' => $row['message'] !== null ? (string) $row['message'] : null,
            'createdAt' => (string) $row['createdAt'],
        ];
    }
}
