# Realtime Chat — PHP API

PHP + MySQL chat backend for Hostinger. Uses short HTTP polling instead of WebSockets so it runs on shared hosting (same pattern as the portfolio contact API).

## Endpoints

| Method | Path | Purpose |
|---|---|---|
| `GET` | `/` | Health check |
| `POST` | `/join` | Join with `{ "name": "..." }` → `{ token, since }` |
| `POST` | `/message` | Send `{ "token", "message" }` |
| `POST` | `/leave` | Leave with `{ "token" }` |
| `GET` | `/poll?token=...&since=0` | Fetch new events |

## Project structure

```
index.php / join.php / message.php / leave.php / poll.php
bootstrap.php
src/ChatService.php
src/Cors.php
src/Database.php
src/Response.php
sql/schema.sql
config.example.php   # copy to config.php on the server
.htaccess
.github/workflows/deploy.yml
```

## Local setup

1. Create a MySQL database and run `sql/schema.sql` (optional — tables auto-create on first request).
2. Copy `config.example.php` → `config.php` and fill DB + CORS origins.
3. Serve with PHP built-in server from this folder:

```bash
php -S localhost:8000
```

## Auto deploy (Hostinger)

Push to `php/api/v2` (or `api/v2/php`). GitHub Actions FTPs to:

`sit/realtime-chat/api/v2/`

Required repo secrets (same as portfolio): `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`.

`config.php` is excluded from deploy — create it once on Hostinger.

## Live URL

`https://vibhu.pro/sit/realtime-chat/api/v2/`
