<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();

$pageTitle = 'Live Chat Console';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<style>
.livechat-app {
  display: grid;
  grid-template-columns: 320px 1fr 280px;
  gap: 16px;
  height: calc(100vh - 120px);
  min-height: 580px;
  margin-top: 10px;
}
@media (max-width: 1200px) {
  .livechat-app {
    grid-template-columns: 300px 1fr;
  }
  .livechat-sidebar-right {
    display: none;
  }
}
@media (max-width: 800px) {
  .livechat-app {
    grid-template-columns: 1fr;
    height: auto;
  }
  .livechat-pane-left {
    height: 380px;
  }
  .livechat-pane-center {
    height: 520px;
  }
}

/* PANELS */
.livechat-pane {
  background: var(--card, #141f2d);
  border: 1px solid var(--border, #1e2c3f);
  border-radius: 12px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

/* THREADS LIST PANE */
.livechat-pane-left {
  border-right: 1px solid var(--border);
}
.livechat-list-header {
  padding: 14px;
  border-bottom: 1px solid var(--border);
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.livechat-search-wrap {
  position: relative;
}
.livechat-search-input {
  width: 100%;
  padding: 8px 12px;
  background: var(--bg, #0b1118);
  border: 1px solid var(--border);
  border-radius: 6px;
  color: var(--text, #f1f5f9);
  font-size: 13px;
  box-sizing: border-box;
}
.livechat-filters {
  display: flex;
  gap: 6px;
  overflow-x: auto;
  padding-bottom: 2px;
}
.livechat-filter-btn {
  background: transparent;
  border: 1px solid var(--border);
  color: var(--muted, #94a3b8);
  padding: 4px 10px;
  border-radius: 14px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.2s ease;
}
.livechat-filter-btn.active {
  background: var(--accent, #3b82f6);
  color: #fff;
  border-color: var(--accent, #3b82f6);
}
.livechat-threads-list {
  flex: 1;
  overflow-y: auto;
  list-style: none;
  margin: 0;
  padding: 0;
}
.livechat-thread-item {
  padding: 12px 14px;
  border-bottom: 1px solid rgba(255,255,255,0.04);
  cursor: pointer;
  display: flex;
  gap: 12px;
  align-items: flex-start;
  transition: background 0.15s ease;
  position: relative;
}
.livechat-thread-item:hover {
  background: rgba(255,255,255,0.02);
}
.livechat-thread-item.active {
  background: rgba(59, 130, 246, 0.08);
  border-left: 3px solid var(--accent, #3b82f6);
}
.thread-avatar {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #1e293b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 700;
  font-size: 13px;
  color: #38bdf8;
  flex-shrink: 0;
  position: relative;
}
.thread-avatar .online-indicator {
  width: 9px;
  height: 9px;
  background: #10b981;
  border: 2px solid var(--card);
  border-radius: 50%;
  position: absolute;
  bottom: -1px;
  right: -1px;
  display: none;
}
.thread-avatar .online-indicator.visible {
  display: block;
}
.thread-info {
  flex: 1;
  min-width: 0;
}
.thread-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 3px;
}
.thread-name {
  font-size: 13px;
  font-weight: 600;
  color: var(--text, #f1f5f9);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.thread-time {
  font-size: 11px;
  color: var(--muted, #94a3b8);
  flex-shrink: 0;
}
.thread-snippet {
  font-size: 12px;
  color: var(--muted, #94a3b8);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 4px;
}
.thread-badges {
  display: flex;
  gap: 6px;
  align-items: center;
}
.badge-typing {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: rgba(16, 185, 129, 0.15);
  color: #10b981;
  border: 1px solid rgba(16, 185, 129, 0.3);
  font-size: 10px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 10px;
  animation: pulseTyping 1.5s infinite;
}
@keyframes pulseTyping {
  0% { opacity: 0.6; }
  50% { opacity: 1; }
  100% { opacity: 0.6; }
}

/* CENTER CHAT PANE */
.livechat-pane-center {
  display: flex;
  flex-direction: column;
}
.livechat-header {
  padding: 12px 18px;
  border-bottom: 1px solid var(--border);
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: rgba(255,255,255,0.01);
}
.livechat-header-user {
  display: flex;
  align-items: center;
  gap: 10px;
}
.livechat-header-actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
.livechat-messages-area {
  flex: 1;
  overflow-y: auto;
  padding: 18px;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.msg-bubble {
  max-width: 78%;
  padding: 10px 14px;
  border-radius: 12px;
  font-size: 13.5px;
  line-height: 1.5;
  position: relative;
  word-break: break-word;
}
.msg-user {
  align-self: flex-start;
  background: #1e293b;
  border: 1px solid var(--border);
  color: #f1f5f9;
  border-bottom-left-radius: 2px;
}
.msg-admin {
  align-self: flex-end;
  background: #2563eb;
  color: #ffffff;
  border-bottom-right-radius: 2px;
}
.msg-bot {
  align-self: flex-start;
  background: #1e1b4b;
  border: 1px solid #4338ca;
  color: #e0e7ff;
  border-bottom-left-radius: 2px;
}
.msg-header-tag {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  margin-bottom: 4px;
  display: flex;
  align-items: center;
  gap: 4px;
}
.msg-time {
  font-size: 10.5px;
  opacity: 0.7;
  margin-top: 4px;
  text-align: right;
}

/* TYPING BANNER IN STREAM */
.livechat-typing-bar {
  padding: 6px 18px;
  font-size: 12px;
  color: #10b981;
  font-weight: 600;
  background: rgba(16, 185, 129, 0.06);
  border-top: 1px solid rgba(16, 185, 129, 0.15);
  display: none;
  align-items: center;
  gap: 8px;
}
.typing-dots {
  display: inline-flex;
  gap: 3px;
}
.typing-dots span {
  width: 5px;
  height: 5px;
  background: #10b981;
  border-radius: 50%;
  animation: bounceDot 1.4s infinite ease-in-out both;
}
.typing-dots span:nth-child(1) { animation-delay: -0.32s; }
.typing-dots span:nth-child(2) { animation-delay: -0.16s; }
@keyframes bounceDot {
  0%, 80%, 100% { transform: scale(0); }
  40% { transform: scale(1); }
}

/* INPUT BAR */
.livechat-input-bar {
  padding: 12px 16px;
  border-top: 1px solid var(--border);
  background: rgba(255,255,255,0.015);
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.canned-dropdown-wrap {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.canned-select {
  background: var(--bg);
  border: 1px solid var(--border);
  color: var(--muted);
  font-size: 12px;
  padding: 4px 8px;
  border-radius: 6px;
  outline: none;
  cursor: pointer;
  max-width: 250px;
}
.livechat-textarea-wrap {
  display: flex;
  gap: 10px;
  align-items: flex-end;
}
.livechat-textarea {
  flex: 1;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 8px;
  color: var(--text);
  font-size: 13.5px;
  padding: 10px 12px;
  min-height: 44px;
  max-height: 120px;
  resize: none;
  outline: none;
  box-sizing: border-box;
}
.livechat-textarea:focus {
  border-color: var(--accent);
}
.livechat-send-btn {
  background: var(--accent, #3b82f6);
  border: none;
  border-radius: 8px;
  color: #fff;
  padding: 10px 18px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: opacity 0.2s ease;
  height: 44px;
}
.livechat-send-btn:hover {
  opacity: 0.9;
}

/* RIGHT USER SIDEBAR */
.livechat-sidebar-right {
  padding: 18px;
  overflow-y: auto;
}
.user-card-heading {
  font-size: 11px;
  text-transform: uppercase;
  color: var(--muted);
  font-weight: 700;
  letter-spacing: 0.05em;
  margin-bottom: 12px;
}
.user-prop-row {
  margin-bottom: 12px;
}
.user-prop-label {
  font-size: 11px;
  color: var(--muted);
  margin-bottom: 2px;
}
.user-prop-val {
  font-size: 13px;
  font-weight: 600;
  color: var(--text);
}
</style>

<div class="container" style="max-width: 1440px;">
  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
    <div>
      <h1 style="margin-bottom:2px; font-size:22px;">Live Chat Command Center</h1>
      <p class="subtitle" style="margin:0;">Real-time visitor and user assistance with bot monitoring &amp; typing detection.</p>
    </div>
    <div style="display:flex; gap:10px; align-items:center;">
      <button id="soundToggleBtn" class="btn small secondary" type="button" onclick="toggleChatSound()">
        🔊 Sound: <span id="soundStatusText">ON</span>
      </button>
      <a href="/admin/support.php" class="btn small secondary">Support Archive</a>
    </div>
  </div>

  <div class="livechat-app">
    <!-- LEFT PANE: THREAD LIST -->
    <div class="livechat-pane livechat-pane-left">
      <div class="livechat-list-header">
        <div class="livechat-search-wrap">
          <input type="text" id="threadSearch" class="livechat-search-input" placeholder="Filter conversations..." oninput="filterThreads()">
        </div>
        <div class="livechat-filters">
          <button class="livechat-filter-btn active" data-filter="all" onclick="setThreadFilter('all')">All</button>
          <button class="livechat-filter-btn" data-filter="human" onclick="setThreadFilter('human')">🚨 Needs Human</button>
          <button class="livechat-filter-btn" data-filter="bot" onclick="setThreadFilter('bot')">🤖 Bot Active</button>
          <button class="livechat-filter-btn" data-filter="closed" onclick="setThreadFilter('closed')">Closed</button>
        </div>
      </div>
      <ul class="livechat-threads-list" id="threadsListEl">
        <li style="padding:20px; text-align:center; color:var(--muted); font-size:13px;">Loading conversations...</li>
      </ul>
    </div>

    <!-- CENTER PANE: ACTIVE CONVERSATION -->
    <div class="livechat-pane livechat-pane-center" id="chatCenterPane">
      <!-- HEADER -->
      <div class="livechat-header">
        <div class="livechat-header-user">
          <div class="thread-avatar" id="activeUserAvatar">?</div>
          <div>
            <div style="font-weight:700; font-size:14px;" id="activeUserName">Select a conversation</div>
            <div style="font-size:11px; color:var(--muted);" id="activeUserEmail">No conversation active</div>
          </div>
        </div>
        <div class="livechat-header-actions" id="chatActionsBar" style="display:none;">
          <button id="botToggleBtn" class="btn small secondary" type="button" onclick="toggleBot()">
            🤖 Bot Active
          </button>
          <button id="statusToggleBtn" class="btn small secondary" type="button" onclick="toggleThreadStatus()">
            Close Chat
          </button>
        </div>
      </div>

      <!-- MESSAGES FEED -->
      <div class="livechat-messages-area" id="messagesAreaEl">
        <div style="margin:auto; text-align:center; color:var(--muted); font-size:13px;">
          Select a chat on the left to start live messaging.
        </div>
      </div>

      <!-- LIVE TYPING NOTIFICATION BAR -->
      <div class="livechat-typing-bar" id="typingBarEl">
        <div class="typing-dots"><span></span><span></span><span></span></div>
        <span id="typingTextEl">User is typing...</span>
      </div>

      <!-- INPUT BAR -->
      <div class="livechat-input-bar" id="chatInputBar" style="display:none;">
        <div class="canned-dropdown-wrap">
          <div style="font-size:11px; color:var(--muted); font-weight:600;">Quick Responses:</div>
          <select class="canned-select" id="cannedSelectEl" onchange="insertCannedResponse(this.value)">
            <option value="">-- Choose canned response --</option>
          </select>
        </div>
        <div class="livechat-textarea-wrap">
          <textarea id="replyTextarea" class="livechat-textarea" placeholder="Type a message to the user... (Enter to send, Shift+Enter for newline)" rows="1" onkeydown="handleKeypress(event)" oninput="handleTypingHeartbeat()"></textarea>
          <button class="livechat-send-btn" type="button" onclick="sendAdminReply()">Send</button>
        </div>
      </div>
    </div>

    <!-- RIGHT PANE: USER PROFILE INTELLIGENCE -->
    <div class="livechat-pane livechat-sidebar-right" id="userSidebarEl">
      <div class="user-card-heading">Visitor Intelligence</div>
      <div id="userInfoContent">
        <p style="font-size:12px; color:var(--muted);">Select a conversation to inspect the user account.</p>
      </div>
    </div>
  </div>
</div>

<script>
let currentThreadId = null;
let currentBotActive = true;
let currentStatus = 'open';
let lastMessageId = 0;
let soundEnabled = true;
let pollInterval = null;
let threadsPollInterval = null;
let lastTypingSent = 0;
let threadsCache = [];
let currentFilter = 'all';

// Web Audio API for Chimes
let audioCtx = null;
function playNotificationChime() {
  if (!soundEnabled) return;
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const now = audioCtx.currentTime;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, now); // D5
    osc.frequency.setValueAtTime(880.00, now + 0.12); // A5
    gain.gain.setValueAtTime(0.08, now);
    gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.35);
    osc.connect(gain);
    gain.connect(audioCtx.destination);
    osc.start(now);
    osc.stop(now + 0.36);
  } catch (e) {}
}

function toggleChatSound() {
  soundEnabled = !soundEnabled;
  document.getElementById('soundStatusText').innerText = soundEnabled ? 'ON' : 'OFF';
  document.getElementById('soundToggleBtn').classList.toggle('secondary', !soundEnabled);
}

// Load Canned Responses
async function loadCannedResponses() {
  try {
    const res = await fetch('/api/livechat.php?action=canned_responses');
    const data = await res.json();
    if (data.ok && data.canned) {
      const select = document.getElementById('cannedSelectEl');
      select.innerHTML = '<option value="">-- Choose canned response --</option>';
      data.canned.forEach(c => {
        const opt = document.createElement('option');
        opt.value = c.message;
        opt.textContent = c.title;
        select.appendChild(opt);
      });
    }
  } catch (e) {}
}

function insertCannedResponse(text) {
  if (!text) return;
  const textarea = document.getElementById('replyTextarea');
  textarea.value = text;
  textarea.focus();
  document.getElementById('cannedSelectEl').value = '';
}

// Load and Poll Inbox Threads
async function loadAdminThreads() {
  try {
    const res = await fetch('/api/livechat.php?action=admin_inbox');
    const data = await res.json();
    if (data.ok && data.threads) {
      // Detect if unread count increased to play chime
      const prevUnread = threadsCache.reduce((acc, t) => acc + (t.unread || 0), 0);
      const newUnread = data.total_unread;
      if (newUnread > prevUnread && prevUnread !== 0) {
        playNotificationChime();
      }
      threadsCache = data.threads;
      renderThreads();

      // If no thread selected yet, select first thread
      if (!currentThreadId && threadsCache.length > 0) {
        selectThread(threadsCache[0].id);
      }
    }
  } catch (e) {}
}

function setThreadFilter(f) {
  currentFilter = f;
  document.querySelectorAll('.livechat-filter-btn').forEach(b => {
    b.classList.toggle('active', b.getAttribute('data-filter') === f);
  });
  renderThreads();
}

function filterThreads() {
  renderThreads();
}

function renderThreads() {
  const container = document.getElementById('threadsListEl');
  const search = (document.getElementById('threadSearch').value || '').toLowerCase();

  let filtered = threadsCache.filter(t => {
    if (search && !t.name.toLowerCase().includes(search) && !t.email.toLowerCase().includes(search) && !t.subject.toLowerCase().includes(search)) {
      return false;
    }
    if (currentFilter === 'human') return !t.bot_active && t.status === 'open';
    if (currentFilter === 'bot') return t.bot_active && t.status === 'open';
    if (currentFilter === 'closed') return t.status === 'closed';
    return true;
  });

  if (filtered.length === 0) {
    container.innerHTML = '<li style="padding:20px; text-align:center; color:var(--muted); font-size:13px;">No matching chats.</li>';
    return;
  }

  container.innerHTML = filtered.map(t => {
    const initials = (t.name || 'G').split(' ').map(n => n[0]).join('').substring(0, 2).toUpperCase();
    const isActive = t.id === currentThreadId;
    return `
      <li class="livechat-thread-item ${isActive ? 'active' : ''}" onclick="selectThread(${t.id})">
        <div class="thread-avatar">
          ${escapeHtml(initials)}
          <span class="online-indicator ${t.is_online ? 'visible' : ''}"></span>
        </div>
        <div class="thread-info">
          <div class="thread-top">
            <div class="thread-name">${escapeHtml(t.name)}</div>
            <div class="thread-time">${escapeHtml(t.time_ago)}</div>
          </div>
          <div class="thread-snippet">${escapeHtml(t.last_message || 'Conversation started')}</div>
          <div class="thread-badges">
            ${t.is_typing ? '<span class="badge-typing">✍️ Typing...</span>' : ''}
            ${t.unread > 0 ? `<span class="badge blue">${t.unread} new</span>` : ''}
            ${t.bot_active ? '<span class="badge purple" style="font-size:10px;">🤖 Bot</span>' : '<span class="badge green" style="font-size:10px;">👤 Human</span>'}
            ${t.status === 'closed' ? '<span class="badge">Closed</span>' : ''}
          </div>
        </div>
      </li>
    `;
  }).join('');
}

// Select Thread & Load Stream
async function selectThread(id) {
  currentThreadId = id;
  lastMessageId = 0;
  renderThreads();

  document.getElementById('chatActionsBar').style.display = 'flex';
  document.getElementById('chatInputBar').style.display = 'flex';

  try {
    const res = await fetch(`/api/livechat.php?action=init&thread_id=${id}`);
    const data = await res.json();
    if (data.ok && data.thread) {
      currentBotActive = data.thread.bot_active;
      currentStatus = data.thread.status;

      document.getElementById('activeUserName').textContent = data.thread.user_name;
      document.getElementById('activeUserEmail').textContent = (data.user_info ? data.user_info.email : 'Guest Visitor') + (data.thread.user_online ? ' • 🟢 Online' : ' • ⚪ Offline');
      document.getElementById('activeUserAvatar').textContent = data.thread.user_name.substring(0, 2).toUpperCase();

      updateBotBtnUI();
      updateStatusBtnUI();
      renderUserInfo(data.user_info);

      const msgArea = document.getElementById('messagesAreaEl');
      msgArea.innerHTML = '';
      if (data.messages) {
        data.messages.forEach(m => appendMessage(m));
        if (data.messages.length > 0) {
          lastMessageId = data.messages[data.messages.length - 1].id;
        }
      }
      scrollToBottom();
    }
  } catch (e) {}

  // Restart active polling
  if (pollInterval) clearInterval(pollInterval);
  pollInterval = setInterval(pollActiveChat, 1800);
}

function updateBotBtnUI() {
  const btn = document.getElementById('botToggleBtn');
  if (currentBotActive) {
    btn.className = 'btn small';
    btn.style.background = '#4f46e5';
    btn.style.borderColor = '#6366f1';
    btn.innerHTML = '🤖 Bot Managing (Click to Take Over)';
  } else {
    btn.className = 'btn small';
    btn.style.background = '#059669';
    btn.style.borderColor = '#10b981';
    btn.innerHTML = '👤 You Are in Control (Enable Bot)';
  }
}

function updateStatusBtnUI() {
  const btn = document.getElementById('statusToggleBtn');
  if (currentStatus === 'open') {
    btn.textContent = 'Close Chat';
    btn.className = 'btn small secondary';
  } else {
    btn.textContent = 'Reopen Chat';
    btn.className = 'btn small';
  }
}

async function toggleBot() {
  if (!currentThreadId) return;
  const newState = currentBotActive ? 0 : 1;
  const res = await fetch(`/api/livechat.php?action=toggle_bot&thread_id=${currentThreadId}&bot_active=${newState}`, { method: 'POST' });
  const data = await res.json();
  if (data.ok) {
    currentBotActive = data.bot_active;
    updateBotBtnUI();
    loadAdminThreads();
  }
}

async function toggleThreadStatus() {
  if (!currentThreadId) return;
  const act = currentStatus === 'open' ? 'close_thread' : 'reopen_thread';
  const res = await fetch(`/api/livechat.php?action=${act}&thread_id=${currentThreadId}`, { method: 'POST' });
  const data = await res.json();
  if (data.ok) {
    currentStatus = data.status;
    updateStatusBtnUI();
    loadAdminThreads();
  }
}

function renderUserInfo(info) {
  const container = document.getElementById('userInfoContent');
  if (!info) {
    container.innerHTML = `
      <div style="padding:10px 0;">
        <div class="user-prop-row">
          <div class="user-prop-label">Visitor Type</div>
          <div class="user-prop-val">Unregistered Guest</div>
        </div>
        <div class="user-prop-row">
          <div class="user-prop-label">Chat Channel</div>
          <div class="user-prop-val">Public Landing Page Widget</div>
        </div>
        <p class="subtitle" style="font-size:12px; margin-top:14px;">Once this visitor registers an account, their full portfolio and KYC record will link here automatically.</p>
      </div>
    `;
    return;
  }

  container.innerHTML = `
    <div style="padding:4px 0;">
      <div class="user-prop-row">
        <div class="user-prop-label">Full Legal Name</div>
        <div class="user-prop-val">${escapeHtml(info.full_name)}</div>
      </div>
      <div class="user-prop-row">
        <div class="user-prop-label">Email Address</div>
        <div class="user-prop-val">${escapeHtml(info.email)}</div>
      </div>
      <div class="user-prop-row">
        <div class="user-prop-label">Wallet Balance</div>
        <div class="user-prop-val" style="color:#34d399; font-size:15px;">${escapeHtml(info.balance)}</div>
      </div>
      <div class="user-prop-row">
        <div class="user-prop-label">KYC Status</div>
        <div class="user-prop-val"><span class="badge ${info.kyc_status === 'approved' ? 'green' : (info.kyc_status === 'pending' ? 'blue' : '')}">${escapeHtml(info.kyc_status.toUpperCase())}</span></div>
      </div>
      <div class="user-prop-row">
        <div class="user-prop-label">Member Since</div>
        <div class="user-prop-val">${escapeHtml(info.created_at)}</div>
      </div>
      <div style="margin-top:20px; display:flex; flex-direction:column; gap:8px;">
        <a href="/admin/users.php" target="_blank" class="btn small secondary">Manage User in Admin &rarr;</a>
        <a href="/admin/deposits.php" target="_blank" class="btn small secondary">Review Deposits &rarr;</a>
      </div>
    </div>
  `;
}

function appendMessage(m) {
  const msgArea = document.getElementById('messagesAreaEl');
  const bubble = document.createElement('div');
  const isBot = m.is_bot == 1;
  const isUser = m.sender_type === 'user';

  bubble.className = `msg-bubble ${isUser ? 'msg-user' : (isBot ? 'msg-bot' : 'msg-admin')}`;

  let headerTag = '';
  if (isUser) {
    headerTag = `<div class="msg-header-tag" style="color:#94a3b8;">👤 ${escapeHtml(m.sender_label || 'User')}</div>`;
  } else if (isBot) {
    headerTag = `<div class="msg-header-tag" style="color:#c7d2fe;">🤖 NexoraCopy Assistant (AI)</div>`;
  } else {
    headerTag = `<div class="msg-header-tag" style="color:#bfdbfe;">🛡️ Support Specialist (You)</div>`;
  }

  const timeStr = m.created_at ? new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';

  bubble.innerHTML = `
    ${headerTag}
    <div style="white-space:pre-wrap;">${escapeHtml(m.message)}</div>
    <div class="msg-time">${timeStr}</div>
  `;
  msgArea.appendChild(bubble);
}

function scrollToBottom() {
  const msgArea = document.getElementById('messagesAreaEl');
  msgArea.scrollTop = msgArea.scrollHeight;
}

// Active Chat Polling (Typing + Messages)
async function pollActiveChat() {
  if (!currentThreadId) return;
  try {
    const res = await fetch(`/api/livechat.php?action=poll&thread_id=${currentThreadId}&after_id=${lastMessageId}`);
    const data = await res.json();
    if (data.ok) {
      // Update typing indicator banner
      const typingBar = document.getElementById('typingBarEl');
      if (data.is_typing) {
        typingBar.style.display = 'flex';
      } else {
        typingBar.style.display = 'none';
      }

      // Append any new messages
      if (data.messages && data.messages.length > 0) {
        data.messages.forEach(m => {
          appendMessage(m);
          lastMessageId = Math.max(lastMessageId, m.id);
        });
        scrollToBottom();
        playNotificationChime();
      }

      // Check bot active status updates
      if (typeof data.bot_active === 'boolean' && data.bot_active !== currentBotActive) {
        currentBotActive = data.bot_active;
        updateBotBtnUI();
      }
    }
  } catch (e) {}
}

// Send Admin Reply
async function sendAdminReply() {
  const textarea = document.getElementById('replyTextarea');
  const message = textarea.value.trim();
  if (!message || !currentThreadId) return;

  textarea.value = '';
  textarea.style.height = '44px';

  try {
    const fd = new FormData();
    fd.append('action', 'send');
    fd.append('thread_id', currentThreadId);
    fd.append('message', message);

    const res = await fetch('/api/livechat.php', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok && data.sent) {
      appendMessage(data.sent);
      lastMessageId = Math.max(lastMessageId, data.sent.id);
      scrollToBottom();
      currentBotActive = false; // admin reply disables bot
      updateBotBtnUI();
      loadAdminThreads();
    }
  } catch (e) {}
}

function handleKeypress(e) {
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault();
    sendAdminReply();
  }
}

function handleTypingHeartbeat() {
  const now = Date.now();
  if (now - lastTypingSent > 2000 && currentThreadId) {
    lastTypingSent = now;
    fetch(`/api/livechat.php?action=typing&thread_id=${currentThreadId}`, { method: 'POST' });
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

// Start polling loops
loadCannedResponses();
loadAdminThreads();
threadsPollInterval = setInterval(loadAdminThreads, 3500);
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
