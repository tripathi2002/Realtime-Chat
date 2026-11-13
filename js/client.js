const API_BASE = (window.CHAT_API_BASE_URL || '').replace(/\/$/, '');
const POLL_INTERVAL_MS = 1500;

const form = document.getElementById('send-container');
const messageInput = document.getElementById('messageInp');
const messageContainer = document.querySelector('.container');

let token = null;
let since = 0;
let pollTimer = null;
let myName = '';

const append = (message, position) => {
  const messageElement = document.createElement('div');
  messageElement.innerText = message;
  messageElement.classList.add('message');
  messageElement.classList.add(position);
  messageContainer.append(messageElement);
  messageContainer.scrollTop = messageContainer.scrollHeight;
};

const api = async (path, options = {}) => {
  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers: {
      Accept: 'application/json',
      ...(options.body ? { 'Content-Type': 'application/json' } : {}),
      ...(token ? { 'X-Chat-Token': token } : {}),
      ...(options.headers || {}),
    },
  });

  const data = await response.json().catch(() => ({}));
  if (!response.ok || data.ok === false) {
    throw new Error(data.message || `Request failed (${response.status})`);
  }

  return data;
};

const handleEvents = (events) => {
  for (const event of events) {
    if (event.type === 'join' && event.name !== myName) {
      append(`${event.name} joined the chat`, 'right');
    } else if (event.type === 'message' && event.name !== myName) {
      append(`${event.name}: ${event.message}`, 'left');
      playNotification();
    } else if (event.type === 'leave') {
      append(`${event.name}: left the chat`, 'left');
    }
  }
};

const playNotification = () => {
  try {
    const audio = new Audio('public/noti.wav');
    audio.play().catch(() => {});
  } catch (_) {
    // Ignore missing/blocked audio.
  }
};

const poll = async () => {
  if (!token) {
    return;
  }

  try {
    const data = await api(`/poll?token=${encodeURIComponent(token)}&since=${since}`);
    if (Array.isArray(data.events) && data.events.length > 0) {
      handleEvents(data.events);
    }
    if (typeof data.since === 'number') {
      since = data.since;
    }
  } catch (error) {
    append(`Connection issue: ${error.message}`, 'left');
    stopPolling();
  }
};

const startPolling = () => {
  stopPolling();
  pollTimer = setInterval(poll, POLL_INTERVAL_MS);
  poll();
};

const stopPolling = () => {
  if (pollTimer) {
    clearInterval(pollTimer);
    pollTimer = null;
  }
};

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!token) {
    return;
  }

  const message = messageInput.value.trim();
  if (!message) {
    return;
  }

  append(`you: ${message}`, 'right');
  messageInput.value = '';

  try {
    await api('/message', {
      method: 'POST',
      body: JSON.stringify({ token, message }),
    });
  } catch (error) {
    append(`Failed to send: ${error.message}`, 'left');
  }
});

const leaveChat = () => {
  if (!token) {
    return;
  }

  const payload = JSON.stringify({ token });
  if (navigator.sendBeacon) {
    navigator.sendBeacon(`${API_BASE}/leave`, new Blob([payload], { type: 'application/json' }));
  } else {
    fetch(`${API_BASE}/leave`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true,
    }).catch(() => {});
  }
};

window.addEventListener('pagehide', leaveChat);

(async () => {
  myName = (prompt('Enter your name :') || '').trim();
  if (!myName) {
    append('Name is required to join the chat.', 'left');
    return;
  }

  try {
    const data = await api('/join', {
      method: 'POST',
      body: JSON.stringify({ name: myName }),
    });
    token = data.token;
    since = data.since || 0;
    append(`you joined the chat as ${myName}`, 'right');
    startPolling();
  } catch (error) {
    append(`Unable to join: ${error.message}`, 'left');
  }
})();
