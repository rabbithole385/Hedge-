/**
 * Nexora Floating Live Chat Client
 * Lightweight, zero-dependency real-time live chat widget with Bot, Typing Detection, and Audio alerts.
 */
(function() {
  'use strict';

  let currentThreadId = null;
  let lastMessageId = 0;
  let isOpen = false;
  let isSoundEnabled = true;
  let pollInterval = null;
  let lastTypingSent = 0;
  let unreadCount = 0;

  // Web Audio Context for pleasant notification sound
  let audioCtx = null;
  function playSound() {
    if (!isSoundEnabled) return;
    try {
      if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      if (audioCtx.state === 'suspended') audioCtx.resume();
      const now = audioCtx.currentTime;
      const osc = audioCtx.createOscillator();
      const gain = audioCtx.createGain();
      osc.type = 'sine';
      osc.frequency.setValueAtTime(523.25, now); // C5
      osc.frequency.setValueAtTime(659.25, now + 0.1); // E5
      gain.gain.setValueAtTime(0.06, now);
      gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.3);
      osc.connect(gain);
      gain.connect(audioCtx.destination);
      osc.start(now);
      osc.stop(now + 0.32);
    } catch (e) {}
  }

  // Create Widget DOM
  function createWidgetDOM() {
    const root = document.createElement('div');
    root.id = 'hedgeinv-livechat-root';

    root.innerHTML = `
      <!-- Launcher -->
      <div class="hinv-chat-launcher" id="hinvLauncher" title="Chat with support">
        <svg viewBox="0 0 24 24">
          <path d="M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2zm0 14H6l-2 2V4h16v12z"/>
        </svg>
        <span class="hinv-chat-badge" id="hinvBadge" style="display:none;">0</span>
      </div>

      <!-- Window -->
      <div class="hinv-chat-window" id="hinvWindow">
        <!-- Header -->
        <div class="hinv-chat-header">
          <div class="hinv-header-info">
            <div class="hinv-avatar-stack">
              <svg viewBox="0 0 40 40" aria-hidden="true"><path d="M12 29V11l16 18V11" fill="none" stroke="#e8c988" stroke-width="3.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              <span class="hinv-online-dot"></span>
            </div>
            <div>
              <div class="hinv-header-title">Nexora Support</div>
              <div class="hinv-header-status">
                <i class="hinv-live-dot"></i> Online &middot; replies in seconds
              </div>
            </div>
          </div>
          <div class="hinv-header-controls">
            <button class="hinv-btn-icon" id="hinvSoundBtn" title="Toggle Sound">
              🔊
            </button>
            <button class="hinv-btn-icon" id="hinvCloseBtn" title="Close Chat">
              ✕
            </button>
          </div>
        </div>

        <!-- Chips -->
        <div class="hinv-chips-bar" id="hinvChipsBar">
          <button class="hinv-chip" data-action="deposit">Deposits</button>
          <button class="hinv-chip" data-action="withdraw">Withdrawals</button>
          <button class="hinv-chip" data-action="kyc">Verification</button>
          <button class="hinv-chip" data-action="trading">Copy trading</button>
          <button class="hinv-chip" data-action="human">Talk to a person</button>
        </div>

        <!-- Messages -->
        <div class="hinv-chat-messages" id="hinvMessages"></div>

        <!-- Typing -->
        <div class="hinv-typing-bubble" id="hinvTyping">
          <div class="hinv-typing-dots"><span></span><span></span><span></span></div>
          <span id="hinvTypingText">Support agent is typing...</span>
        </div>

        <!-- Footer -->
        <div class="hinv-chat-footer">
          <input type="text" class="hinv-input" id="hinvInput" placeholder="Type your message…" autocomplete="off">
          <button class="hinv-send-btn" id="hinvSendBtn" title="Send message">
            <svg viewBox="0 0 24 24">
              <path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/>
            </svg>
          </button>
        </div>
      </div>
    `;

    document.body.appendChild(root);

    // Event Listeners
    document.getElementById('hinvLauncher').addEventListener('click', toggleChat);
    document.getElementById('hinvCloseBtn').addEventListener('click', toggleChat);
    document.getElementById('hinvSoundBtn').addEventListener('click', toggleSound);

    document.getElementById('hinvSendBtn').addEventListener('click', sendMessage);
    document.getElementById('hinvInput').addEventListener('keydown', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        sendMessage();
      } else {
        handleTypingHeartbeat();
      }
    });

    // Chips clicks
    document.getElementById('hinvChipsBar').addEventListener('click', function(e) {
      const chip = e.target.closest('.hinv-chip');
      if (!chip) return;
      if (chip.getAttribute('data-url')) {
        window.location.href = chip.getAttribute('data-url');
      } else if (chip.getAttribute('data-action')) {
        sendChipMessage(chip.getAttribute('data-action'), chip.textContent.trim());
      }
    });
  }

  function toggleSound() {
    isSoundEnabled = !isSoundEnabled;
    const btn = document.getElementById('hinvSoundBtn');
    btn.textContent = isSoundEnabled ? '🔊' : '🔇';
  }

  function toggleChat() {
    isOpen = !isOpen;
    const win = document.getElementById('hinvWindow');
    win.classList.toggle('open', isOpen);

    if (isOpen) {
      unreadCount = 0;
      updateBadge();
      if (!currentThreadId) {
        initChat();
      }
      setTimeout(() => {
        document.getElementById('hinvInput').focus();
        scrollToBottom();
      }, 200);
    }
  }

  function updateBadge() {
    const badge = document.getElementById('hinvBadge');
    if (unreadCount > 0 && !isOpen) {
      badge.textContent = unreadCount > 9 ? '9+' : unreadCount;
      badge.style.display = 'flex';
    } else {
      badge.style.display = 'none';
    }
  }

  async function initChat() {
    try {
      const res = await fetch('/api/livechat.php?action=init');
      const data = await res.json();
      if (data.ok && data.thread) {
        currentThreadId = data.thread.id;
        const msgContainer = document.getElementById('hinvMessages');
        msgContainer.innerHTML = '';
        if (data.messages) {
          data.messages.forEach(m => appendMessage(m));
          if (data.messages.length > 0) {
            lastMessageId = data.messages[data.messages.length - 1].id;
          }
        }
        scrollToBottom();

        if (pollInterval) clearInterval(pollInterval);
        pollInterval = setInterval(pollChat, 1800);
      }
    } catch (e) {}
  }

  async function pollChat() {
    if (!currentThreadId) return;
    try {
      const res = await fetch(`/api/livechat.php?action=poll&thread_id=${currentThreadId}&after_id=${lastMessageId}`);
      const data = await res.json();
      if (data.ok) {
        // Typing indicator
        const typingEl = document.getElementById('hinvTyping');
        if (data.is_typing) {
          typingEl.style.display = 'flex';
          scrollToBottom();
        } else {
          typingEl.style.display = 'none';
        }

        // New messages
        if (data.messages && data.messages.length > 0) {
          data.messages.forEach(m => {
            appendMessage(m);
            lastMessageId = Math.max(lastMessageId, m.id);
          });
          scrollToBottom();
          playSound();
          if (!isOpen) {
            unreadCount += data.messages.length;
            updateBadge();
          }
        }
      }
    } catch (e) {}
  }

  function appendMessage(m, customChips = null) {
    const container = document.getElementById('hinvMessages');
    const row = document.createElement('div');
    const isUser = m.sender_type === 'user';
    const isBot = m.is_bot == 1;

    row.className = `hinv-msg-row ${isUser ? 'user' : (isBot ? 'bot' : 'admin')}`;

    let tag = '';
    if (!isUser) {
      tag = `<div class="hinv-msg-tag">${isBot ? '🤖 Nexora Assistant' : '🛡️ Live Agent'}</div>`;
    }

    const timeStr = m.created_at ? new Date(m.created_at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';

    row.innerHTML = `
      ${tag}
      <div class="hinv-msg-bubble">${formatMsg(m.message)}</div>
      <div class="hinv-msg-time">${timeStr}</div>
    `;

    // If message includes chips, render inline
    if (customChips && customChips.length > 0) {
      const chipsWrap = document.createElement('div');
      chipsWrap.style.display = 'flex';
      chipsWrap.style.flexWrap = 'wrap';
      chipsWrap.style.gap = '6px';
      chipsWrap.style.marginTop = '6px';

      customChips.forEach(c => {
        const btn = document.createElement('button');
        btn.className = 'hinv-chip';
        btn.textContent = String(c.label).replace(/^[\p{Extended_Pictographic}\uFE0F\u200D\s]+/u, '');
        if (c.url) {
          btn.addEventListener('click', () => window.location.href = c.url);
        } else if (c.action) {
          btn.addEventListener('click', () => sendChipMessage(c.action, c.label));
        }
        chipsWrap.appendChild(btn);
      });
      row.appendChild(chipsWrap);
    }

    container.appendChild(row);
  }

  function scrollToBottom() {
    const msgContainer = document.getElementById('hinvMessages');
    msgContainer.scrollTop = msgContainer.scrollHeight;
  }

  async function sendMessage() {
    const input = document.getElementById('hinvInput');
    const text = input.value.trim();
    if (!text) return;

    input.value = '';

    if (!currentThreadId) {
      await initChat();
    }
    if (!currentThreadId) return;

    try {
      const fd = new FormData();
      fd.append('action', 'send');
      fd.append('thread_id', currentThreadId);
      fd.append('message', text);

      const res = await fetch('/api/livechat.php', { method: 'POST', body: fd });
      const data = await res.json();
      if (data.ok) {
        if (data.sent) {
          appendMessage(data.sent);
          lastMessageId = Math.max(lastMessageId, data.sent.id);
        }
        if (data.bot_reply) {
          setTimeout(() => {
            appendMessage(data.bot_reply, data.bot_reply.chips);
            lastMessageId = Math.max(lastMessageId, data.bot_reply.id);
            scrollToBottom();
            playSound();
          }, 350);
        }
        scrollToBottom();
      }
    } catch (e) {}
  }

  function sendChipMessage(actionKey, label) {
    const input = document.getElementById('hinvInput');
    input.value = label;
    sendMessage();
  }

  function handleTypingHeartbeat() {
    const now = Date.now();
    if (now - lastTypingSent > 2000 && currentThreadId) {
      lastTypingSent = now;
      fetch(`/api/livechat.php?action=typing&thread_id=${currentThreadId}`, { method: 'POST' });
    }
  }

  // Safe text with **bold** support for bot replies.
  function formatMsg(str) {
    return escapeHtml(str).replace(/\*\*([^*]+?)\*\*/g, '<strong>$1</strong>');
  }

  function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
  }

  // Initialize on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createWidgetDOM);
  } else {
    createWidgetDOM();
  }

})();
