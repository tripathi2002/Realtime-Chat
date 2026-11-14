# Realtime Chat — PHP API

Chat hot path (join presence, messages, poll/stream) uses **local files** to avoid Hostinger MySQL connection-hour limits. An **hourly flush** moves pending events into MySQL and cleans the files.

## Storage model

| Data | Where | When DB is used |
|---|---|---|
| Auth / rooms | MySQL | signup, login, create/list room, join room lookup |
| Presence + live events | `storage/rooms/{id}/` | never on poll/stream/message |
| Archived events | MySQL | hourly flush; join/resume history top-up |

```
storage/
  rooms/{roomId}/events.jsonl
  rooms/{roomId}/presence.json
  sessions/{token}.json
  flush/
```

## Hourly flush

Protect with `flush.key` in `config.php`.

Hostinger cron (every hour):

```bash
0 * * * * curl -fsS "https://vibhu.pro/sit/realtime-chat/api/v2/flush?key=YOUR_FLUSH_KEY"
```

Force run (ops):

```bash
curl "https://vibhu.pro/sit/realtime-chat/api/v2/flush?key=YOUR_FLUSH_KEY&force=1"
```

### Flush behaviour

| Scenario | Result |
|---|---|
| File has data | Insert into MySQL → clean file lines |
| File empty | `{ skipped: true }` |
| DB write fails | Files **kept** (no data loss) |
| Flush already running | `409 lock_busy` |
| Bad flush key | `401` |

## Auth / rooms / chat endpoints

Same as before (`X-Auth-Token`, `X-Chat-Token`). See previous docs for signup/login/rooms/join/stream.

## Local scenario tests

```bash
php bin/test-file-store.php
```
