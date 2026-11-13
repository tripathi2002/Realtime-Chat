(() => {
  const API_BASE = (window.CHAT_API_BASE_URL || '').replace(/\/$/, '');
  const SESSION_KEY = 'vchat.session.v2';
  const THEME_KEY = 'vchat.theme';
  const MUTE_KEY = 'vchat.muted';
  const MAX_CHARS = 2000;

  const joinPanel = document.getElementById('joinPanel');
  const chatPanel = document.getElementById('chatPanel');
  const joinForm = document.getElementById('joinForm');
  const nameInput = document.getElementById('nameInput');
  const joinError = document.getElementById('joinError');
  const joinBtn = document.getElementById('joinBtn');
  const form = document.getElementById('send-container');
  const messageInput = document.getElementById('messageInp');
  const sendBtn = document.getElementById('sendBtn');
  const charCount = document.getElementById('charCount');
  const messageContainer = document.getElementById('messageContainer');
  const emptyState = document.getElementById('emptyState');
  const jumpBtn = document.getElementById('jumpBtn');
  const leaveBtn = document.getElementById('leaveBtn');
  const connectionStatus = document.getElementById('connectionStatus');
  const userBadge = document.getElementById('userBadge');
  const userBadgeMobile = document.getElementById('userBadgeMobile');
  const presenceLabel = document.getElementById('presenceLabel');
  const reconnectBanner = document.getElementById('reconnectBanner');
  const reconnectText = document.getElementById('reconnectText');
  const retryBtn = document.getElementById('retryBtn');
  const muteBtn = document.getElementById('muteBtn');
  const themeBtn = document.getElementById('themeBtn');

  let token = null;
  let since = 0;
  let myName = '';
  let muted = localStorage.getItem(MUTE_KEY) === '1';
  let streamAbort = null;
  let reconnectTimer = null;
  let reconnectAttempt = 0;
  let stickToBottom = true;
  let lastDayKey = '';
  let lastMessageSender = '';
  let lastMessageSide = '';
  let sendChain = Promise.resolve();
  let seenIds = new Set();
  let unread = 0;
  let baseTitle = 'v-chat';

  const avatarColor = (name) => {
    let hash = 0;
    for (let i = 0; i < name.length; i += 1) {
      hash = name.charCodeAt(i) + ((hash << 5) - hash);
    }
    const hue = Math.abs(hash) % 360;
    return `hsl(${hue} 58% 42%)`;
  };

  const initials = (name) =>
    name
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase() || '?';

  const formatTime = (iso) => {
    if (!iso) {
      return '';
    }
    const date = new Date(iso.replace(' ', 'T') + 'Z');
    if (Number.isNaN(date.getTime())) {
      const local = new Date(iso);
      return Number.isNaN(local.getTime())
        ? ''
        : local.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  };

  const dayKey = (iso) => {
    if (!iso) {
      return '';
    }
    const date = new Date(iso.replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) {
      return '';
    }
    return date.toDateString();
  };

  const dayLabel = (iso) => {
    const key = dayKey(iso);
    if (!key) {
      return '';
    }
    const date = new Date(key);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (date.toDateString() === today.toDateString()) {
      return 'Today';
    }
    if (date.toDateString() === yesterday.toDateString()) {
      return 'Yesterday';
    }
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
  };

  const linkify = (text) => {
    const frag = document.createDocumentFragment();
    const regex = /(https?:\/\/[^\s<]+)/g;
    let last = 0;
    let match = regex.exec(text);
    while (match) {
      if (match.index > last) {
        frag.appendChild(document.createTextNode(text.slice(last, match.index)));
      }
      const a = document.createElement('a');
      a.href = match[1];
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = match[1];
      frag.appendChild(a);
      last = match.index + match[1].length;
      match = regex.exec(text);
    }
    if (last < text.length) {
      frag.appendChild(document.createTextNode(text.slice(last)));
    }
    return frag;
  };

  const setStatus = (state, label) => {
    connectionStatus.dataset.state = state;
    connectionStatus.querySelector('.status__label').textContent = label;
  };

  const showReconnect = (show, text = 'Reconnecting…') => {
    reconnectBanner.hidden = !show;
    reconnectText.textContent = text;
  };

  const showJoinError = (message) => {
    joinError.hidden = !message;
    joinError.textContent = message || '';
  };

  const updateEmpty = () => {
    const hasContent = messageContainer.querySelector('.message-row, .day-divider');
    emptyState.hidden = Boolean(hasContent);
  };

  const updateUnread = () => {
    document.title = unread > 0 ? `(${unread}) ${baseTitle}` : baseTitle;
  };

  const saveSession = () => {
    if (!token || !myName) {
      sessionStorage.removeItem(SESSION_KEY);
      return;
    }
    sessionStorage.setItem(
      SESSION_KEY,
      JSON.stringify({ token, name: myName, since })
    );
  };

  const loadSession = () => {
    try {
      return JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
    } catch (_) {
      return null;
    }
  };

  const clearSession = () => {
    sessionStorage.removeItem(SESSION_KEY);
  };

  const setComposerEnabled = (enabled) => {
    messageInput.disabled = !enabled;
    sendBtn.disabled = !enabled || !messageInput.value.trim();
    if (enabled) {
      messageInput.focus();
    }
  };

  const resizeComposer = () => {
    messageInput.style.height = 'auto';
    messageInput.style.height = `${Math.min(messageInput.scrollHeight, 128)}px`;
    charCount.textContent = `${messageInput.value.length} / ${MAX_CHARS}`;
    sendBtn.disabled = messageInput.disabled || !messageInput.value.trim();
  };

  const nearBottom = () => {
    const distance =
      messageContainer.scrollHeight - messageContainer.scrollTop - messageContainer.clientHeight;
    return distance < 80;
  };

  const scrollToBottom = (force = false) => {
    if (force || stickToBottom) {
      messageContainer.scrollTop = messageContainer.scrollHeight;
      jumpBtn.hidden = true;
      unread = 0;
      updateUnread();
    }
  };

  const ensureDayDivider = (iso) => {
    const key = dayKey(iso);
    if (!key || key === lastDayKey) {
      return;
    }
    lastDayKey = key;
    const divider = document.createElement('div');
    divider.className = 'day-divider';
    divider.textContent = dayLabel(iso);
    messageContainer.appendChild(divider);
    lastMessageSender = '';
    lastMessageSide = '';
  };

  const appendMessage = ({
    id,
    body,
    side,
    name,
    createdAt,
    pending = false,
    failed = false,
    localId = null,
  }) => {
    if (id && seenIds.has(id)) {
      return null;
    }
    if (id) {
      seenIds.add(id);
    }

    ensureDayDivider(createdAt);
    const grouped = side !== 'system' && name === lastMessageSender && side === lastMessageSide;

    const row = document.createElement('div');
    row.className = `message-row ${side}${grouped ? ' grouped' : ''}`;
    if (localId) {
      row.dataset.localId = localId;
    }
    if (id) {
      row.dataset.eventId = String(id);
    }

    if (side !== 'system') {
      const avatar = document.createElement('div');
      avatar.className = 'avatar';
      avatar.style.background = avatarColor(name || myName || 'user');
      avatar.textContent = initials(name || myName || '?');
      row.appendChild(avatar);
    }

    const bubble = document.createElement('article');
    bubble.className = `message${pending ? ' pending' : ''}${failed ? ' failed' : ''}`;

    if (side !== 'system') {
      const meta = document.createElement('div');
      meta.className = 'message__meta';
      const who = document.createElement('span');
      who.textContent = side === 'right' ? 'You' : name || 'Someone';
      const time = document.createElement('time');
      time.textContent = formatTime(createdAt) || (pending ? 'Sending…' : '');
      meta.append(who, time);
      bubble.appendChild(meta);
    }

    const bodyEl = document.createElement('p');
    bodyEl.className = 'message__body';
    if (side === 'system') {
      bodyEl.textContent = body;
    } else {
      bodyEl.appendChild(linkify(body));
    }
    bubble.appendChild(bodyEl);
    row.appendChild(bubble);
    messageContainer.appendChild(row);

    lastMessageSender = side === 'system' ? '' : name;
    lastMessageSide = side;
    updateEmpty();

    if (stickToBottom || side === 'right') {
      scrollToBottom(true);
    } else {
      jumpBtn.hidden = false;
      unread += 1;
      updateUnread();
    }

    return row;
  };

  const appendSystem = (body, createdAt = null) => {
    appendMessage({ body, side: 'system', createdAt });
  };

  const renderHistory = (history) => {
    messageContainer.querySelectorAll('.message-row, .day-divider').forEach((node) => node.remove());
    seenIds = new Set();
    lastDayKey = '';
    lastMessageSender = '';
    lastMessageSide = '';
    for (const event of history || []) {
      handleEvent(event, { fromHistory: true });
    }
    updateEmpty();
    scrollToBottom(true);
  };

  const updatePresence = (online = []) => {
    const others = online.filter((name) => name !== myName);
    if (others.length === 0) {
      presenceLabel.textContent = 'Only you online';
      return;
    }
    presenceLabel.textContent =
      others.length <= 3
        ? `Online: ${others.join(', ')}`
        : `Online: ${others.slice(0, 3).join(', ')} +${others.length - 3}`;
  };

  const playNotification = () => {
    if (muted || document.hidden) {
      return;
    }
    try {
      const audio = new Audio('public/noti.wav');
      audio.play().catch(() => {});
    } catch (_) {
      // Ignore.
    }
  };

  const handleEvent = (event, { fromHistory = false } = {}) => {
    if (!event || (event.id && seenIds.has(event.id) && event.type === 'message')) {
      if (event?.id && event.type === 'message') {
        return;
      }
    }

    if (event.type === 'join') {
      if (event.name !== myName) {
        appendSystem(`${event.name} joined the chat`, event.createdAt);
      }
      return;
    }

    if (event.type === 'leave') {
      appendSystem(`${event.name} left the chat`, event.createdAt);
      return;
    }

    if (event.type === 'message') {
      const mine = event.name === myName;
      if (mine && !fromHistory) {
        // Optimistic local bubble already shown; mark seen by id only.
        if (event.id) {
          seenIds.add(event.id);
        }
        return;
      }
      appendMessage({
        id: event.id,
        body: event.message || '',
        side: mine ? 'right' : 'left',
        name: event.name,
        createdAt: event.createdAt,
      });
      if (!mine && !fromHistory) {
        playNotification();
      }
    }
  };

  const handleEvents = (events = []) => {
    for (const event of events) {
      handleEvent(event);
      if (typeof event.id === 'number') {
        since = Math.max(since, event.id);
      }
    }
    saveSession();
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
      const error = new Error(data.message || `Request failed (${response.status})`);
      error.status = response.status;
      error.payload = data;
      throw error;
    }

    return data;
  };

  const stopStream = () => {
    if (streamAbort) {
      streamAbort.abort();
      streamAbort = null;
    }
    if (reconnectTimer) {
      clearTimeout(reconnectTimer);
      reconnectTimer = null;
    }
  };

  const scheduleReconnect = () => {
    if (!token) {
      return;
    }
    reconnectAttempt += 1;
    const delay = Math.min(1000 * 2 ** Math.min(reconnectAttempt, 5), 20000);
    setStatus('reconnecting', 'Reconnecting');
    showReconnect(true, `Reconnecting in ${Math.round(delay / 1000)}s…`);
    reconnectTimer = setTimeout(() => {
      startLiveConnection();
    }, delay);
  };

  const readSse = async (response, signal) => {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (!signal.aborted) {
      const { value, done } = await reader.read();
      if (done) {
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const chunks = buffer.split('\n\n');
      buffer = chunks.pop() || '';

      for (const chunk of chunks) {
        const lines = chunk.split('\n');
        let eventName = 'message';
        let dataLine = '';
        for (const line of lines) {
          if (line.startsWith('event:')) {
            eventName = line.slice(6).trim();
          } else if (line.startsWith('data:')) {
            dataLine += line.slice(5).trim();
          }
        }
        if (!dataLine) {
          continue;
        }
        let payload = {};
        try {
          payload = JSON.parse(dataLine);
        } catch (_) {
          continue;
        }

        if (eventName === 'error') {
          throw new Error(payload.message || 'Stream error');
        }
        if (payload.events) {
          handleEvents(payload.events);
        }
        if (typeof payload.since === 'number') {
          since = Math.max(since, payload.since);
          saveSession();
        }
        if (payload.online) {
          updatePresence(payload.online);
        }
        if (eventName === 'ready' || eventName === 'ping' || eventName === 'events') {
          reconnectAttempt = 0;
          setStatus('online', 'Online');
          showReconnect(false);
        }
      }
    }
  };

  const startSse = async (signal) => {
    const response = await fetch(`${API_BASE}/stream?since=${since}`, {
      headers: {
        Accept: 'text/event-stream',
        'X-Chat-Token': token,
      },
      signal,
    });

    if (!response.ok || !response.body) {
      throw new Error(`SSE failed (${response.status})`);
    }

    await readSse(response, signal);
  };

  const startLongPollLoop = async (signal) => {
    while (!signal.aborted && token) {
      const data = await api(`/poll?since=${since}&wait=1`, { signal });
      if (Array.isArray(data.events) && data.events.length) {
        handleEvents(data.events);
      }
      if (typeof data.since === 'number') {
        since = Math.max(since, data.since);
        saveSession();
      }
      if (data.online) {
        updatePresence(data.online);
      }
      reconnectAttempt = 0;
      setStatus('online', 'Online');
      showReconnect(false);
    }
  };

  const startLiveConnection = async () => {
    if (!token) {
      return;
    }
    stopStream();
    streamAbort = new AbortController();
    const { signal } = streamAbort;
    setStatus('connecting', 'Connecting');

    try {
      await startSse(signal);
      if (!signal.aborted && token) {
        scheduleReconnect();
      }
    } catch (error) {
      if (signal.aborted) {
        return;
      }
      try {
        await startLongPollLoop(signal);
      } catch (pollError) {
        if (signal.aborted) {
          return;
        }
        if (pollError.status === 401) {
          appendSystem('Session expired. Please join again.');
          resetToJoin();
          return;
        }
        scheduleReconnect();
      }
    }
  };

  const enterChat = () => {
    joinPanel.hidden = true;
    chatPanel.hidden = false;
    userBadge.hidden = false;
    userBadge.textContent = myName;
    userBadgeMobile.hidden = false;
    userBadgeMobile.textContent = myName;
    setComposerEnabled(true);
    setStatus('online', 'Online');
  };

  const resetToJoin = () => {
    stopStream();
    token = null;
    since = 0;
    myName = '';
    clearSession();
    messageContainer.querySelectorAll('.message-row, .day-divider').forEach((node) => node.remove());
    seenIds = new Set();
    lastDayKey = '';
    lastMessageSender = '';
    lastMessageSide = '';
    unread = 0;
    updateUnread();
    updateEmpty();
    chatPanel.hidden = true;
    joinPanel.hidden = false;
    userBadge.hidden = true;
    userBadgeMobile.hidden = true;
    setComposerEnabled(false);
    setStatus('idle', 'Offline');
    showReconnect(false);
    nameInput.focus();
  };

  const leaveChat = async () => {
    if (!token) {
      resetToJoin();
      return;
    }
    const activeToken = token;
    stopStream();
    try {
      await api('/leave', {
        method: 'POST',
        body: JSON.stringify({}),
        headers: { 'X-Chat-Token': activeToken },
      });
    } catch (_) {
      // Best effort.
    }
    resetToJoin();
  };

  const markLocalState = (localId, { pending, failed, eventId }) => {
    const row = messageContainer.querySelector(`[data-local-id="${localId}"]`);
    if (!row) {
      return;
    }
    const bubble = row.querySelector('.message');
    bubble.classList.toggle('pending', Boolean(pending));
    bubble.classList.toggle('failed', Boolean(failed));
    if (eventId) {
      row.dataset.eventId = String(eventId);
      seenIds.add(eventId);
    }
    let retry = bubble.querySelector('.message__retry');
    if (failed) {
      if (!retry) {
        retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'message__retry';
        retry.textContent = 'Retry';
        retry.addEventListener('click', () => {
          const body = bubble.querySelector('.message__body')?.textContent || '';
          row.remove();
          queueSend(body);
        });
        bubble.appendChild(retry);
      }
    } else if (retry) {
      retry.remove();
    }
  };

  const queueSend = (message) => {
    const localId = `local-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    appendMessage({
      body: message,
      side: 'right',
      name: myName,
      createdAt: new Date().toISOString(),
      pending: true,
      localId,
    });

    sendChain = sendChain
      .then(async () => {
        try {
          const data = await api('/message', {
            method: 'POST',
            body: JSON.stringify({ message }),
          });
          markLocalState(localId, { pending: false, failed: false, eventId: data.id });
          if (typeof data.id === 'number') {
            since = Math.max(since, data.id);
            saveSession();
          }
        } catch (error) {
          markLocalState(localId, { pending: false, failed: true });
          if (error.status === 429) {
            appendSystem(error.message);
          }
        }
      })
      .catch(() => {});
  };

  const bootstrapSession = async (session) => {
    token = session.token;
    myName = session.name;
    since = session.since || 0;
    setStatus('connecting', 'Connecting');
    try {
      const data = await api('/resume');
      myName = data.name || myName;
      since = data.since || since;
      enterChat();
      renderHistory(data.history || []);
      updatePresence(data.online || []);
      saveSession();
      startLiveConnection();
    } catch (_) {
      clearSession();
      token = null;
      myName = '';
      setStatus('idle', 'Offline');
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
      renderHistory(data.history || []);
      updatePresence(data.online || []);
      appendSystem(`You joined as ${myName}`);
      saveSession();
      startLiveConnection();
    } catch (error) {
      setStatus('error', 'Offline');
      const fieldError = error.payload?.errors?.name;
      showJoinError(fieldError || error.message || 'Unable to join right now.');
    } finally {
      joinBtn.disabled = false;
      joinBtn.textContent = 'Join chat';
    }
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!token || messageInput.disabled) {
      return;
    }
    const message = messageInput.value.trim();
    if (!message) {
      return;
    }
    messageInput.value = '';
    resizeComposer();
    queueSend(message);
  });

  messageInput.addEventListener('input', resizeComposer);
  messageInput.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  messageContainer.addEventListener('scroll', () => {
    stickToBottom = nearBottom();
    if (stickToBottom) {
      jumpBtn.hidden = true;
      unread = 0;
      updateUnread();
    }
  });

  jumpBtn.addEventListener('click', () => scrollToBottom(true));
  leaveBtn.addEventListener('click', () => leaveChat());
  retryBtn.addEventListener('click', () => {
    reconnectAttempt = 0;
    showReconnect(true, 'Reconnecting…');
    startLiveConnection();
  });

  muteBtn.addEventListener('click', () => {
    muted = !muted;
    localStorage.setItem(MUTE_KEY, muted ? '1' : '0');
    muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    muteBtn.textContent = muted ? '🔇' : '🔊';
    muteBtn.title = muted ? 'Unmute notifications' : 'Mute notifications';
  });

  const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    themeBtn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    themeBtn.textContent = theme === 'dark' ? '☀' : '☾';
    localStorage.setItem(THEME_KEY, theme);
  };

  themeBtn.addEventListener('click', () => {
    const next = document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark';
    applyTheme(next);
  });

  const preferredTheme =
    localStorage.getItem(THEME_KEY) ||
    (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
  applyTheme(preferredTheme);
  muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
  muteBtn.textContent = muted ? '🔇' : '🔊';

  // Do NOT leave on pagehide — mobile app switching would kick the user out.
  window.addEventListener('beforeunload', () => {
    saveSession();
  });

  resizeComposer();
  updateEmpty();

  const existing = loadSession();
  if (existing?.token && existing?.name) {
    bootstrapSession(existing);
  } else {
    nameInput.focus();
  }
})();
