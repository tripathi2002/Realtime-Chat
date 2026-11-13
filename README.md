# Realtime Chat — PHP API

PHP + MySQL chat backend for Hostinger. Live updates use **SSE** (`/stream`) with **long-poll** (`/poll?wait=1`) as fallback.

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/` | Health |
| `POST` | `/join` | Join with unique `{ "name" }` → token, history, online |
| `GET` | `/resume` | Restore session (`X-Chat-Token`) |
| `POST` | `/message` | Send `{ "message" }` (token header) |
| `POST` | `/leave` | Leave room |
| `GET` | `/stream?since=` | SSE event stream |
| `GET` | `/poll?since=&wait=1` | Long-poll fallback |
| `GET` | `/presence` | Online names |

Auth: send `X-Chat-Token` header (not query string).

## Deploy

Push `php/api/v2` → FTP `sit/realtime-chat/api/v2/`. Create `config.php` on the server once.
