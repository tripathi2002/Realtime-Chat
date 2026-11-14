# Realtime Chat — PHP API

PHP + MySQL chat backend for Hostinger.

## Features

- Signup / login with unique public user IDs (`u_########`)
- Create rooms and join by code / shareable `?room=CODE` link
- SSE live updates (`/stream`) + long-poll fallback
- Room-scoped history, presence, rate limits

## Auth

| Method | Path | Notes |
|---|---|---|
| `POST` | `/signup` | `{ email, password, displayName }` |
| `POST` | `/login` | `{ email, password }` |
| `POST` | `/logout` | header `X-Auth-Token` |
| `GET` | `/me` | header `X-Auth-Token` |

## Rooms

| Method | Path | Notes |
|---|---|---|
| `POST` | `/rooms` | create `{ name }` |
| `GET` | `/rooms` | list my rooms |
| `GET` | `/rooms?code=ABCD1234` | room details |

## Chat (room presence)

| Method | Path | Notes |
|---|---|---|
| `POST` | `/join` | `{ roomCode }` + `X-Auth-Token` → chat token |
| `GET` | `/resume` | restore chat session (`X-Chat-Token`) |
| `POST` | `/message` | `{ message }` |
| `POST` | `/leave` | leave room presence |
| `GET` | `/stream?since=` | SSE |
| `GET` | `/poll?since=&wait=1` | long-poll |

Headers:
- `X-Auth-Token` — account session
- `X-Chat-Token` — in-room presence session

## Deploy

Push `php/api/v2` → `sit/realtime-chat/api/v2/`. Keep `config.php` on the server.
