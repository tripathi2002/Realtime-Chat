const API_BASE = (window.CHAT_API_BASE_URL || '').replace(/\/$/, '');
const POLL_INTERVAL_MS = 1500;

const joinPanel = document.getElementById('joinPanel');
const chatPanel = document.getElementById('chatPanel');
const joinForm = document.getElementById('joinForm');
const nameInput = document.getElementById('nameInput');
const joinError = document.getElementById('joinError');
const joinBtn = document.getElementById('joinBtn');
const form = document.getElementById('send-container');
const messageInput = document.getElementById('messageInp');
const sendBtn = document.getElementById('sendBtn');
const messageContainer = document.getElementById('messageContainer');
const leaveBtn = document.getElementById('leaveBtn');
const connectionStatus = document.getElementById('connectionStatus');
const userBadge = document.getElementById('userBadge');

let token = null;
let since = 0;
let pollTimer = null;
let myName = '';

const setStatus = (state, label) => {
  connectionStatus.dataset.state = state;
  connectionStatus.querySelector('.status__label').textContent = label;
};

const showJoinError = (message) => {
  joinError.hidden = !message;
  joinError.textContent = message || '';
};

const appendMessage = ({ body, position, meta }) => {
  const messageElement = document.createElement('article');
  messageElement.classList.add('message', position);

  if (meta) {
    const metaElement = document.createElement('span');
    metaElement.className = 'message__meta';
    metaElement.textContent = meta;
    messageElement.appendChild(metaElement);
  }

  const bodyElement = document.createElement('p');
  bodyElement.className = 'message__body';
  bodyElement.textContent = body;
  messageElement.appendChild(bodyElement);

  messageContainer.appendChild(messageElement);
  messageContainer.scrollTop = messageContainer.scrollHeight;
};

const appendSystem = (body) => {
  appendMessage({ body, position: 'system' });
};

const setComposerEnabled = (enabled) => {
  messageInput.disabled = !enabled;
  sendBtn.disabled = !enabled;
  if (enabled) {
    messageInput.focus();
  }
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

const playNotification = () => {
  try {
    const audio = new Audio('public/noti.wav');
    audio.play().catch(() => {});
  } catch (_) {
    // Ignore missing/blocked audio.
  }
};

const handleEvents = (events) => {
  for (const event of events) {
    if (event.type === 'join' && event.name !== myName) {
      appendSystem(`${event.name} joined the chat`);
    } else if (event.type === 'message' && event.name !== myName) {
      appendMessage({
        body: event.message || '',
        position: 'left',
        meta: event.name,
      });
      playNotification();
    } else if (event.type === 'leave') {
      appendSystem(`${event.name} left the chat`);
    }
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
    setStatus('online', 'Online');
  } catch (error) {
    setStatus('error', 'Disconnected');
    appendSystem(`Connection issue: ${error.message}`);
    setComposerEnabled(false);
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

const enterChat = () => {
  joinPanel.hidden = true;
  chatPanel.hidden = false;
  userBadge.hidden = false;
  userBadge.textContent = myName;
  setComposerEnabled(true);
  setStatus('online', 'Online');
};

const resetToJoin = () => {
  stopPolling();
  token = null;
  since = 0;
  myName = '';
  messageContainer.replaceChildren();
  chatPanel.hidden = true;
  joinPanel.hidden = false;
  userBadge.hidden = true;
  userBadge.textContent = '';
  setComposerEnabled(false);
  setStatus('idle', 'Offline');
  nameInput.focus();
};

const leaveChat = async ({ silent = false } = {}) => {
  if (!token) {
    return;
  }

  const currentToken = token;
  const payload = JSON.stringify({ token: currentToken });

  stopPolling();
  setComposerEnabled(false);

  try {
    if (silent && navigator.sendBeacon) {
      navigator.sendBeacon(`${API_BASE}/leave`, new Blob([payload], { type: 'application/json' }));
    } else {
      await api('/leave', {
        method: 'POST',
        body: payload,
      });
    }
  } catch (_) {
    // Best-effort leave.
  } finally {
    if (!silent) {
      resetToJoin();
    } else {
      token = null;
    }
  }
};

joinForm.addEventListener('submit', async (e) => {
  e.preventDefault();
  showJoinError('');

  const name = nameInput.value.trim();
  if (!name) {
    showJoinError('Display name is required.');
    nameInput.focus();
    return;
  }

  joinBtn.disabled = true;
  joinBtn.textContent = 'Joining…';
  setStatus('connecting', 'Connecting');

  try {
    const data = await api('/join', {
      method: 'POST',
      body: JSON.stringify({ name }),
    });

    myName = data.name || name;
    token = data.token;
    since = data.since || 0;
    enterChat();
    appendSystem(`You joined as ${myName}`);
    startPolling();
  } catch (error) {
    setStatus('error', 'Offline');
    showJoinError(error.message || 'Unable to join right now.');
  } finally {
    joinBtn.disabled = false;
    joinBtn.textContent = 'Join chat';
  }
});

form.addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!token) {
    return;
  }

  const message = messageInput.value.trim();
  if (!message) {
    return;
  }

  appendMessage({
    body: message,
    position: 'right',
    meta: 'You',
  });
  messageInput.value = '';

  try {
    await api('/message', {
      method: 'POST',
      body: JSON.stringify({ token, message }),
    });
  } catch (error) {
    appendSystem(`Failed to send: ${error.message}`);
  }
});

leaveBtn.addEventListener('click', () => {
  leaveChat({ silent: false });
});

window.addEventListener('pagehide', () => {
  leaveChat({ silent: true });
});

nameInput.focus();
