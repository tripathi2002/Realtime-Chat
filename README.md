# Realtime Chat — Backend

Node.js Express + Socket.IO server for the realtime group chat app. Handles connections, message broadcast, and join/leave events.

## Features

- Real-time messaging over WebSockets (Socket.IO)
- Join / leave notifications broadcast to other users
- CORS enabled for a separately hosted frontend
- Dockerfile for containerized runs

## Tech stack

Node.js · Express · Socket.IO · morgan · dotenv · Docker

## Project structure

```
index.js          # Express + Socket.IO server
Dockerfile
.env.example
package.json
```

## Getting started

```bash
npm install
cp .env.example .env
npm run dev            # nodemon index.js, default port 8000
```

With Docker:

```bash
docker build -t realtime-chat-backend .
docker run -p 8000:8000 realtime-chat-backend
```

## Environment variables

`PORT` — HTTP/Socket.IO listen port (default `8000`)

## Socket events

| Event | Direction | Payload |
|---|---|---|
| `new-user-joined` | client → server | name |
| `send` | client → server | message |
| `user-joined` | server → clients | name |
| `receive` | server → clients | `{ message, name }` |
| `leave` | server → clients | name |

## License

No license file has been added to this repository yet.
