<?php

declare(strict_types=1);

final class FlushService
{
    public function __construct(
        private readonly array $config,
        private readonly FileStore $store,
    ) {
    }

    /**
     * Move unflushed file events into MySQL, then clean those lines from files.
     *
     * Positive: data present → inserted → files cleaned.
     * Negative: empty → no-op; lock busy → error; DB fail → files kept; partial insert rolled back.
     *
     * @return array{ok: bool, skipped?: bool, flushed?: int, rooms?: int, message?: string, error?: string}
     */
    public function run(bool $force = false): array
    {
        try {
            return $this->store->withFlushLock(function () use ($force) {
                $events = $this->store->collectUnflushedEvents();
                if ($events === []) {
                    return [
                        'ok' => true,
                        'skipped' => true,
                        'flushed' => 0,
                        'message' => 'No file data to flush.',
                    ];
                }

                if (!$force) {
                    $last = $this->lastFlushAt();
                    if ($last > 0 && (time() - $last) < 3500) {
                        return [
                            'ok' => true,
                            'skipped' => true,
                            'flushed' => 0,
                            'message' => 'Flush ran recently; wait until the next hour window.',
                        ];
                    }
                }

                $pdo = Database::connection($this->config);
                Database::ensureChatTables($pdo);

                $pdo->beginTransaction();
                try {
                    $stmt = $pdo->prepare(
                        'INSERT INTO chat_events (room_id, type, user_name, message, created_at)
                         VALUES (:room_id, :type, :name, :message, :created_at)'
                    );

                    $ledger = $this->readLedger();
                    $keys = [];
                    $newLedger = $ledger;
                    foreach ($events as $event) {
                        $type = (string) ($event['type'] ?? '');
                        if (!in_array($type, ['join', 'message', 'leave', 'typing'], true)) {
                            continue;
                        }
                        $roomId = (int) ($event['roomId'] ?? 0);
                        $fileId = (int) ($event['id'] ?? 0);
                        if ($roomId <= 0 || $fileId <= 0) {
                            continue;
                        }
                        $ledgerKey = $roomId . ':' . $fileId;
                        $createdAt = (string) ($event['createdAt'] ?? gmdate('Y-m-d H:i:s'));
                        if (!isset($ledger[$ledgerKey])) {
                            $stmt->execute([
                                ':room_id' => $roomId,
                                ':type' => $type,
                                ':name' => (string) ($event['name'] ?? ''),
                                ':message' => $event['message'] ?? null,
                                ':created_at' => $createdAt,
                            ]);
                            $newLedger[$ledgerKey] = time();
                        }
                        $keys[] = ['roomId' => $roomId, 'id' => $fileId];
                    }

                    $pdo->commit();
                    $this->writeLedger($newLedger);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('[chat-api] flush db: ' . $e->getMessage());

                    return [
                        'ok' => false,
                        'flushed' => 0,
                        'error' => 'db_write_failed',
                        'message' => 'Database write failed; file data was kept.',
                    ];
                }

                try {
                    $compact = $this->store->markEventsFlushed($keys, true);
                } catch (Throwable $e) {
                    error_log('[chat-api] flush compact: ' . $e->getMessage());

                    return [
                        'ok' => false,
                        'flushed' => count($keys),
                        'error' => 'file_clean_failed',
                        'message' => 'Events were saved to DB but file cleanup failed; retry is idempotent.',
                    ];
                }

                $this->rememberFlushAt();

                return [
                    'ok' => true,
                    'skipped' => false,
                    'flushed' => count($keys),
                    'rooms' => (int) ($compact['rooms'] ?? 0),
                    'message' => 'Flushed file events to database and cleaned files.',
                ];
            });
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'already running')) {
                return [
                    'ok' => false,
                    'error' => 'lock_busy',
                    'message' => 'Another flush is already running.',
                ];
            }

            return [
                'ok' => false,
                'error' => 'flush_failed',
                'message' => $e->getMessage(),
            ];
        } catch (Throwable $e) {
            error_log('[chat-api] flush: ' . $e->getMessage());

            return [
                'ok' => false,
                'error' => 'flush_failed',
                'message' => 'Unexpected flush failure; file data was kept.',
            ];
        }
    }

    private function lastFlushAt(): int
    {
        $path = $this->store->root() . '/flush/last_flush_at';
        if (!is_file($path)) {
            return 0;
        }

        return (int) trim((string) file_get_contents($path));
    }

    private function rememberFlushAt(): void
    {
        $path = $this->store->root() . '/flush/last_flush_at';
        file_put_contents($path, (string) time(), LOCK_EX);
    }

    /**
     * @return array<string, int>
     */
    private function readLedger(): array
    {
        $path = $this->store->root() . '/flush/ledger.json';
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }
        // Keep ledger bounded.
        $cutoff = time() - 86400 * 7;
        $out = [];
        foreach ($decoded as $key => $ts) {
            if (is_string($key) && is_int($ts) && $ts >= $cutoff) {
                $out[$key] = $ts;
            }
        }

        return $out;
    }

    /**
     * @param array<string, int> $ledger
     */
    private function writeLedger(array $ledger): void
    {
        $path = $this->store->root() . '/flush/ledger.json';
        file_put_contents(
            $path,
            json_encode($ledger, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            LOCK_EX
        );
    }
}
