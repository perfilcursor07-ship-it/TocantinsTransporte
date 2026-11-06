<!-- Chat Widget - Popup Flutuante Moderno -->
<div id="chat-widget" class="fixed bottom-4 right-4 sm:bottom-6 sm:right-6 z-[9999]">
    <!-- Botão de Abrir Chat - Design Moderno -->
    <button id="chat-toggle-btn" onclick="toggleChatWidget()" class="chat-fab-button group">
        <!-- Círculo externo com gradiente -->
        <div class="chat-fab-outer"></div>
        
        <!-- Círculo principal -->
        <div class="chat-fab-inner">
            <!-- Ícone de chat -->
            <span id="chat-icon" class="chat-fab-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>
                </svg>
            </span>
            <!-- Ícone de fechar -->
            <span id="chat-close-icon" class="chat-fab-icon hidden">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
            </span>
        </div>
        
        <!-- Badge de notificação -->
        <span id="chat-badge" class="chat-fab-badge hidden">!</span>
    </button>

    <!-- Tooltip elegante -->
    <div id="chat-tooltip" class="chat-tooltip">
        <span>💬</span> Precisa de ajuda?
    </div>

    <!-- Chat Box -->
    <div id="chat-box" class="hidden absolute bottom-16 right-0 w-[340px] sm:w-[380px] bg-white rounded-2xl shadow-2xl overflow-hidden border border-gray-100">
        <!-- Header compacto -->
        <div class="bg-gradient-to-r from-emerald-500 to-teal-600 px-4 py-3 text-white">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-white/20 rounded-xl flex items-center justify-center">
                    <span class="text-xl">🚌</span>
                </div>
                <div class="flex-1">
                    <h4 class="font-semibold text-sm">WiFi Tocantins</h4>
                    <div class="flex items-center space-x-1.5">
                        <span id="chat-status-dot" class="w-1.5 h-1.5 bg-green-300 rounded-full animate-pulse"></span>
                        <p id="chat-status-text" class="text-xs text-emerald-100">Online</p>
                    </div>
                </div>
                <button onclick="toggleChatWidget()" class="w-7 h-7 bg-white/20 hover:bg-white/30 rounded-full flex items-center justify-center transition-colors">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                </button>
            </div>
        </div>

        <!-- Formulário Inicial Compacto -->
        <div id="chat-form-container" class="p-4">
            <div class="text-center mb-3">
                <h5 class="font-semibold text-gray-800 text-sm">👋 Como podemos ajudar?</h5>
            </div>
            
            <form id="chat-start-form" class="space-y-2.5">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                        </svg>
                    </div>
                    <input type="text" id="chat-name" placeholder="Seu nome" required
                           class="w-full pl-9 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-emerald-500 focus:bg-white transition-all">
                </div>
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                    </div>
                    <input type="tel" id="chat-phone" placeholder="Telefone do cadastro" required maxlength="16"
                           class="w-full pl-9 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-emerald-500 focus:bg-white transition-all">
                    <p class="text-[10px] text-gray-400 mt-0.5 ml-1">Use o mesmo telefone que cadastrou no WiFi</p>
                </div>
                <div class="relative">
                    <div class="absolute top-2.5 left-0 pl-3 pointer-events-none">
                        <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                        </svg>
                    </div>
                    <textarea id="chat-first-message" placeholder="Como podemos ajudar?" required rows="2"
                              class="w-full pl-9 pr-3 py-2.5 bg-gray-50 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-emerald-500 focus:bg-white transition-all resize-none"></textarea>
                </div>
                <button type="submit" id="chat-start-btn"
                        class="w-full bg-gradient-to-r from-emerald-500 to-teal-600 text-white py-2.5 rounded-lg font-medium text-sm hover:shadow-lg hover:shadow-emerald-500/30 transition-all flex items-center justify-center space-x-2">
                    <span>Iniciar Conversa</span>
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                    </svg>
                </button>
            </form>
            
            <p class="text-[10px] text-gray-400 text-center mt-3 flex items-center justify-center space-x-1">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <span>Dados protegidos</span>
            </p>
        </div>

        <!-- Área de Mensagens Compacta -->
        <div id="chat-messages-container" class="hidden flex flex-col" style="height: 380px;">
            <!-- Mensagens -->
            <div id="chat-messages" class="flex-1 overflow-y-auto p-3 space-y-3 bg-gray-50">
                <!-- Mensagem de boas-vindas -->
                <div class="flex justify-center">
                    <span class="text-[10px] text-gray-400 bg-white px-2 py-0.5 rounded-full shadow-sm">Início da conversa</span>
                </div>
            </div>

            <!-- Input de Mensagem -->
            <div class="p-3 border-t bg-white">
                <form id="chat-send-form" class="flex items-center space-x-2">
                    <input type="text" id="chat-message-input" placeholder="Digite sua mensagem..." autocomplete="off"
                           class="flex-1 bg-gray-100 border border-transparent rounded-lg px-3 py-2 text-sm focus:outline-none focus:border-emerald-500 focus:bg-white transition-all">
                    <button type="submit" 
                            class="w-9 h-9 bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-lg hover:shadow-md transition-all flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>


<style>
/* ===== BOTÃO FAB DO CHAT ===== */
.chat-fab-button {
    position: relative;
    width: 56px;
    height: 56px;
    border: none;
    background: transparent;
    cursor: pointer;
    outline: none;
    -webkit-tap-highlight-color: transparent;
}

.chat-fab-outer {
    position: absolute;
    inset: 0;
    border-radius: 18px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    opacity: 0.2;
    animation: fabPulse 2.5s ease-in-out infinite;
}

.chat-fab-inner {
    position: absolute;
    inset: 3px;
    border-radius: 15px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 4px 12px rgba(16, 185, 129, 0.35), 0 1px 3px rgba(0, 0, 0, 0.08);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.chat-fab-button:hover .chat-fab-inner {
    transform: scale(1.06);
    box-shadow: 0 6px 20px rgba(16, 185, 129, 0.45), 0 2px 6px rgba(0, 0, 0, 0.1);
}

.chat-fab-button:active .chat-fab-inner {
    transform: scale(0.95);
}

.chat-fab-icon {
    color: white;
    width: 24px;
    height: 24px;
    transition: transform 0.3s ease;
}

.chat-fab-icon svg {
    width: 100%;
    height: 100%;
}

.chat-fab-button:hover .chat-fab-icon {
    transform: scale(1.08);
}

.chat-fab-badge {
    position: absolute;
    top: 0;
    right: 0;
    width: 20px;
    height: 20px;
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    color: white;
    font-size: 11px;
    font-weight: 700;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(239, 68, 68, 0.5);
    animation: badgeBounce 1s ease infinite;
    border: 2px solid white;
}

.chat-tooltip {
    position: absolute;
    bottom: 70px;
    right: 0;
    background: white;
    color: #374151;
    font-size: 14px;
    font-weight: 500;
    padding: 10px 16px;
    border-radius: 12px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
    white-space: nowrap;
    opacity: 0;
    transform: translateY(10px);
    transition: all 0.3s ease;
    pointer-events: none;
    border: 1px solid #e5e7eb;
}

.chat-tooltip::after {
    content: '';
    position: absolute;
    bottom: -6px;
    right: 20px;
    width: 12px;
    height: 12px;
    background: white;
    border-right: 1px solid #e5e7eb;
    border-bottom: 1px solid #e5e7eb;
    transform: rotate(45deg);
}

#chat-widget:hover .chat-tooltip {
    opacity: 1;
    transform: translateY(0);
}

@keyframes fabPulse {
    0%, 100% {
        transform: scale(1);
        opacity: 0.2;
    }
    50% {
        transform: scale(1.2);
        opacity: 0.08;
    }
}

@keyframes badgeBounce {
    0%, 100% {
        transform: scale(1);
    }
    50% {
        transform: scale(1.1);
    }
}

/* ===== CHAT BOX ===== */
#chat-box {
    animation: chatSlideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
}

@keyframes chatSlideUp {
    from {
        opacity: 0;
        transform: translateY(30px) scale(0.95);
    }
    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

#chat-messages::-webkit-scrollbar {
    width: 5px;
}

#chat-messages::-webkit-scrollbar-track {
    background: transparent;
}

#chat-messages::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

#chat-messages::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

.chat-message-enter {
    animation: messageEnter 0.3s ease-out;
}

@keyframes messageEnter {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.chat-msg-body {
    white-space: pre-line;
    word-break: break-word;
    line-height: 1.45;
}

.chat-typing-bubble {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 10px 14px;
}

.chat-typing-dot {
    width: 7px;
    height: 7px;
    border-radius: 9999px;
    background: #94a3b8;
    animation: chatTypingBounce 1.2s infinite ease-in-out;
}

.chat-typing-dot:nth-child(2) { animation-delay: 0.15s; }
.chat-typing-dot:nth-child(3) { animation-delay: 0.3s; }

@keyframes chatTypingBounce {
    0%, 60%, 100% { transform: translateY(0); opacity: 0.45; }
    30% { transform: translateY(-4px); opacity: 1; }
}

/* Tooltip animation */
#chat-widget:hover #chat-tooltip {
    opacity: 1;
}

/* Mobile responsivo */
@media (max-width: 640px) {
    #chat-widget {
        bottom: 16px !important;
        right: 16px !important;
    }
    
    .chat-fab-button {
        width: 50px !important;
        height: 50px !important;
    }
    
    .chat-fab-icon {
        width: 20px !important;
        height: 20px !important;
    }
    
    .chat-tooltip {
        display: none !important;
    }
    
    #chat-box {
        position: fixed !important;
        bottom: 0 !important;
        left: 0 !important;
        right: 0 !important;
        width: 100% !important;
        max-width: 100% !important;
        max-height: 85vh !important;
        border-radius: 24px 24px 0 0 !important;
        margin: 0 !important;
    }
    
    #chat-messages-container {
        height: 50vh !important;
    }
    
    #chat-tooltip {
        display: none !important;
    }
}
</style>

<script>
(function() {
    let chatSessionId = localStorage.getItem('chat_session_id');
    let chatUserName = localStorage.getItem('chat_user_name');
    let chatOpen = false;
    let lastMessageId = 0;
    let pollingInterval = null;
    let typingTimer = null;
    let isShowingTyping = false;

    function escapeHtml(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    /** Formata texto do chat: limpa *markdown*, quebra passos e preserva linhas. */
    function formatChatText(text) {
        let s = String(text ?? '');

        // Separa "1) ... 2) ..." colados numa linha
        s = s.replace(/\s+(\d+\))\s+/g, '\n\n$1 ');

        // Remove **negrito** / *ênfase* markdown, mantendo o texto
        s = s.replace(/\*\*([^*]+)\*\*/g, '$1');
        s = s.replace(/\*([^*\n]+)\*/g, '$1');
        s = s.replace(/\*/g, '');

        s = s.replace(/\n{3,}/g, '\n\n').trim();

        return escapeHtml(s);
    }

    function setChatStatus(typing) {
        const textEl = document.getElementById('chat-status-text');
        const dotEl = document.getElementById('chat-status-dot');
        if (!textEl) return;
        if (typing) {
            textEl.textContent = 'digitando...';
            if (dotEl) dotEl.classList.add('animate-pulse');
        } else {
            textEl.textContent = 'Online';
        }
    }

    function showTypingIndicator() {
        const messagesDiv = document.getElementById('chat-messages');
        if (!messagesDiv) return;

        isShowingTyping = true;
        setChatStatus(true);

        let el = document.getElementById('chat-typing-indicator');
        if (el) {
            messagesDiv.appendChild(el);
            messagesDiv.scrollTop = messagesDiv.scrollHeight;
            return;
        }

        el = document.createElement('div');
        el.id = 'chat-typing-indicator';
        el.className = 'flex justify-start chat-message-enter';
        el.innerHTML = `
            <div class="flex items-end space-x-2 max-w-[85%]">
                <div class="w-8 h-8 bg-gradient-to-br from-emerald-400 to-teal-500 rounded-full flex items-center justify-center text-white text-xs font-bold flex-shrink-0 shadow-md">A</div>
                <div class="bg-white rounded-2xl rounded-bl-md shadow-sm border border-gray-100 chat-typing-bubble">
                    <span class="chat-typing-dot"></span>
                    <span class="chat-typing-dot"></span>
                    <span class="chat-typing-dot"></span>
                </div>
            </div>
        `;
        messagesDiv.appendChild(el);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    function hideTypingIndicator() {
        isShowingTyping = false;
        setChatStatus(false);
        if (typingTimer) {
            clearTimeout(typingTimer);
            typingTimer = null;
        }
        const el = document.getElementById('chat-typing-indicator');
        if (el) el.remove();
    }

    /** Mostra "digitando..." e depois a resposta (efeito humano). */
    function presentAiReply(msg) {
        if (!msg) {
            hideTypingIndicator();
            return;
        }

        if (msg.id) {
            lastMessageId = Math.max(lastMessageId, msg.id);
        }

        const alreadyTyping = isShowingTyping;
        showTypingIndicator();

        const len = (msg.message || '').length;
        // Se já estava digitando durante a chamada da API, só segura um pouco a mais
        const delay = alreadyTyping
            ? Math.min(2800, Math.max(900, 500 + len * 12))
            : Math.min(4200, Math.max(1400, 900 + len * 18));

        if (typingTimer) clearTimeout(typingTimer);
        typingTimer = setTimeout(() => {
            hideTypingIndicator();
            renderServerMessage(msg);
        }, delay);
    }

    // Máscara de telefone
    const phoneInput = document.getElementById('chat-phone');
    if (phoneInput) {
        phoneInput.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length > 11) value = value.slice(0, 11);
            
            if (value.length > 0) {
                value = '(' + value;
                if (value.length > 3) value = value.slice(0, 3) + ') ' + value.slice(3);
                if (value.length > 10) value = value.slice(0, 10) + '-' + value.slice(10);
            }
            e.target.value = value;
        });
    }

    // Toggle Chat Widget
    window.toggleChatWidget = function() {
        const chatBox = document.getElementById('chat-box');
        const chatIcon = document.getElementById('chat-icon');
        const closeIcon = document.getElementById('chat-close-icon');
        const tooltip = document.getElementById('chat-tooltip');
        const badge = document.getElementById('chat-badge');
        
        chatOpen = !chatOpen;
        
        if (chatOpen) {
            chatBox.classList.remove('hidden');
            chatIcon.classList.add('hidden');
            closeIcon.classList.remove('hidden');
            tooltip.style.display = 'none';
            badge.classList.add('hidden');
            
            // Se já tem sessão, mostrar mensagens
            if (chatSessionId) {
                showMessagesContainer();
                loadMessages();
                startPolling();
            }
        } else {
            chatBox.classList.add('hidden');
            chatIcon.classList.remove('hidden');
            closeIcon.classList.add('hidden');
            tooltip.style.display = '';
            stopPolling();
        }
    };

    // Mostrar container de mensagens
    function showMessagesContainer() {
        document.getElementById('chat-form-container').classList.add('hidden');
        document.getElementById('chat-messages-container').classList.remove('hidden');
    }

    // Adicionar mensagem na tela
    // Renderiza uma mensagem vinda do servidor (aceita probe_request, escalate, text)
    function renderServerMessage(msg) {
        if (!msg) return;
        const isAdmin = msg.sender_type === 'admin';
        const isAI = isAdmin && msg.metadata && msg.metadata.ai === true;
        const adminName = isAI ? (msg.metadata.ai_name || 'Ana') : (msg.admin && msg.admin.name ? msg.admin.name : 'Atendente');
        const time = new Date(msg.created_at).toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});

        if (msg.type === 'probe_request' && msg.metadata && msg.metadata.probe_url) {
            renderProbeButton(msg.message, msg.metadata.probe_url, adminName, time);
        } else if (msg.type === 'voucher_offer' && msg.metadata && msg.metadata.voucher_code) {
            renderVoucherCard(msg.metadata, adminName, time);
        } else if (msg.type === 'receipt_request') {
            renderReceiptRequest(msg.message, adminName, time, msg.id);
        } else if (msg.type === 'receipt_upload' && msg.metadata && msg.metadata.receipt_url) {
            renderReceiptUpload(msg.metadata, time);
        } else if (msg.type === 'mac_request') {
            renderMacRequest(msg.message, adminName, time, msg.id);
        } else if (msg.type === 'recovery_request') {
            renderRecoveryRequest(msg.message, adminName, time, msg.metadata);
        } else if (msg.type === 'mac_upload' && msg.metadata && msg.metadata.mac_url) {
            renderMacUpload(msg.metadata, time);
        } else if (msg.type === 'mac_text' && msg.metadata && msg.metadata.mac_address) {
            renderMacText(msg.metadata.mac_address, time);
        } else {
            addMessage(msg.message, isAdmin, adminName, time);
        }
    }

    function appendChatNode(node) {
        const messagesDiv = document.getElementById('chat-messages');
        if (!messagesDiv || !node) return;
        const typing = document.getElementById('chat-typing-indicator');
        if (typing) {
            messagesDiv.insertBefore(node, typing);
        } else {
            messagesDiv.appendChild(node);
        }
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    function renderVoucherCard(meta, adminName, time) {
        const messagesDiv = document.getElementById('chat-messages');
        const div = document.createElement('div');
        div.className = 'flex justify-start chat-message-enter';
        const code = meta.voucher_code || '---';
        const hours = meta.voucher_hours || 12;
        let url = meta.activate_url || '/voucher/ativar';
        // Corrigir URLs localhost salvas em dev
        if (url.includes('localhost')) {
            url = window.location.origin + '/voucher/ativar';
        }
        div.innerHTML = `
            <div class="max-w-[92%] w-full bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-300 rounded-xl p-3 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white text-base shadow">🎁</div>
                    <div class="flex-1">
                        <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider">Voucher de cortesia</p>
                        <p class="text-xs font-bold text-emerald-800">${hours} horas de cortesia · ${adminName} · ${time}</p>
                    </div>
                </div>
                <div class="bg-white rounded-lg p-2.5 text-center shadow-inner border border-emerald-100 mb-2">
                    <p class="text-[9px] text-gray-500 uppercase tracking-wider font-bold">Seu código</p>
                    <p class="text-lg font-mono font-bold text-emerald-700 tracking-widest my-1 select-all">${code}</p>
                    <button onclick="navigator.clipboard.writeText('${code}'); this.textContent='✓ copiado';"
                            class="text-[10px] px-2 py-0.5 bg-emerald-50 border border-emerald-200 rounded hover:bg-emerald-100 font-semibold text-emerald-700">
                        copiar código
                    </button>
                </div>
                <a href="${url}" target="_blank" class="block w-full bg-emerald-600 hover:bg-emerald-700 text-white text-center py-2 rounded-lg font-semibold text-xs transition">
                    ▶ Ativar voucher agora
                </a>
                <p class="text-[9px] text-gray-500 mt-1.5 leading-tight">Clique em "Ativar" e informe o código acima para ganhar ${hours}h de internet.</p>
            </div>
        `;
        appendChatNode(div);
    }

    function renderProbeButton(text, url, adminName, time) {
        const messagesDiv = document.getElementById('chat-messages');
        const div = document.createElement('div');
        div.className = 'flex justify-start chat-message-enter';
        div.innerHTML = `
            <div class="max-w-[90%] w-full bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-xl p-3 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-7 h-7 rounded-lg bg-blue-500 flex items-center justify-center text-white text-xs">📡</div>
                    <div class="flex-1">
                        <p class="text-[10px] font-bold text-blue-700 uppercase tracking-wider">Teste de conexão</p>
                        <p class="text-[9px] text-gray-500">${adminName} · ${time}</p>
                    </div>
                </div>
                <p class="text-xs text-gray-700 mb-2 chat-msg-body">${formatChatText(text)}</p>
                <a href="${url}" target="_blank" class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2 rounded-lg font-semibold text-xs transition">
                    ▶ Fazer teste agora
                </a>
                <p class="text-[9px] text-gray-400 mt-1 text-center">Leva 15 segundos</p>
            </div>
        `;
        appendChatNode(div);
    }

    function renderReceiptRequest(text, adminName, time, msgId) {
        const messagesDiv = document.getElementById('chat-messages');
        const div = document.createElement('div');
        div.className = 'flex justify-start chat-message-enter';
        const inputId = 'receipt-file-' + (msgId || Date.now());
        div.innerHTML = `
            <div class="max-w-[92%] w-full bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-300 rounded-xl p-3 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center text-white text-base shadow">📎</div>
                    <div class="flex-1">
                        <p class="text-[10px] font-bold text-amber-800 uppercase tracking-wider">Comprovante PIX</p>
                        <p class="text-[9px] text-gray-500">${adminName} · ${time}</p>
                    </div>
                </div>
                <p class="text-xs text-gray-700 mb-2 leading-snug chat-msg-body">${formatChatText(text)}</p>
                <input type="file" id="${inputId}" accept="image/*,.pdf" class="hidden" />
                <button type="button" data-receipt-input="${inputId}"
                        class="receipt-upload-btn w-full bg-amber-600 hover:bg-amber-700 text-white text-center py-2.5 rounded-lg font-semibold text-xs transition">
                    📷 Enviar comprovante
                </button>
                <p class="text-[9px] text-gray-500 mt-1.5 text-center">Foto ou print do PIX · máx. 5 MB</p>
                <p class="receipt-upload-status text-[10px] text-amber-700 mt-1 text-center hidden"></p>
            </div>
        `;
        appendChatNode(div);

        const btn = div.querySelector('.receipt-upload-btn');
        const input = div.querySelector('#' + inputId);
        const status = div.querySelector('.receipt-upload-status');
        btn.addEventListener('click', () => input.click());
        input.addEventListener('change', () => {
            if (input.files && input.files[0]) {
                uploadReceiptFile(input.files[0], btn, status);
            }
        });
    }

    function renderReceiptUpload(meta, time) {
        const div = document.createElement('div');
        div.className = 'flex justify-end chat-message-enter';
        const url = meta.receipt_url || '';
        const isPdf = (meta.receipt_mime || '').includes('pdf') || (url || '').toLowerCase().endsWith('.pdf');
        const preview = isPdf
            ? `<a href="${url}" target="_blank" class="block text-center text-xs font-semibold text-emerald-800 underline py-4">📄 Abrir PDF do comprovante</a>`
            : `<a href="${url}" target="_blank"><img src="${url}" alt="Comprovante" class="max-h-40 mx-auto rounded-lg border border-emerald-200 object-contain bg-white"></a>`;
        div.innerHTML = `
            <div class="max-w-[85%] bg-emerald-600 text-white rounded-xl p-2.5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-100 mb-1.5">Comprovante enviado · ${time}</p>
                <div class="bg-white/95 rounded-lg p-1.5">${preview}</div>
            </div>
        `;
        appendChatNode(div);
    }

    function renderRecoveryRequest(text, adminName, time, meta) {
        const div = document.createElement('div');
        div.className = 'flex justify-start chat-message-enter';
        div.innerHTML = `
            <div class="max-w-[92%] w-full bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-300 rounded-xl p-3 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-amber-500 flex items-center justify-center text-white text-base shadow">🔄</div>
                    <div class="flex-1">
                        <p class="text-[10px] font-bold text-amber-800 uppercase tracking-wider">Reativar acesso</p>
                        <p class="text-[9px] text-gray-500">${adminName} · ${time}</p>
                    </div>
                </div>
                <p class="text-xs text-gray-700 mb-2 leading-snug chat-msg-body">${formatChatText(text)}</p>
                <button type="button"
                        class="recovery-open-btn w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white text-center py-2.5 rounded-lg font-bold text-xs transition">
                    🔄 Recuperar acesso
                </button>
                <p class="text-[9px] text-gray-500 mt-1.5 text-center">Informe o telefone que você usou para pagar</p>
            </div>
        `;
        appendChatNode(div);

        const fallbackUrl = (meta && meta.recovery_url) || '/reativar';
        div.querySelector('.recovery-open-btn').addEventListener('click', () => openRecoveryFromChat(fallbackUrl));
    }

    // No portal abre a janela "Recuperar acesso" da própria página; fora dele, a /reativar.
    // A janela fica numa camada abaixo do chat (z-55 x z-9999), então fecha o chat antes,
    // senão ela abriria escondida atrás dele.
    function openRecoveryFromChat(fallbackUrl) {
        if (typeof window.openRecoveryModal === 'function' && document.getElementById('recovery-modal')) {
            if (chatOpen) window.toggleChatWidget();
            window.openRecoveryModal();
            return;
        }
        window.location.href = fallbackUrl;
    }

    function renderMacRequest(text, adminName, time, msgId) {
        const div = document.createElement('div');
        div.className = 'flex justify-start chat-message-enter';
        const inputId = 'mac-file-' + (msgId || Date.now());
        div.innerHTML = `
            <div class="max-w-[92%] w-full bg-gradient-to-br from-sky-50 to-indigo-50 border-2 border-sky-300 rounded-xl p-3 shadow-sm">
                <div class="flex items-center gap-2 mb-2">
                    <div class="w-8 h-8 rounded-lg bg-sky-600 flex items-center justify-center text-white text-base shadow">📶</div>
                    <div class="flex-1">
                        <p class="text-[10px] font-bold text-sky-800 uppercase tracking-wider">MAC da rede</p>
                        <p class="text-[9px] text-gray-500">${adminName} · ${time}</p>
                    </div>
                </div>
                <p class="text-xs text-gray-700 mb-2 leading-snug chat-msg-body">${formatChatText(text)}</p>
                <input type="file" id="${inputId}" accept="image/*" capture="environment" class="hidden" />
                <button type="button" data-mac-input="${inputId}"
                        class="mac-upload-btn w-full bg-sky-600 hover:bg-sky-700 text-white text-center py-2.5 rounded-lg font-semibold text-xs transition">
                    📷 Enviar foto do MAC
                </button>
                <p class="text-[9px] text-gray-500 mt-1.5 text-center">Foto da tela de configurações · ou escreva o código no chat</p>
                <p class="mac-upload-status text-[10px] text-sky-700 mt-1 text-center hidden"></p>
            </div>
        `;
        appendChatNode(div);

        const btn = div.querySelector('.mac-upload-btn');
        const input = div.querySelector('#' + inputId);
        const status = div.querySelector('.mac-upload-status');
        btn.addEventListener('click', () => input.click());
        input.addEventListener('change', () => {
            if (input.files && input.files[0]) {
                uploadMacFile(input.files[0], btn, status);
            }
        });
    }

    function renderMacUpload(meta, time) {
        const div = document.createElement('div');
        div.className = 'flex justify-end chat-message-enter';
        const url = meta.mac_url || '';
        div.innerHTML = `
            <div class="max-w-[85%] bg-sky-600 text-white rounded-xl p-2.5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-wider text-sky-100 mb-1.5">Foto do MAC enviada · ${time}</p>
                <div class="bg-white/95 rounded-lg p-1.5">
                    <a href="${url}" target="_blank"><img src="${url}" alt="MAC da rede" class="max-h-40 mx-auto rounded-lg border border-sky-200 object-contain bg-white"></a>
                </div>
            </div>
        `;
        appendChatNode(div);
    }

    function renderMacText(mac, time) {
        const div = document.createElement('div');
        div.className = 'flex justify-end chat-message-enter';
        div.innerHTML = `
            <div class="max-w-[85%] bg-sky-600 text-white rounded-xl p-2.5 shadow-sm">
                <p class="text-[10px] font-bold uppercase tracking-wider text-sky-100 mb-1">MAC enviado · ${time}</p>
                <p class="text-sm font-mono font-bold tracking-wider">${escapeHtml(mac)}</p>
            </div>
        `;
        appendChatNode(div);
    }

    async function uploadMacFile(file, btn, statusEl) {
        if (!chatSessionId) {
            alert('Inicie a conversa antes de enviar a foto do MAC.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('Arquivo muito grande. Máximo 5 MB.');
            return;
        }

        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Enviando...';
        statusEl.classList.remove('hidden');
        statusEl.textContent = 'Enviando foto do MAC...';

        try {
            const form = new FormData();
            form.append('session_id', chatSessionId);
            form.append('mac_photo', file);

            const res = await fetch('/api/chat/upload-mac', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
                body: form,
            });
            const data = await res.json();

            if (!data.success) {
                throw new Error(data.error || 'Falha ao enviar');
            }

            btn.textContent = '✓ Enviado';
            btn.classList.remove('bg-sky-600', 'hover:bg-sky-700');
            btn.classList.add('bg-emerald-600');
            statusEl.textContent = 'Foto enviada!';

            if (data.message) renderServerMessage(data.message);
            if (data.ai_reply) presentAiReply(data.ai_reply);
        } catch (e) {
            console.error(e);
            btn.disabled = false;
            btn.textContent = originalText;
            statusEl.textContent = e.message || 'Erro ao enviar. Tente de novo.';
        }
    }

    async function uploadReceiptFile(file, btn, statusEl) {
        if (!chatSessionId) {
            alert('Inicie a conversa antes de enviar o comprovante.');
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert('Arquivo muito grande. Máximo 5 MB.');
            return;
        }

        const originalText = btn.textContent;
        btn.disabled = true;
        btn.textContent = 'Enviando...';
        statusEl.classList.remove('hidden');
        statusEl.textContent = 'Enviando comprovante...';

        try {
            const form = new FormData();
            form.append('session_id', chatSessionId);
            form.append('receipt', file);

            const res = await fetch('/api/chat/upload-receipt', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    'Accept': 'application/json',
                },
                body: form,
            });
            const data = await res.json();

            if (!data.success) {
                throw new Error(data.error || 'Falha ao enviar');
            }

            btn.textContent = '✓ Enviado';
            btn.classList.remove('bg-amber-600', 'hover:bg-amber-700');
            btn.classList.add('bg-emerald-600');
            statusEl.textContent = 'Comprovante enviado!';

            if (data.message) renderServerMessage(data.message);
            if (data.ai_reply) presentAiReply(data.ai_reply);
        } catch (e) {
            console.error(e);
            btn.disabled = false;
            btn.textContent = originalText;
            statusEl.textContent = e.message || 'Erro ao enviar. Tente de novo.';
        }
    }

    function addMessage(message, isAdmin = false, adminName = null, time = null) {
        const messagesDiv = document.getElementById('chat-messages');
        const msgDiv = document.createElement('div');
        msgDiv.className = `flex ${isAdmin ? 'justify-start' : 'justify-end'} chat-message-enter`;

        const displayTime = time || new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
        const body = formatChatText(message);

        if (isAdmin) {
            msgDiv.innerHTML = `
                <div class="flex items-end space-x-2 max-w-[92%]">
                    <div class="w-8 h-8 bg-gradient-to-br from-emerald-400 to-teal-500 rounded-full flex items-center justify-center text-white text-xs font-bold flex-shrink-0 shadow-md">
                        ${adminName ? escapeHtml(adminName.charAt(0).toUpperCase()) : 'A'}
                    </div>
                    <div class="bg-white rounded-2xl rounded-bl-md px-3.5 py-2.5 shadow-sm border border-gray-100">
                        ${adminName ? `<p class="text-[11px] font-semibold text-emerald-600 mb-1">${escapeHtml(adminName)}</p>` : ''}
                        <p class="text-sm text-gray-800 chat-msg-body">${body}</p>
                        <p class="text-[10px] text-gray-400 mt-1.5">${displayTime}</p>
                    </div>
                </div>
            `;
        } else {
            msgDiv.innerHTML = `
                <div class="max-w-[85%]">
                    <div class="bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-2xl rounded-br-md px-3.5 py-2.5 shadow-md">
                        <p class="text-sm chat-msg-body">${body}</p>
                        <p class="text-[10px] text-emerald-100 mt-1.5 text-right">${displayTime}</p>
                    </div>
                </div>
            `;
        }

        // Mantém o indicador de digitando no final
        const typing = document.getElementById('chat-typing-indicator');
        if (typing) {
            messagesDiv.insertBefore(msgDiv, typing);
        } else {
            messagesDiv.appendChild(msgDiv);
        }
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
    }

    // Iniciar conversa
    const startForm = document.getElementById('chat-start-form');
    if (startForm) {
        startForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const name = document.getElementById('chat-name').value.trim();
            const phone = document.getElementById('chat-phone').value.trim();
            const email = '';
            const message = document.getElementById('chat-first-message').value.trim();
            
            if (!name || !phone || !message) {
                alert('Preencha todos os campos');
                return;
            }

            // Desabilitar botão
            const btn = document.getElementById('chat-start-btn');
            btn.disabled = true;
            btn.innerHTML = `
                <svg class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <span>Conectando...</span>
            `;

            fetch('/api/chat/start', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    name: name,
                    phone: phone,
                    email: email,
                    message: message,
                    mac: window.CLIENT_MAC || (window.wifiPortal && window.wifiPortal.deviceMac) || (window.macDetector && window.macDetector.getMacForPayment()) || null
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    chatSessionId = data.session_id;
                    chatUserName = name;
                    localStorage.setItem('chat_session_id', chatSessionId);
                    localStorage.setItem('chat_user_name', chatUserName);

                    showMessagesContainer();
                    addMessage(message, false);

                    if (data.ai_reply) {
                        presentAiReply(data.ai_reply);
                    } else {
                        setTimeout(() => {
                            addMessage('Obrigado por entrar em contato, ' + name.split(' ')[0] + '! Nossa equipe foi notificada e responderá em breve.', true, 'Atendente');
                        }, 800);
                    }

                    startPolling();
                } else {
                    alert(data.message || 'Erro ao iniciar conversa. Tente novamente.');
                    resetStartButton();
                }
            })
            .catch(error => {
                console.error('Erro ao iniciar chat:', error);
                alert('Erro de conexão. Tente novamente.');
                resetStartButton();
            });
        });
    }

    function resetStartButton() {
        const btn = document.getElementById('chat-start-btn');
        btn.disabled = false;
        btn.innerHTML = `
            <span>Iniciar Conversa</span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
            </svg>
        `;
    }

    // Enviar mensagem
    const sendForm = document.getElementById('chat-send-form');
    if (sendForm) {
        sendForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const input = document.getElementById('chat-message-input');
            const message = input.value.trim();
            
            if (!message || !chatSessionId) return;

            // Adicionar mensagem imediatamente (otimista)
            addMessage(message, false);
            input.value = '';
            showTypingIndicator();

            fetch('/api/chat/send', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    session_id: chatSessionId,
                    message: message
                })
            })
            .then(response => {
                if (!response.ok) {
                    hideTypingIndicator();
                    clearSession();
                    return null;
                }
                return response.json();
            })
            .then(data => {
                if (!data) return;
                
                if (data.closed) {
                    hideTypingIndicator();
                    showConversationClosed();
                    return;
                }
                
                if (data.success && data.message && data.message.id) {
                    lastMessageId = Math.max(lastMessageId, data.message.id);
                }

                if (data.ai_reply) {
                    presentAiReply(data.ai_reply);
                } else {
                    hideTypingIndicator();
                }
            })
            .catch(error => {
                hideTypingIndicator();
                console.error('Erro ao enviar:', error);
            });
        });
    }

    // Carregar mensagens
    function loadMessages() {
        if (!chatSessionId) return;

        fetch(`/api/chat/messages?session_id=${chatSessionId}`)
            .then(response => {
                if (!response.ok) {
                    // Sessão não existe mais, limpar e mostrar formulário
                    clearSession();
                    return null;
                }
                return response.json();
            })
            .then(data => {
                if (!data) return;
                
                if (!data.success) {
                    // Sessão inválida, limpar
                    clearSession();
                    return;
                }
                
                if (data.messages) {
                    const messagesDiv = document.getElementById('chat-messages');
                    messagesDiv.innerHTML = `
                        <div class="flex justify-center">
                            <span class="text-[10px] text-gray-400 bg-white px-2 py-0.5 rounded-full shadow-sm">Início da conversa</span>
                        </div>
                    `;
                    
                    data.messages.forEach(msg => {
                        const visitorCard = msg.sender_type === 'visitor' && ['receipt_upload', 'mac_upload', 'mac_text'].includes(msg.type || 'text');
                        if (msg.sender_type === 'admin' || visitorCard) {
                            renderServerMessage(msg);
                        } else {
                            const time = new Date(msg.created_at).toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'});
                            addMessage(msg.message, false, null, time);
                        }
                        lastMessageId = Math.max(lastMessageId, msg.id);
                    });
                }
            })
            .catch(() => {
                // Erro de conexão, não limpar sessão
                console.log('Erro ao carregar mensagens');
            });
    }
    
    // Limpar sessão inválida
    function clearSession() {
        localStorage.removeItem('chat_session_id');
        localStorage.removeItem('chat_user_name');
        chatSessionId = null;
        chatUserName = null;
        lastMessageId = 0;
        stopPolling();
        
        // Mostrar formulário
        document.getElementById('chat-form-container').classList.remove('hidden');
        document.getElementById('chat-messages-container').classList.add('hidden');
    }

    // Polling para novas mensagens
    function startPolling() {
        if (pollingInterval) return;
        
        pollingInterval = setInterval(() => {
            if (!chatSessionId) return;

            fetch(`/api/chat/check?session_id=${chatSessionId}&last_id=${lastMessageId}`)
                .then(response => response.json())
                .then(data => {
                    // Verificar se conversa foi encerrada
                    if (data.closed) {
                        showConversationClosed();
                        return;
                    }
                    
                    if (data.success && data.has_new && data.messages) {
                        data.messages.forEach(msg => {
                            renderServerMessage(msg);
                            lastMessageId = Math.max(lastMessageId, msg.id);
                        });
                        
                        // Mostrar badge se chat fechado
                        if (!chatOpen) {
                            document.getElementById('chat-badge').classList.remove('hidden');
                        }
                    }
                });
        }, 4000);
    }

    function stopPolling() {
        if (pollingInterval) {
            clearInterval(pollingInterval);
            pollingInterval = null;
        }
    }

    // Mostrar mensagem de conversa encerrada
    function showConversationClosed() {
        stopPolling();
        
        const messagesDiv = document.getElementById('chat-messages');
        const closedMsg = document.createElement('div');
        closedMsg.className = 'flex justify-center my-4';
        closedMsg.innerHTML = `
            <div class="bg-gray-100 rounded-xl px-4 py-3 text-center max-w-[90%]">
                <div class="text-2xl mb-2">✅</div>
                <p class="text-sm font-medium text-gray-700">Conversa encerrada</p>
                <p class="text-xs text-gray-500 mt-1">Obrigado pelo contato!</p>
                <button onclick="startNewConversation()" class="mt-3 bg-emerald-500 text-white text-xs px-4 py-2 rounded-lg hover:bg-emerald-600 transition-colors">
                    Iniciar nova conversa
                </button>
            </div>
        `;
        messagesDiv.appendChild(closedMsg);
        messagesDiv.scrollTop = messagesDiv.scrollHeight;
        
        // Desabilitar input
        const input = document.getElementById('chat-message-input');
        if (input) {
            input.disabled = true;
            input.placeholder = 'Conversa encerrada';
        }
    }

    // Iniciar nova conversa
    window.startNewConversation = function() {
        // Limpar sessão
        localStorage.removeItem('chat_session_id');
        localStorage.removeItem('chat_user_name');
        chatSessionId = null;
        chatUserName = null;
        lastMessageId = 0;
        
        // Mostrar formulário
        document.getElementById('chat-form-container').classList.remove('hidden');
        document.getElementById('chat-messages-container').classList.add('hidden');
        
        // Limpar formulário
        document.getElementById('chat-name').value = '';
        document.getElementById('chat-phone').value = '';
        document.getElementById('chat-first-message').value = '';
        
        // Reabilitar input
        const input = document.getElementById('chat-message-input');
        if (input) {
            input.disabled = false;
            input.placeholder = 'Digite sua mensagem...';
        }
    };

    // Se já tem sessão, iniciar polling em background
    if (chatSessionId) {
        startPolling();
    }

    // Mostrar tooltip após 3 segundos
    setTimeout(() => {
        const tooltip = document.getElementById('chat-tooltip');
        if (tooltip && !chatOpen) {
            tooltip.style.opacity = '1';
            setTimeout(() => {
                tooltip.style.opacity = '0';
            }, 5000);
        }
    }, 3000);
})();
</script>
