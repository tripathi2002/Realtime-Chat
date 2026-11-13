# Realtime Chat — Frontend

Vanilla HTML/CSS/JS client for the realtime group chat app. Connects to the Socket.IO backend to send and receive messages.

## Features

- Real-time messaging over WebSockets (Socket.IO client)
- Join / leave notifications
- Notification sound asset in `public/noti.wav`
- Static site (no Node server required)

## Project structure

```
index.html        # chat UI
js/client.js      # Socket.IO client
css/style.css
images/vat.png
public/noti.wav
Dockerfile        # nginx static server
.env.example
```

## Getting started

Open `index.html` in a browser, or serve the folder with any static file server:

```bash
npx serve .
```

> `js/client.js` points at a hosted Socket.IO URL. For local development, switch it to the commented `http://localhost:8000` line and run the backend.

With Docker:

```bash
docker build -t realtime-chat-frontend .
docker run -p 8080:80 realtime-chat-frontend
```

## Environment variables

`SOCKET_SERVER_URL` — document the Socket.IO server the client should use (set in `js/client.js` for now).
