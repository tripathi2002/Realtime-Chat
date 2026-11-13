# Realtime Chat — Frontend

Portfolio-styled chat UI with SSE live updates, session restore, and reconnect.

## Features

- SSE stream (`/stream`) with long-poll fallback
- Auto-reconnect with exponential backoff
- Message history on join/resume
- Unique display names
- `sessionStorage` session restore (survives refresh)
- Serial send queue, retry on failure
- Empty state, timestamps, avatars, date dividers
- Mobile-first composer, jump-to-latest, reconnect banner
- Dark mode + mute toggle
- `noindex` for SIT

## Local

```bash
npx serve .
```

Point `js/config.js` at your local PHP API if needed.

## Deploy

Push `ui/v2`. Optional repo variable/secret `CHAT_API_BASE_URL` is injected into `js/config.js` at deploy time.
