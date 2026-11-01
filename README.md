# Realtime Chat

A simple group chat app built with Node.js, Express and Socket.IO. Users enter a name, join a shared room and exchange messages instantly; everyone is notified when someone joins or leaves.

## Features

- Real-time messaging over WebSockets (Socket.IO)
- Join / leave notifications broadcast to other users
- Notification sound on incoming messages (`public/noti.wav`)
- Static vanilla JS frontend served by the same Express server
- Dockerfile for containerized runs

## Tech stack

Node.js · Express · Socket.IO · morgan · dotenv · HTML/CSS/JavaScript · Docker

## Project structure

```
index.js              # Express + Socket.IO server
frontend/index.html   # chat UI
frontend/js/client.js # Socket.IO client
frontend/css/style.css
public/noti.wav       # notification sound
Dockerfile
.env.example
```

## Getting started

```bash
npm install
cp .env.example .env
npm run dev            # nodemon index.js, default port 8000
```

> The client in `frontend/js/client.js` points at a hosted Socket.IO URL. For local development, switch it to the commented `http://localhost:8000` line.

With Docker:

```bash
docker build -t realtime-chat .
docker run -p 8000:8000 realtime-chat
```

## Environment variables

`PORT`, `SOCKET_SERVER_URL`

## Socket events

| Event | Direction | Payload |
|---|---|---|
| `new-user-joined` | client → server | name |
| `send` | client → server | message |
| `user-joined` | server → clients | name |
| `receive` | server → clients | `{ message, name }` |
| `leave` | server → clients | name |
## Screenshots

_Screenshots coming soon._

## License

No license file has been added to this repository yet.
