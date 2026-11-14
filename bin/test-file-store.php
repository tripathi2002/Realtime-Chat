<?php

declare(strict_types=1);

/**
 * Positive + negative scenario checks for file buffer + flush flow.
 * Run: php bin/test-file-store.php
 */

require_once dirname(__DIR__) . '/src/FileStore.php';
require_once dirname(__DIR__) . '/src/FlushService.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Response.php';

$root = sys_get_temp_dir() . '/vchat_store_test_' . bin2hex(random_bytes(4));
$passed = 0;
$failed = 0;

function expect(bool $cond, string $label): void
{
    global $passed, $failed;
    if ($cond) {
        echo "[PASS] {$label}\n";
        $passed++;
    } else {
        echo "[FAIL] {$label}\n";
        $failed++;
    }
}

try {
    $store = new FileStore($root);

    // Positive: append + read since
    $e1 = $store->appendEvent(1, ['type' => 'join', 'name' => 'Ada', 'message' => null]);
    $e2 = $store->appendEvent(1, ['type' => 'message', 'name' => 'Ada', 'message' => 'hello']);
    expect($e1['id'] === 1 && $e2['id'] === 2, 'positive: sequential event ids');
    $since = $store->eventsSince(1, 1);
    expect(count($since) === 1 && $since[0]['message'] === 'hello', 'positive: eventsSince returns only newer');

    // Positive: presence upsert + find
    $token = bin2hex(random_bytes(32));
    $store->upsertPresence(1, [
        'token' => $token,
        'accountId' => 9,
        'name' => 'Ada',
        'publicId' => 'u_ada',
    ]);
    $found = $store->findPresenceByToken($token);
    expect($found !== null && $found['name'] === 'Ada', 'positive: presence find by token');
    expect($store->onlineNames(1) === ['Ada'], 'positive: online names');

    // Positive: same account replaces old token (no duplicate presence)
    $token2 = bin2hex(random_bytes(32));
    $store->upsertPresence(1, [
        'token' => $token2,
        'accountId' => 9,
        'name' => 'Ada',
        'publicId' => 'u_ada',
    ]);
    expect($store->findPresenceByToken($token) === null, 'positive: old token removed on rejoin');
    expect($store->findPresenceByToken($token2) !== null, 'positive: new token active');

    // Positive: leave
    $removed = $store->removePresence($token2);
    expect($removed !== null && $removed['name'] === 'Ada', 'positive: remove presence');
    expect($store->onlineNames(1) === [], 'positive: room empty after leave');

    // Positive: unflushed collect
    expect($store->hasUnflushedData() === true, 'positive: hasUnflushedData when events exist');
    $unflushed = $store->collectUnflushedEvents();
    expect(count($unflushed) >= 2, 'positive: collectUnflushedEvents');

    // Positive: mark flushed + clean file (room-scoped keys)
    $keys = array_map(static fn ($r) => ['roomId' => (int) $r['roomId'], 'id' => (int) $r['id']], $unflushed);
    $compact = $store->markEventsFlushed($keys, true);
    expect(($compact['removed'] ?? 0) >= 2, 'positive: markEventsFlushed removes lines');
    expect($store->hasUnflushedData() === false, 'positive: file clean after flush mark');

    // Positive: room-scoped flush keys do not cross-delete
    $store->appendEvent(10, ['type' => 'message', 'name' => 'A', 'message' => 'room10']);
    $store->appendEvent(11, ['type' => 'message', 'name' => 'B', 'message' => 'room11']);
    $store->markEventsFlushed([['roomId' => 10, 'id' => 1]], true);
    expect(count($store->eventsSince(11, 0)) === 1, 'positive: flushing room 10 does not clear room 11 id=1');

    // Negative: invalid token
    expect($store->findPresenceByToken('bad') === null, 'negative: invalid token ignored');
    expect($store->removePresence('nope') === null, 'negative: remove missing token is no-op');

    // Negative: corrupt / empty events file
    $badRoom = $root . '/rooms/2';
    mkdir($badRoom, 0755, true);
    file_put_contents($badRoom . '/events.jsonl', "{not-json}\n\n{\"id\":1,\"type\":\"message\",\"name\":\"X\",\"message\":\"ok\",\"createdAt\":\"2026-01-01 00:00:00\",\"flushed\":false}\n");
    $parsed = $store->eventsSince(2, 0);
    expect(count($parsed) === 1 && $parsed[0]['message'] === 'ok', 'negative: skips corrupt JSONL lines');

    // Negative: flush lock busy
    $lockPath = $root . '/flush/flush.lock';
    $fh = fopen($lockPath, 'c+');
    flock($fh, LOCK_EX);
    $lockFailed = false;
    try {
        $store->withFlushLock(static function () {
            return true;
        });
    } catch (RuntimeException $e) {
        $lockFailed = str_contains($e->getMessage(), 'already running');
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    expect($lockFailed, 'negative: concurrent flush lock rejected');

    // Negative: expire stale users
    $staleToken = bin2hex(random_bytes(32));
    $store->upsertPresence(3, [
        'token' => $staleToken,
        'accountId' => 3,
        'name' => 'Old',
        'publicId' => 'u_old',
    ]);
    // Backdate lastSeenAt
    $presencePath = $root . '/rooms/3/presence.json';
    $presence = json_decode((string) file_get_contents($presencePath), true);
    $presence[$staleToken]['lastSeenAt'] = gmdate('Y-m-d H:i:s', time() - 9999);
    file_put_contents($presencePath, json_encode($presence));
    $expired = $store->expireStale(120);
    expect(count($expired) === 1 && $expired[0]['name'] === 'Old', 'positive: expire stale presence');
    expect($store->findPresenceByToken($staleToken) === null, 'positive: stale session removed');

    // FlushService without DB: should keep files when DB config missing
    $store2 = new FileStore($root . '_flush');
    $store2->appendEvent(7, ['type' => 'message', 'name' => 'Bob', 'message' => 'keep-me']);
    $flush = new FlushService([
        'db' => [
            'host' => '127.0.0.1',
            'port' => '1',
            'name' => 'nope',
            'user' => 'nope',
            'pass' => 'nope',
            'persistent' => false,
        ],
    ], $store2);
    $result = $flush->run(true);
    expect(($result['ok'] ?? true) === false, 'negative: flush fails when DB unreachable');
    expect($store2->hasUnflushedData() === true, 'negative: file data kept after failed flush');

    echo "\n{$passed} passed, {$failed} failed\n";
    exit($failed === 0 ? 0 : 1);
} finally {
    // best-effort cleanup
    $it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
    $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
    }
    @rmdir($root);
}
