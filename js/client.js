(() => {
  const API_BASE = (window.CHAT_API_BASE_URL || '').replace(/\/$/, '');
  const AUTH_KEY = 'vchat.auth.v1';
  const SESSION_KEY = 'vchat.session.v3';
  const THEME_KEY = 'vchat.theme';
  const MUTE_KEY = 'vchat.muted';
  const MAX_CHARS = 2000;

  const authPanel = document.getElementById('authPanel');
  const homePanel = document.getElementById('homePanel');
  const chatPanel = document.getElementById('chatPanel');
  const authForm = document.getElementById('authForm');
  const authTitle = document.getElementById('authTitle');
  const authLead = document.getElementById('authLead');
  const authError = document.getElementById('authError');
  const authBtn = document.getElementById('authBtn');
  const tabLogin = document.getElementById('tabLogin');
  const tabSignup = document.getElementById('tabSignup');
  const displayNameField = document.getElementById('displayNameField');
  const displayNameInput = document.getElementById('displayNameInput');
  const emailInput = document.getElementById('emailInput');
  const passwordInput = document.getElementById('passwordInput');
  const homeUserName = document.getElementById('homeUserName');
  const homeUserId = document.getElementById('homeUserId');
  const createRoomForm = document.getElementById('createRoomForm');
  const roomNameInput = document.getElementById('roomNameInput');
  const createRoomError = document.getElementById('createRoomError');
  const createRoomBtn = document.getElementById('createRoomBtn');
  const openRoomForm = document.getElementById('openRoomForm');
  const roomCodeInput = document.getElementById('roomCodeInput');
  const openRoomError = document.getElementById('openRoomError');
  const roomList = document.getElementById('roomList');
  const roomListEmpty = document.getElementById('roomListEmpty');
  const form = document.getElementById('send-container');
  const messageInput = document.getElementById('messageInp');
  const sendBtn = document.getElementById('sendBtn');
  const charCount = document.getElementById('charCount');
  const messageContainer = document.getElementById('messageContainer');
  const emptyState = document.getElementById('emptyState');
  const jumpBtn = document.getElementById('jumpBtn');
  const leaveBtn = document.getElementById('leaveBtn');
  const copyLinkBtn = document.getElementById('copyLinkBtn');
  const logoutBtn = document.getElementById('logoutBtn');
  const connectionStatus = document.getElementById('connectionStatus');
  const userBadge = document.getElementById('userBadge');
  const userBadgeMobile = document.getElementById('userBadgeMobile');
  const presenceLabel = document.getElementById('presenceLabel');
  const chatRoomTitle = document.getElementById('chatRoomTitle');
  const reconnectBanner = document.getElementById('reconnectBanner');
  const reconnectText = document.getElementById('reconnectText');
  const retryBtn = document.getElementById('retryBtn');
  const muteBtn = document.getElementById('muteBtn');
  const themeBtn = document.getElementById('themeBtn');

  let authMode = 'login';
  let authToken = null;
  let currentUser = null;
  let chatToken = null;
  let since = 0;
  let myName = '';
  let myPublicId = '';
  let currentRoom = null;
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

  const roomFromUrl = () => {
    const params = new URLSearchParams(window.location.search);
    return (params.get('room') || '').trim().toUpperCase();
  };

  const setRoomInUrl = (code) => {
    const url = new URL(window.location.href);
    if (code) {
      url.searchParams.set('room', code);
    } else {
      url.searchParams.delete('room');
    }
    window.history.replaceState({}, '', url);
  };

  const roomLink = (code) => {
    const url = new URL(window.location.href);
    url.search = '';
    url.hash = '';
    url.searchParams.set('room', code);
    return url.toString();
  };

  const showToast = (text) => {
    const el = document.createElement('div');
    el.className = 'toast';
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 1800);
  };

  const saveAuth = () => {
    if (!authToken || !currentUser) {
      localStorage.removeItem(AUTH_KEY);
      return;
    }
    localStorage.setItem(AUTH_KEY, JSON.stringify({ authToken, user: currentUser }));
  };

  const loadAuth = () => {
    try {
      return JSON.parse(localStorage.getItem(AUTH_KEY) || 'null');
    } catch (_) {
      return null;
    }
  };

  const saveChatSession = () => {
    if (!chatToken || !myName || !currentRoom) {
      sessionStorage.removeItem(SESSION_KEY);
      return;
    }
    sessionStorage.setItem(
      SESSION_KEY,
      JSON.stringify({
        token: chatToken,
        name: myName,
        publicId: myPublicId,
        since,
        room: currentRoom,
      })
    );
  };

  const loadChatSession = () => {
    try {
      return JSON.parse(sessionStorage.getItem(SESSION_KEY) || 'null');
    } catch (_) {
      return null;
    }
  };

  const clearChatSession = () => sessionStorage.removeItem(SESSION_KEY);

  const setStatus = (state, label) => {
    connectionStatus.dataset.state = state;
    connectionStatus.querySelector('.status__label').textContent = label;
  };

  const showReconnect = (show, text = 'Reconnecting…') => {
    reconnectBanner.hidden = !show;
    reconnectText.textContent = text;
  };

  const showAuthError = (message) => {
    authError.hidden = !message;
    authError.textContent = message || '';
  };

  const setAuthMode = (mode) => {
    authMode = mode;
    const isSignup = mode === 'signup';
    tabLogin.classList.toggle('is-active', !isSignup);
    tabSignup.classList.toggle('is-active', isSignup);
    displayNameField.hidden = !isSignup;
    authTitle.textContent = isSignup ? 'Create account' : 'Sign in';
    authLead.textContent = isSignup
      ? 'Get a unique user ID and start creating shareable chat rooms.'
      : 'Sign in to create rooms and chat with a unique user ID.';
    authBtn.textContent = isSignup ? 'Sign up' : 'Log in';
    passwordInput.autocomplete = isSignup ? 'new-password' : 'current-password';
    showAuthError('');
  };

  const showPanel = (panel) => {
    authPanel.hidden = panel !== 'auth';
    homePanel.hidden = panel !== 'home';
    chatPanel.hidden = panel !== 'chat';
    logoutBtn.hidden = panel === 'auth';
  };

  const api = async (path, options = {}) => {
    const response = await fetch(`${API_BASE}${path}`, {
      ...options,
      headers: {
        Accept: 'application/json',
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        ...(authToken ? { 'X-Auth-Token': authToken } : {}),
        ...(chatToken ? { 'X-Chat-Token': chatToken } : {}),
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

  const avatarColor = (name) => {
    let hash = 0;
    for (let i = 0; i < name.length; i += 1) hash = name.charCodeAt(i) + ((hash << 5) - hash);
    return `hsl(${Math.abs(hash) % 360} 58% 42%)`;
  };

  const initials = (name) =>
    name
      .split(/\s+/)
      .slice(0, 2)
      .map((part) => part[0] || '')
      .join('')
      .toUpperCase() || '?';

  const formatTime = (iso) => {
    if (!iso) return '';
    const date = new Date(String(iso).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  };

  const dayKey = (iso) => {
    if (!iso) return '';
    const date = new Date(String(iso).replace(' ', 'T'));
    return Number.isNaN(date.getTime()) ? '' : date.toDateString();
  };

  const dayLabel = (iso) => {
    const key = dayKey(iso);
    if (!key) return '';
    const date = new Date(key);
    const today = new Date();
    const yesterday = new Date();
    yesterday.setDate(today.getDate() - 1);
    if (date.toDateString() === today.toDateString()) return 'Today';
    if (date.toDateString() === yesterday.toDateString()) return 'Yesterday';
    return date.toLocaleDateString([], { month: 'short', day: 'numeric', year: 'numeric' });
  };

  const linkify = (text) => {
    const frag = document.createDocumentFragment();
    const regex = /(https?:\/\/[^\s<]+)/g;
    let last = 0;
    let match = regex.exec(text);
    while (match) {
      if (match.index > last) frag.appendChild(document.createTextNode(text.slice(last, match.index)));
      const a = document.createElement('a');
      a.href = match[1];
      a.target = '_blank';
      a.rel = 'noopener noreferrer';
      a.textContent = match[1];
      frag.appendChild(a);
      last = match.index + match[1].length;
      match = regex.exec(text);
    }
    if (last < text.length) frag.appendChild(document.createTextNode(text.slice(last)));
    return frag;
  };

  const updateEmpty = () => {
    emptyState.hidden = Boolean(messageContainer.querySelector('.message-row, .day-divider'));
  };

  const updateUnread = () => {
    document.title = unread > 0 ? `(${unread}) ${baseTitle}` : baseTitle;
  };

  const setComposerEnabled = (enabled) => {
    messageInput.disabled = !enabled;
    sendBtn.disabled = !enabled || !messageInput.value.trim();
    if (enabled) messageInput.focus();
  };

  const resizeComposer = () => {
    const maxHeight = 128;
    messageInput.classList.remove('is-scrollable');
    messageInput.style.height = 'auto';
    const nextHeight = Math.min(Math.max(messageInput.scrollHeight, 44), maxHeight);
    messageInput.style.height = `${nextHeight}px`;
    messageInput.classList.toggle('is-scrollable', messageInput.scrollHeight > maxHeight);
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
    if (!key || key === lastDayKey) return;
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
    if (id && seenIds.has(id)) return null;
    if (id) seenIds.add(id);

    ensureDayDivider(createdAt);
    const grouped = side !== 'system' && name === lastMessageSender && side === lastMessageSide;
    const row = document.createElement('div');
    row.className = `message-row ${side}${grouped ? ' grouped' : ''}`;
    if (localId) row.dataset.localId = localId;
    if (id) row.dataset.eventId = String(id);

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
    if (side === 'system') bodyEl.textContent = body;
    else bodyEl.appendChild(linkify(body));
    bubble.appendChild(bodyEl);
    row.appendChild(bubble);
    messageContainer.appendChild(row);

    lastMessageSender = side === 'system' ? '' : name;
    lastMessageSide = side;
    updateEmpty();

    if (stickToBottom || side === 'right') scrollToBottom(true);
    else {
      jumpBtn.hidden = false;
      unread += 1;
      updateUnread();
    }
    return row;
  };

  const appendSystem = (body, createdAt = null) => appendMessage({ body, side: 'system', createdAt });

  const clearMessages = () => {
    messageContainer.querySelectorAll('.message-row, .day-divider').forEach((node) => node.remove());
    seenIds = new Set();
    lastDayKey = '';
    lastMessageSender = '';
    lastMessageSide = '';
    updateEmpty();
  };

  const handleEvent = (event, { fromHistory = false } = {}) => {
    if (!event) return;
    if (event.type === 'join') {
      if (event.name !== myName) appendSystem(`${event.name} joined the chat`, event.createdAt);
      return;
    }
    if (event.type === 'leave') {
      appendSystem(`${event.name} left the chat`, event.createdAt);
      return;
    }
    if (event.type === 'message') {
      const mine = event.name === myName;
      if (mine && !fromHistory) {
        if (event.id) seenIds.add(event.id);
        return;
      }
      appendMessage({
        id: event.id,
        body: event.message || '',
        side: mine ? 'right' : 'left',
        name: event.name,
        createdAt: event.createdAt,
      });
      if (!mine && !fromHistory && !muted && !document.hidden) {
        try {
          new Audio('public/noti.wav').play().catch(() => {});
        } catch (_) {}
      }
    }
  };

  const handleEvents = (events = []) => {
    for (const event of events) {
      handleEvent(event);
      if (typeof event.id === 'number') since = Math.max(since, event.id);
    }
    saveChatSession();
  };

  const renderHistory = (history) => {
    clearMessages();
    for (const event of history || []) handleEvent(event, { fromHistory: true });
    updateEmpty();
    scrollToBottom(true);
  };

  const updatePresence = (online = []) => {
    const others = online.filter((name) => name !== myName);
    presenceLabel.textContent =
      others.length === 0
        ? 'Only you online'
        : others.length <= 3
          ? `Online: ${others.join(', ')}`
          : `Online: ${others.slice(0, 3).join(', ')} +${others.length - 3}`;
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
    if (!chatToken) return;
    reconnectAttempt += 1;
    const delay = Math.min(1000 * 2 ** Math.min(reconnectAttempt, 5), 20000);
    setStatus('reconnecting', 'Reconnecting');
    showReconnect(true, `Reconnecting in ${Math.round(delay / 1000)}s…`);
    reconnectTimer = setTimeout(() => startLiveConnection(), delay);
  };

  const readSse = async (response, signal) => {
    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    while (!signal.aborted) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      const chunks = buffer.split('\n\n');
      buffer = chunks.pop() || '';
      for (const chunk of chunks) {
        const lines = chunk.split('\n');
        let eventName = 'message';
        let dataLine = '';
        for (const line of lines) {
          if (line.startsWith('event:')) eventName = line.slice(6).trim();
          else if (line.startsWith('data:')) dataLine += line.slice(5).trim();
        }
        if (!dataLine) continue;
        let payload = {};
        try {
          payload = JSON.parse(dataLine);
        } catch (_) {
          continue;
        }
        if (eventName === 'error') throw new Error(payload.message || 'Stream error');
        if (payload.events) handleEvents(payload.events);
        if (typeof payload.since === 'number') {
          since = Math.max(since, payload.since);
          saveChatSession();
        }
        if (payload.online) updatePresence(payload.online);
        if (eventName === 'ready' || eventName === 'ping' || eventName === 'events') {
          reconnectAttempt = 0;
          setStatus('online', 'Online');
          showReconnect(false);
        }
      }
    }
  };

  const startLiveConnection = async () => {
    if (!chatToken) return;
    stopStream();
    streamAbort = new AbortController();
    const { signal } = streamAbort;
    setStatus('connecting', 'Connecting');
    try {
      const response = await fetch(`${API_BASE}/stream?since=${since}`, {
        headers: { Accept: 'text/event-stream', 'X-Chat-Token': chatToken },
        signal,
      });
      if (!response.ok || !response.body) throw new Error(`SSE failed (${response.status})`);
      await readSse(response, signal);
      if (!signal.aborted && chatToken) scheduleReconnect();
    } catch (error) {
      if (signal.aborted) return;
      try {
        while (!signal.aborted && chatToken) {
          const data = await api(`/poll?since=${since}&wait=1`, { signal });
          if (Array.isArray(data.events) && data.events.length) handleEvents(data.events);
          if (typeof data.since === 'number') {
            since = Math.max(since, data.since);
            saveChatSession();
          }
          if (data.online) updatePresence(data.online);
          reconnectAttempt = 0;
          setStatus('online', 'Online');
          showReconnect(false);
        }
      } catch (pollError) {
        if (signal.aborted) return;
        if (pollError.status === 401) {
          appendSystem('Session expired. Please join again.');
          leaveToHome();
          return;
        }
        scheduleReconnect();
      }
    }
  };

  const enterChat = (room) => {
    currentRoom = room;
    chatRoomTitle.textContent = room?.name || 'Group chat';
    userBadge.hidden = false;
    userBadge.textContent = myName;
    userBadgeMobile.hidden = false;
    userBadgeMobile.textContent = myName;
    showPanel('chat');
    setComposerEnabled(true);
    setStatus('online', 'Online');
    setRoomInUrl(room?.code || '');
    baseTitle = room?.name ? `${room.name} · v-chat` : 'v-chat';
    updateUnread();
  };

  const leaveToHome = async () => {
    stopStream();
    if (chatToken) {
      try {
        await api('/leave', { method: 'POST', body: JSON.stringify({}) });
      } catch (_) {}
    }
    chatToken = null;
    since = 0;
    currentRoom = null;
    clearChatSession();
    clearMessages();
    unread = 0;
    updateUnread();
    setComposerEnabled(false);
    setStatus('idle', 'Offline');
    showReconnect(false);
    setRoomInUrl('');
    baseTitle = 'v-chat';
    showHome();
  };

  const renderRoomList = (rooms) => {
    roomList.replaceChildren();
    roomListEmpty.hidden = rooms.length > 0;
    for (const room of rooms) {
      const li = document.createElement('li');
      li.className = 'room-list__item';
      li.innerHTML = `
        <div class="room-list__meta">
          <p class="room-list__name"></p>
          <p class="room-list__code"></p>
        </div>
        <div class="room-list__actions">
          <button class="btn btn--outline btn--small" type="button" data-action="open">Open</button>
          <button class="btn btn--outline btn--small" type="button" data-action="copy">Copy link</button>
        </div>`;
      li.querySelector('.room-list__name').textContent = room.name;
      li.querySelector('.room-list__code').textContent = `Code ${room.code}`;
      li.querySelector('[data-action="open"]').addEventListener('click', () => joinRoom(room.code));
      li.querySelector('[data-action="copy"]').addEventListener('click', async () => {
        await navigator.clipboard.writeText(roomLink(room.code));
        showToast('Room link copied');
      });
      roomList.appendChild(li);
    }
  };

  const refreshRooms = async () => {
    const data = await api('/rooms');
    renderRoomList(data.rooms || []);
  };

  const showHome = async () => {
    showPanel('home');
    homeUserName.textContent = currentUser?.displayName || '';
    homeUserId.textContent = currentUser?.publicId || '';
    userBadge.hidden = false;
    userBadge.textContent = currentUser?.displayName || '';
    try {
      await refreshRooms();
    } catch (_) {
      renderRoomList([]);
    }
    const pendingRoom = roomFromUrl();
    if (pendingRoom) {
      roomCodeInput.value = pendingRoom;
    }
  };

  const joinRoom = async (code) => {
    const roomCode = String(code || '').trim().toUpperCase();
    if (!roomCode) return;
    setStatus('connecting', 'Connecting');
    try {
      const data = await api('/join', {
        method: 'POST',
        body: JSON.stringify({ roomCode }),
      });
      chatToken = data.token;
      myName = data.name;
      myPublicId = data.publicId || currentUser?.publicId || '';
      since = data.since || 0;
      enterChat(data.room);
      renderHistory(data.history || []);
      updatePresence(data.online || []);
      appendSystem(`You joined ${data.room?.name || 'the room'}`);
      saveChatSession();
      startLiveConnection();
    } catch (error) {
      setStatus('error', 'Offline');
      openRoomError.hidden = false;
      openRoomError.textContent = error.message || 'Unable to join room.';
      showPanel('home');
    }
  };

  const markLocalState = (localId, { pending, failed, eventId }) => {
    const row = messageContainer.querySelector(`[data-local-id="${localId}"]`);
    if (!row) return;
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
    } else if (retry) retry.remove();
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
            saveChatSession();
          }
        } catch (error) {
          markLocalState(localId, { pending: false, failed: true });
          if (error.status === 429) appendSystem(error.message);
        }
      })
      .catch(() => {});
  };

  const bootstrapAuth = async (saved) => {
    authToken = saved.authToken;
    currentUser = saved.user;
    try {
      const data = await api('/me');
      currentUser = data.user;
      saveAuth();
      const chatSession = loadChatSession();
      if (chatSession?.token && chatSession?.room?.code) {
        chatToken = chatSession.token;
        myName = chatSession.name;
        myPublicId = chatSession.publicId || currentUser.publicId;
        since = chatSession.since || 0;
        try {
          const resumed = await api('/resume');
          myName = resumed.name || myName;
          since = resumed.since || since;
          enterChat(resumed.room || chatSession.room);
          renderHistory(resumed.history || []);
          updatePresence(resumed.online || []);
          saveChatSession();
          startLiveConnection();
          return;
        } catch (_) {
          clearChatSession();
          chatToken = null;
        }
      }
      await showHome();
      if (roomFromUrl()) await joinRoom(roomFromUrl());
    } catch (_) {
      authToken = null;
      currentUser = null;
      localStorage.removeItem(AUTH_KEY);
      showPanel('auth');
    }
  };

  tabLogin.addEventListener('click', () => setAuthMode('login'));
  tabSignup.addEventListener('click', () => setAuthMode('signup'));

  authForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    showAuthError('');
    const email = emailInput.value.trim();
    const password = passwordInput.value;
    const displayName = displayNameInput.value.trim();
    authBtn.disabled = true;
    try {
      const path = authMode === 'signup' ? '/signup' : '/login';
      const body =
        authMode === 'signup'
          ? { email, password, displayName }
          : { email, password };
      const data = await api(path, { method: 'POST', body: JSON.stringify(body) });
      authToken = data.authToken;
      currentUser = data.user;
      saveAuth();
      await showHome();
      if (roomFromUrl()) await joinRoom(roomFromUrl());
    } catch (error) {
      const errors = error.payload?.errors || {};
      showAuthError(errors.email || errors.password || errors.displayName || error.message);
    } finally {
      authBtn.disabled = false;
    }
  });

  createRoomForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    createRoomError.hidden = true;
    createRoomBtn.disabled = true;
    try {
      const data = await api('/rooms', {
        method: 'POST',
        body: JSON.stringify({ name: roomNameInput.value.trim() }),
      });
      roomNameInput.value = '';
      await refreshRooms();
      await navigator.clipboard.writeText(roomLink(data.room.code)).catch(() => {});
      showToast('Room created — link copied');
      await joinRoom(data.room.code);
    } catch (error) {
      createRoomError.hidden = false;
      createRoomError.textContent = error.payload?.errors?.name || error.message;
    } finally {
      createRoomBtn.disabled = false;
    }
  });

  openRoomForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    openRoomError.hidden = true;
    await joinRoom(roomCodeInput.value);
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    if (!chatToken || messageInput.disabled) return;
    const message = messageInput.value.trim();
    if (!message) return;
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
  leaveBtn.addEventListener('click', () => leaveToHome());
  copyLinkBtn.addEventListener('click', async () => {
    if (!currentRoom?.code) return;
    await navigator.clipboard.writeText(roomLink(currentRoom.code));
    showToast('Room link copied');
  });
  retryBtn.addEventListener('click', () => {
    reconnectAttempt = 0;
    showReconnect(true, 'Reconnecting…');
    startLiveConnection();
  });

  logoutBtn.addEventListener('click', async () => {
    stopStream();
    try {
      if (chatToken) await api('/leave', { method: 'POST', body: JSON.stringify({}) });
    } catch (_) {}
    try {
      await api('/logout', { method: 'POST', body: JSON.stringify({}) });
    } catch (_) {}
    authToken = null;
    currentUser = null;
    chatToken = null;
    currentRoom = null;
    localStorage.removeItem(AUTH_KEY);
    clearChatSession();
    clearMessages();
    setRoomInUrl('');
    showPanel('auth');
    setStatus('idle', 'Offline');
    userBadge.hidden = true;
  });

  muteBtn.addEventListener('click', () => {
    muted = !muted;
    localStorage.setItem(MUTE_KEY, muted ? '1' : '0');
    muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
    muteBtn.textContent = muted ? '🔇' : '🔊';
  });

  const applyTheme = (theme) => {
    document.documentElement.dataset.theme = theme;
    themeBtn.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
    themeBtn.textContent = theme === 'dark' ? '☀' : '☾';
    localStorage.setItem(THEME_KEY, theme);
  };
  themeBtn.addEventListener('click', () => {
    applyTheme(document.documentElement.dataset.theme === 'dark' ? 'light' : 'dark');
  });

  applyTheme(
    localStorage.getItem(THEME_KEY) ||
      (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
  );
  muteBtn.setAttribute('aria-pressed', muted ? 'true' : 'false');
  muteBtn.textContent = muted ? '🔇' : '🔊';
  setAuthMode('login');
  resizeComposer();

  window.addEventListener('beforeunload', () => saveChatSession());

  const savedAuth = loadAuth();
  if (savedAuth?.authToken && savedAuth?.user) bootstrapAuth(savedAuth);
  else showPanel('auth');
})();
