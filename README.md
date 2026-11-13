# Realtime Chat — Frontend

Static HTML/CSS/JS chat UI. Talks to the PHP poll API (no Socket.IO).

## Project structure

```
index.html
js/config.js      # API base URL
js/client.js      # join / send / poll loop
css/style.css
images/vat.png
public/noti.wav
.htaccess
.github/workflows/deploy.yml
```

## Local development

1. Run the PHP API locally (`php -S localhost:8000` in `../backend`).
2. Point the client at it:

```js
// js/config.js
window.CHAT_API_BASE_URL = 'http://localhost:8000';
```

3. Serve this folder:

```bash
npx serve .
```

## Auto deploy (Hostinger)

Push to `ui/v2`. GitHub Actions FTPs to:

`sit/realtime-chat/ui/v2/`

Required repo secrets: `FTP_HOST`, `FTP_USER`, `FTP_PASSWORD`.

## Live URL

`https://vibhu.pro/sit/realtime-chat/ui/v2/`
