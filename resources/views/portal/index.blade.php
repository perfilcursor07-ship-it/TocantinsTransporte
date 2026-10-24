<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WiFi Tocantins — Internet no ônibus</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @php
        $forceLogin = config('wifi.mikrotik.force_login_redirect', false);
        $skipLogin = request()->boolean('skip_login');
        $hasContext = request()->hasAny(['mac', 'mikrotik_mac', 'client_mac'])
            || request()->boolean('from_login')
            || request()->boolean('captive')
            || request()->boolean('from_router');
        $loginUrl = config('wifi.mikrotik.login_url', 'http://10.5.50.1/login');
    @endphp
    @if ($forceLogin && !$skipLogin && !$hasContext)
        {{-- Não usar meta refresh cego: quem acessa via 4G não alcança 10.5.50.1
             e recebe ERR_CONNECTION_TIMED_OUT antes da página carregar.
             O redirect é feito via JS somente se o roteador responder. --}}
        <script>window._TRY_HOTSPOT_LOGIN = @json($loginUrl . '?dst=' . urlencode(request()->fullUrl()));</script>
    @endif
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- Tailwind servido LOCALMENTE (mesmo domínio do portal). Evita depender do CDN
         externo, que pode ser bloqueado pelo walled garden do captive portal e quebrar
         todo o CSS da página de vendas. --}}
    <script src="{{ asset('js/tailwind.play.js') }}"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        surface: '#F0F4F3',
                        ink: '#111111',
                        ink2: '#333333',
                        muted: '#888888',
                        border: '#E0E0E0',
                        green: { DEFAULT: '#00A335', light: '#00C040', dark: '#007A28', pale: '#E8F5E9' },
                        gold: { DEFAULT: '#E6A817', pale: '#FFF8E1' },
                        red: { DEFAULT: '#D32F2F', pale: '#FFEBEE' },
                        blue: { DEFAULT: '#1565C0', light: '#1E88E5', pale: '#E3F2FD' },
                        brand: { 50: '#E8F5E9', 100: '#C8E6C9', 500: '#00A335', 600: '#007A28', 700: '#005A1D' }
                    },
                    fontFamily: { 'sans': ['Inter', 'system-ui', 'sans-serif'] },
                    boxShadow: {
                        card: '0 1px 4px rgba(0,0,0,0.06), 0 0 0 1px rgba(0,0,0,0.03)',
                        hover: '0 8px 24px rgba(0,0,0,0.10)',
                        modal: '0 20px 60px rgba(0,0,0,0.20)',
                        glow: '0 0 20px rgba(0,163,53,0.25)',
                    },
                }
            }
        }
    </script>
    <style>
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 4px 14px rgba(0,163,53,0.35), 0 0 0 0 rgba(0,163,53,0.4); }
            50% { box-shadow: 0 4px 20px rgba(0,163,53,0.5), 0 0 0 10px rgba(0,163,53,0); }
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }
        .animate-fade-in { animation: fadeIn 0.4s ease-out; }
        .animate-slide-up { animation: slideUp 0.5s cubic-bezier(.22,1,.36,1); }
        .animate-slide-up-delay { animation: slideUp 0.5s cubic-bezier(.22,1,.36,1) 0.1s both; }
        .btn-pulse { animation: pulse-glow 2s ease-in-out infinite; }
        .connect-button {
            background: linear-gradient(135deg, #00C040 0%, #007A28 100%);
            position: relative; overflow: hidden;
            transition: all 0.25s cubic-bezier(.22,1,.36,1);
        }
        .connect-button::after {
            content: '';
            position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            animation: shimmer 3s infinite;
        }
        .connect-button:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(0,163,53,0.35); }
        .connect-button:active { transform: scale(0.97); }
        .connect-button > span:first-child { letter-spacing: 0.04em; }
        .connect-button > span:first-child::after { content: ' \2192'; display: inline-block; margin-left: 4px; transition: transform .25s; }
        .connect-button:hover > span:first-child::after { transform: translateX(3px); }
        .wifi-plan-card {
            border-radius: 1rem;
            transition: border-color .2s, box-shadow .2s, background .2s, transform .15s;
        }
        .wifi-plan-card:active { transform: scale(0.985); }
        .plan-card-selected {
            border-color: #00A335 !important;
            background: linear-gradient(135deg, #f0fdf4 0%, #e6f9ee 100%) !important;
            box-shadow: 0 0 0 4px rgba(0,163,53,0.12), 0 10px 24px -8px rgba(0,122,40,0.28) !important;
        }
        .plan-card-selected [data-plan-radio] {
            border-width: 5px !important; border-color: #00A335 !important;
        }
        .plan-tag {
            position: absolute; top: 0; z-index: 10; pointer-events: none;
            font-size: 11px; font-weight: 800; line-height: 1; letter-spacing: .01em;
            padding: 5px 10px; border-radius: 999px; white-space: nowrap;
            box-shadow: 0 2px 6px rgba(0,0,0,0.08);
        }
        .hero-gradient {
            background:
                radial-gradient(120% 90% at 100% 0%, rgba(255,255,255,0.18) 0%, transparent 55%),
                radial-gradient(90% 70% at 0% 100%, rgba(0,192,64,0.45) 0%, transparent 60%),
                linear-gradient(160deg, #00531C 0%, #007A28 45%, #00A335 100%);
        }
        .price-pill {
            background: linear-gradient(135deg, #fef08a 0%, #facc15 100%);
            color: #422006;
        }
        .page-bg {
            background:
                radial-gradient(80% 40% at 50% 0%, rgba(0,163,53,0.08) 0%, transparent 70%),
                #F3F6F5;
        }
        .glass-card {
            background: #fff;
            border: 1px solid rgba(15,23,42,0.06);
            box-shadow: 0 1px 2px rgba(15,23,42,0.04), 0 12px 32px -12px rgba(15,23,42,0.14);
        }
        .app-icon { box-shadow: 0 4px 10px -3px rgba(0,0,0,0.25); }
        body { -webkit-font-smoothing: antialiased; -moz-osx-font-smoothing: grayscale; }
    </style>
</head>
<body class="font-sans min-h-screen page-bg">

    <!-- No-WiFi Warning Overlay -->
    <div id="no-wifi-warning" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[100] hidden">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl p-5 w-full max-w-sm animate-slide-up shadow-2xl text-center">
                <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728M15.536 8.464a5 5 0 010 7.072M6 18L18 6"/>
                    </svg>
                </div>
                <h3 class="text-base font-bold text-gray-900 mb-1">Desligue os dados móveis (4G)</h3>
                <p class="text-xs text-gray-500 mb-4">Para o pagamento liberar seu acesso, você precisa estar <strong>só no WiFi do ônibus</strong>.</p>

                <div class="text-left bg-gray-50 border border-gray-200 rounded-lg p-3 mb-3 space-y-2">
                    <div class="flex items-start gap-2">
                        <span class="flex-shrink-0 w-5 h-5 rounded-full bg-red-500 text-white text-[11px] font-bold flex items-center justify-center">1</span>
                        <p class="text-[12px] text-gray-700"><strong>Desligue o 4G / dados móveis</strong> do celular.</p>
                    </div>
                    <div class="flex items-start gap-2">
                        <span class="flex-shrink-0 w-5 h-5 rounded-full bg-emerald-500 text-white text-[11px] font-bold flex items-center justify-center">2</span>
                        <div>
                            <p class="text-[12px] text-gray-700">No Wi-Fi do celular, toque na rede:</p>
                            <span class="inline-flex items-center gap-1 mt-1 bg-emerald-50 text-emerald-800 font-bold text-[12px] px-2 py-1 rounded-md border border-emerald-300">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.858 15.355-5.858 21.213 0"/></svg>
                                TocantinsTransporteWifi
                            </span>
                        </div>
                    </div>
                </div>

                <div class="bg-amber-50 border border-amber-200 rounded-lg p-2.5 mb-4">
                    <p class="text-[11px] text-amber-800">
                        ⚠️ Se pagar com o 4G ligado, o sistema <strong>não reconhece seu aparelho</strong> e o acesso <strong>não é liberado</strong>.
                    </p>
                </div>

                <button id="no-wifi-retry-btn" onclick="retryWifiCheck()" class="connect-button w-full text-white font-bold py-3 rounded-xl shadow-md text-sm mb-2">
                    JÁ CONECTEI, VERIFICAR
                </button>
                <p class="text-[10px] text-gray-400">O pagamento só funciona pelo WiFi do ônibus</p>
            </div>
        </div>
    </div>

    <script>
    // 🎯 OPÇÃO B: O backend decide se o usuário está no WiFi do ônibus.
    // $on_hotspot vem do PortalController usando os mesmos sinais confiáveis
    // (URL do MikroTik, sessão verificada, IP do hotspot, usuário vinculado).
    // O JS só faz a verificação extra quando o usuário clica "Já conectei".
    window._ON_HOTSPOT = {{ ($on_hotspot ?? false) ? 'true' : 'false' }};

    function hasMikrotikContext() {
        const urlParams = new URLSearchParams(window.location.search);
        return urlParams.has('mac') || urlParams.has('mikrotik_mac') || urlParams.has('client_mac') ||
               urlParams.has('from_mikrotik') || urlParams.has('from_router') ||
               urlParams.has('captive') || urlParams.has('from_login');
    }
    function showNoWifiWarning() {
        document.getElementById('no-wifi-warning').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        window._noWifiBlocked = true;
    }
    function hideNoWifiWarning() {
        document.getElementById('no-wifi-warning').classList.add('hidden');
        document.body.style.overflow = 'auto';
        window._noWifiBlocked = false;
    }
    function retryWifiCheck() {
        const btn = document.getElementById('no-wifi-retry-btn');
        btn.innerHTML = '<span class="animate-pulse">Verificando...</span>';
        btn.disabled = true;
        probeHotspot(3000).then(function(reachable) {
            if (reachable) {
                hideNoWifiWarning();
                window.location.reload();
            } else {
                btn.innerHTML = 'WIFI NÃO DETECTADO!'; btn.classList.remove('connect-button'); btn.classList.add('bg-red-500');
                setTimeout(() => { btn.innerHTML = 'JÁ CONECTEI, VERIFICAR'; btn.classList.add('connect-button'); btn.classList.remove('bg-red-500'); btn.disabled = false; }, 2500);
            }
        });
    }
    document.addEventListener('DOMContentLoaded', function() {
        // Se o backend já confirmou que está no WiFi do ônibus, não mostra aviso.
        if (window._ON_HOTSPOT || hasMikrotikContext()) {
            window._noWifiBlocked = false;
            return;
        }

        // Se o servidor pediu redirect para o login do hotspot, só redireciona
        // se o roteador (10.5.50.1) responder — quem está no 4G não alcança
        // esse IP e ficaria travado em ERR_CONNECTION_TIMED_OUT.
        if (window._TRY_HOTSPOT_LOGIN) {
            probeHotspot(2500).then(function(reachable) {
                if (!reachable) {
                    showNoWifiWarning();
                    return;
                }
                // Está no WiFi do ônibus. Antes de redirecionar ao 10.5.50.1/login,
                // pergunta ao SERVIDOR se este aparelho já é conhecido e está ativo
                // (quem pagou está em bypass e o /login recusaria a conexão).
                // ⚠️ Não usar probe de internet genérico aqui: dava falso positivo
                // (ex.: dados móveis ainda ativos) e mostrava "já ativa" pra quem
                // nem pagou.
                checkDeviceActiveOnServer(6000).then(function(state) {
                    if (state === 'active') {
                        showAlreadyActiveNotice();
                    } else if (state === 'known') {
                        // MAC identificado mas sem acesso: segue o fluxo normal
                        // de pagamento (sem redirect, o portal já tem o MAC).
                    } else {
                        window.location.href = window._TRY_HOTSPOT_LOGIN;
                    }
                });
            });
            return;
        }

        // Backend não detectou hotspot → mostrar aviso direto (sem fetch lento/falso positivo)
        showNoWifiWarning();
    });

    // Pergunta ao servidor se este aparelho é conhecido e tem acesso ativo.
    // Retorna: 'active' (MAC conhecido + acesso ativo), 'known' (MAC conhecido
    // sem acesso) ou 'unknown' (servidor não identificou o MAC).
    // Fontes de MAC, em ordem: URL → localStorage (salvo em visita/pagamento
    // anterior neste navegador) → detect-device no servidor.
    function checkDeviceActiveOnServer(timeoutMs) {
        return new Promise(function(resolve) {
            let done = false;
            const finish = function(r) { if (!done) { done = true; resolve(r); } };
            setTimeout(function() { finish('unknown'); }, timeoutMs);

            const macRegex = /^([0-9A-F]{2}:){5}[0-9A-F]{2}$/i;
            const checkMacOnServer = function(mac) {
                return fetch('/api/user/check-mac/' + encodeURIComponent(mac), { cache: 'no-store' })
                    .then(function(r) { return r.json(); })
                    .then(function(info) {
                        finish(info && info.already_active ? 'active' : 'known');
                    });
            };

            const params = new URLSearchParams(window.location.search);
            let mac = params.get('mac') || params.get('mikrotik_mac') || params.get('client_mac') || '';
            if (!macRegex.test(mac)) {
                mac = localStorage.getItem('real_mac') || '';
            }
            if (macRegex.test(mac)) {
                checkMacOnServer(mac).catch(function() { finish('unknown'); });
                return;
            }

            const csrf = document.querySelector('meta[name="csrf-token"]');
            fetch('/api/detect-device', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf ? csrf.content : ''
                },
                cache: 'no-store',
                body: JSON.stringify({})
            })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    const detected = data && data.mac_address;
                    if (!detected || !macRegex.test(detected)) { finish('unknown'); return; }
                    try { localStorage.setItem('real_mac', detected.toUpperCase()); } catch (e) {}
                    return checkMacOnServer(detected);
                })
                .catch(function() { finish('unknown'); });
        });
    }

    // Aviso amigável para quem já está liberado (pagamento ou liberação de 3 min):
    // substitui o redirect ao 10.5.50.1/login, que recusa conexão para quem tem bypass.
    function showAlreadyActiveNotice() {
        if (document.getElementById('already-active-notice')) return;
        const el = document.createElement('div');
        el.id = 'already-active-notice';
        el.className = 'fixed inset-0 bg-black/70 z-[70] flex items-center justify-center p-5';
        el.innerHTML =
            '<div class="bg-white rounded-2xl max-w-sm w-full p-6 text-center shadow-2xl">' +
                '<div class="w-16 h-16 rounded-full bg-green-100 flex items-center justify-center mx-auto mb-3">' +
                    '<svg class="w-9 h-9 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>' +
                '</div>' +
                '<h2 class="text-lg font-extrabold text-gray-900">Sua internet já está ativa!</h2>' +
                '<p class="text-sm text-gray-600 mt-2 leading-relaxed">Seu aparelho já está liberado no WiFi do ônibus. Pode navegar normalmente.</p>' +
                '<p class="text-[11px] text-gray-400 mt-3 leading-relaxed">Se você usou a liberação rápida de 3 minutos para pagar, o acesso completo libera sozinho assim que o PIX for confirmado.</p>' +
                '<button onclick="document.getElementById(\'already-active-notice\').remove()" class="mt-4 w-full connect-button text-white font-extrabold py-3 rounded-xl text-sm">COMEÇAR A NAVEGAR</button>' +
            '</div>';
        document.body.appendChild(el);
    }
    window.showAlreadyActiveNotice = showAlreadyActiveNotice;
    window.checkDeviceActiveOnServer = checkDeviceActiveOnServer;

    // Testa se está no WiFi do ônibus.
    // 1º: pergunta ao servidor (HTTPS, sempre funciona) se o IP público do
    //     visitante é de um roteador de ônibus.
    // 2º fallback: probe local ao 10.5.50.1 (pode ser bloqueado por mixed
    //     content em HTTPS, por isso não é mais o método principal).
    function probeHotspot(timeoutMs) {
        return new Promise(function(resolve) {
            let done = false;
            const finish = function(result) {
                if (!done) { done = true; resolve(result); }
            };

            const controller = new AbortController();
            const abortTimer = setTimeout(function() { controller.abort(); }, timeoutMs);

            fetch('/api/connection-check', { headers: { 'Accept': 'application/json' }, signal: controller.signal, cache: 'no-store' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    clearTimeout(abortTimer);
                    if (data && data.on_hotspot) {
                        finish(true);
                    } else {
                        probeHotspotLocal(1500).then(finish);
                    }
                })
                .catch(function() {
                    clearTimeout(abortTimer);
                    probeHotspotLocal(1500).then(finish);
                });

            setTimeout(function() { finish(false); }, timeoutMs + 1600);
        });
    }

    function probeHotspotLocal(timeoutMs) {
        return new Promise(function(resolve) {
            let done = false;
            const finish = function(result) {
                if (!done) { done = true; resolve(result); }
            };
            const img = new Image();
            img.onload = function() { finish(true); };
            img.onerror = function() { finish(false); };
            img.src = 'http://10.5.50.1/favicon.ico?t=' + Date.now();
            setTimeout(function() { finish(false); }, timeoutMs);
        });
    }
    </script>

    <!-- Loading Overlay -->
    <div id="loading-overlay" class="fixed inset-0 bg-black/50 backdrop-blur-sm z-50 hidden">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl p-6 sm:p-8 text-center shadow-xl w-full max-w-xs animate-slide-up">
                <div class="relative w-14 h-14 mx-auto mb-4">
                    <div id="loading-spinner" class="animate-spin rounded-full h-14 w-14 border-[3px] border-gray-200 border-t-brand-600"></div>
                    <div id="loading-scan-icon" class="hidden absolute inset-0 flex items-center justify-center">
                        <svg class="w-7 h-7 text-brand-600 animate-pulse" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                        </svg>
                    </div>
                </div>
                <p id="loading-title" class="text-gray-800 font-bold text-sm">Processando...</p>
                <p id="loading-subtitle" class="text-gray-500 text-xs mt-1.5 leading-relaxed">Por favor, aguarde</p>

                <div id="loading-progress-wrap" class="hidden mt-4">
                    <div class="flex justify-center gap-2 mb-2">
                        <span id="loading-dot-1" class="w-2 h-2 rounded-full bg-gray-200 transition-colors"></span>
                        <span id="loading-dot-2" class="w-2 h-2 rounded-full bg-gray-200 transition-colors"></span>
                        <span id="loading-dot-3" class="w-2 h-2 rounded-full bg-gray-200 transition-colors"></span>
                        <span id="loading-dot-4" class="w-2 h-2 rounded-full bg-gray-200 transition-colors"></span>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div id="loading-progress-bar" class="h-full bg-gradient-to-r from-brand-600 to-brand-500 transition-all duration-500 rounded-full" style="width:25%"></div>
                    </div>
                    <p class="text-[10px] text-brand-600 font-semibold mt-2 animate-pulse">Analisando conexão WiFi...</p>
                </div>

                <p id="loading-hint" class="text-gray-400 text-[10px] mt-3 hidden leading-relaxed">Se demorar mais de 30s, desligue o 4G e tente de novo.</p>
            </div>
        </div>
    </div>

    <div class="min-h-screen flex flex-col">

        <!-- Hero: marca + como funciona -->
        <header class="hero-gradient relative overflow-hidden text-white px-4 pt-4 pb-9 sm:pt-5 sm:pb-11">
            <div class="pointer-events-none absolute -top-16 -right-10 w-48 h-48 rounded-full bg-white/10 blur-2xl"></div>

            <div class="relative max-w-lg mx-auto">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-white/15 ring-1 ring-white/25 flex items-center justify-center flex-shrink-0">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.858 15.355-5.858 21.213 0"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-lg font-black leading-tight tracking-tight">WiFi Tocantins</p>
                        <p class="text-[13px] text-white/85 leading-tight">Internet no ônibus via Starlink</p>
                    </div>
                </div>

                <!-- Como funciona: o que o passageiro faz NESTA tela -->
                <ol class="mt-4 grid grid-cols-3 gap-1.5" aria-label="Como funciona">
                    <li class="flex items-center gap-1.5 rounded-xl bg-white/10 ring-1 ring-white/15 px-2 py-2">
                        <span aria-hidden="true" class="flex w-6 h-6 flex-shrink-0 items-center justify-center rounded-full bg-white text-green-dark text-[13px] font-black">1</span>
                        <span class="text-[12px] font-bold leading-tight">Escolha o plano</span>
                    </li>
                    <li class="flex items-center gap-1.5 rounded-xl bg-white/10 ring-1 ring-white/15 px-2 py-2">
                        <span aria-hidden="true" class="flex w-6 h-6 flex-shrink-0 items-center justify-center rounded-full bg-white text-green-dark text-[13px] font-black">2</span>
                        <span class="text-[12px] font-bold leading-tight">Pague no PIX</span>
                    </li>
                    <li class="flex items-center gap-1.5 rounded-xl bg-white/10 ring-1 ring-white/15 px-2 py-2">
                        <span aria-hidden="true" class="flex w-6 h-6 flex-shrink-0 items-center justify-center rounded-full bg-lime-300 text-green-dark text-[13px] font-black">3</span>
                        <span class="text-[12px] font-bold leading-tight">Pronto, navegue</span>
                    </li>
                </ol>
            </div>
        </header>

        <!-- Conteudo Principal (pb-24: o botão flutuante do chat não cobre o fim da página) -->
        <main class="relative flex-1 px-4 -mt-6 pb-24">
            <div class="max-w-lg mx-auto space-y-3 sm:space-y-4">

                <!-- Status de conexão: dinâmico — re-testa o WiFi do ônibus a cada 5s -->
                @if(!($on_hotspot ?? false))
                <section id="wifi-status-card" class="bg-amber-50 border border-amber-300 rounded-2xl px-4 py-3 shadow-lg shadow-amber-900/5 animate-slide-up transition-colors duration-300">
                    <div class="flex items-start gap-3">
                        <div id="wifi-status-icon" class="w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center flex-shrink-0 shadow-sm transition-colors duration-300">
                            <svg id="wifi-status-icon-off" class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 5.636a9 9 0 010 12.728M15.536 8.464a5 5 0 010 7.072M6 18L18 6"/></svg>
                            <svg id="wifi-status-icon-on" class="w-5 h-5 text-white hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p id="wifi-status-title" class="text-amber-900 font-extrabold text-[15px] leading-tight">Conecte no WiFi do ônibus para pagar</p>
                            <p id="wifi-status-text" class="text-amber-800 text-[13px] mt-1 leading-snug">Entre na rede <strong>"TocantinsTransporteWifi"</strong> e depois pague. <button type="button" onclick="showNoWifiWarning()" class="font-bold underline underline-offset-2">Ver como</button></p>
                        </div>
                    </div>
                </section>
                <script>
                (function() {
                    let wifiOk = false;
                    function setWifiCardState(connected) {
                        if (connected === wifiOk) return;
                        wifiOk = connected;
                        const card = document.getElementById('wifi-status-card');
                        const icon = document.getElementById('wifi-status-icon');
                        const title = document.getElementById('wifi-status-title');
                        const text = document.getElementById('wifi-status-text');
                        const iconOff = document.getElementById('wifi-status-icon-off');
                        const iconOn = document.getElementById('wifi-status-icon-on');
                        if (!card) return;
                        if (connected) {
                            card.className = 'bg-green-pale border border-green/40 rounded-2xl px-4 py-3 shadow-lg shadow-green-900/5 transition-colors duration-300';
                            icon.className = 'w-10 h-10 rounded-xl bg-green flex items-center justify-center flex-shrink-0 shadow-sm transition-colors duration-300';
                            title.className = 'text-green-dark font-extrabold text-[15px] leading-tight';
                            title.textContent = '✓ Conectado ao WiFi do ônibus';
                            text.className = 'text-green-dark/80 text-[13px] mt-1 leading-snug';
                            text.innerHTML = 'Tudo certo! Escolha o plano abaixo e pague no PIX.';
                            iconOff.classList.add('hidden');
                            iconOn.classList.remove('hidden');
                        } else {
                            card.className = 'bg-amber-50 border border-amber-300 rounded-2xl px-4 py-3 shadow-lg shadow-amber-900/5 transition-colors duration-300';
                            icon.className = 'w-10 h-10 rounded-xl bg-amber-400 flex items-center justify-center flex-shrink-0 shadow-sm transition-colors duration-300';
                            title.className = 'text-amber-900 font-extrabold text-[15px] leading-tight';
                            title.textContent = 'Conecte no WiFi do ônibus para pagar';
                            text.className = 'text-amber-800 text-[13px] mt-1 leading-snug';
                            text.innerHTML = 'Entre na rede <strong>"TocantinsTransporteWifi"</strong> e depois pague. <button type="button" onclick="showNoWifiWarning()" class="font-bold underline underline-offset-2">Ver como</button>';
                            iconOn.classList.add('hidden');
                            iconOff.classList.remove('hidden');
                        }
                    }
                    function monitorWifi() {
                        if (typeof probeHotspot !== 'function') return;
                        probeHotspot(2000).then(function(reachable) {
                            setWifiCardState(reachable);
                            // Quando conectar, o usuário some do aviso; continua monitorando
                            // para voltar ao amarelo se cair do WiFi.
                        });
                    }
                    // Primeiro teste rápido + monitoramento a cada 5s
                    setTimeout(monitorWifi, 1500);
                    setInterval(monitorWifi, 5000);
                })();
                </script>
                @endif

                <!-- Card de Planos -->
                <section class="glass-card rounded-3xl overflow-hidden animate-slide-up">
                    <div class="px-4 pt-4 pb-3 sm:px-5 sm:pt-5">
                        <h2 class="text-lg font-black text-ink tracking-tight leading-tight mb-3">Escolha seu plano</h2>

                        <div id="interval-access-status" hidden class="mb-3 rounded-xl border border-green/30 bg-green-pale px-3 py-2 text-sm" aria-live="polite">
                            <p data-interval-message></p>
                            <button type="button" hidden class="mt-2 font-bold text-green-dark underline">Verificar meu plano</button>
                        </div>
                        <div class="space-y-3" id="wifi-plan-options" role="radiogroup" aria-label="Escolha seu plano de WiFi">
                            @if($plan_short_enabled ?? true)
                            <!-- Plano 1 hora (compacto) -->
                            <button type="button" data-plan-option data-plan-price="{{ $wifi_price_short ?? 5.99 }}" data-plan-duration="{{ $session_duration_short ?? 1 }}" data-plan-name="{{ $session_duration_short ?? 1 }} hora(s) de acesso" data-plan-suffix="/ hora"
                                class="wifi-plan-card flex w-full items-center gap-3 border-2 border-gray-200 bg-white px-4 py-3.5 text-left hover:border-green/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-green/30">
                                <span data-plan-radio class="h-5 w-5 rounded-full border-2 border-gray-300 bg-white flex-shrink-0 transition-all duration-200"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="text-base font-extrabold text-ink leading-tight">{{ $session_duration_short ?? 1 }} hora de internet</p>
                                    <p class="text-[13px] text-gray-600 leading-tight mt-1">Para uma parada rápida</p>
                                </div>
                                <p data-plan-price-display class="text-xl font-black text-ink tracking-tight whitespace-nowrap">R${{ number_format($wifi_price_short ?? 5.99, 2, ',', '.') }}</p>
                            </button>
                            @endif

                            @if($plan_full_enabled ?? true)
                            <!-- Plano Viagem Completa (PRÉ-SELECIONADO) -->
                            <div class="relative pt-3">
                                <div class="plan-tag left-4 price-pill">⭐ Mais escolhido</div>
                                <button type="button" data-plan-option data-plan-price="{{ $wifi_price_full ?? 6.99 }}" data-plan-duration="{{ $session_duration ?? 12 }}" data-plan-name="Viagem completa" data-plan-suffix="/ {{ $session_duration ?? 12 }} horas" data-plan-default="true"
                                    class="wifi-plan-card plan-card-selected relative flex w-full items-center gap-3 border-2 px-4 pt-5 pb-4 text-left hover:shadow-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-green/30">
                                    <span data-plan-radio class="h-5 w-5 rounded-full border-[4px] border-green bg-white flex-shrink-0 transition-all duration-200"></span>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-[17px] font-black text-ink leading-tight tracking-tight">Viagem completa</p>
                                        <p class="text-[13px] text-green-dark font-semibold leading-snug mt-1">{{ $session_duration ?? 12 }} horas de internet</p>
                                    </div>
                                    <div class="text-right flex-shrink-0">
                                        <p class="text-[12px] text-gray-500 leading-none">de <span class="line-through">R${{ number_format($original_price ?? 9.99, 2, ',', '.') }}</span></p>
                                        <p data-plan-price-display class="text-[26px] font-black text-green-dark tracking-tight leading-none mt-1 whitespace-nowrap">R${{ number_format($wifi_price_full ?? 6.99, 2, ',', '.') }}</p>
                                    </div>
                                </button>
                            </div>
                            @endif
                            @include('portal.partials.interval-plan')
                        </div>
                    </div>

                    <div class="px-4 pt-1 pb-4 sm:px-5 sm:pb-5">
                        <!-- CTA primeiro: usuário vê plano + botão sem rolar -->
                        <div id="plan-cta-wrapper">
                            <button id="connect-btn"
                                class="connect-button btn-pulse w-full text-white font-black py-3 rounded-2xl text-base flex flex-col items-center justify-center gap-0.5 shadow-lg lg:hidden">
                                <span>CONECTAR AGORA</span>
                                <span class="text-[11px] font-semibold text-white/90">Pague via PIX e navegue na hora · rápido e fácil</span>
                            </button>
                            <button id="connect-btn-desktop"
                                class="connect-button btn-pulse w-full text-white font-black py-3 rounded-2xl text-base flex flex-col items-center justify-center gap-0.5 shadow-lg hidden lg:flex">
                                <span>CONECTAR AGORA</span>
                                <span class="text-[11px] font-semibold text-white/90">Pague via PIX e navegue na hora · rápido e fácil</span>
                            </button>
                        </div>

                        <!-- Confiança: uma linha só, sem competir com o botão -->
                        <p class="mt-3 flex flex-wrap items-center justify-center gap-x-3 gap-y-1 text-[13px] font-semibold text-gray-600 leading-tight">
                            <span>🔒 Pagamento seguro</span>
                            <span>💬 Ajuda no WhatsApp</span>
                        </p>

                        <!-- Apps que funcionam (visual, sem texto extra) -->
                        <div class="flex justify-center items-center gap-3 mt-3" aria-label="Funciona com Instagram, WhatsApp, YouTube, Facebook, TikTok e Netflix">
                            <div class="app-icon w-8 h-8 rounded-xl flex items-center justify-center" style="background: linear-gradient(135deg, #833AB4, #E1306C, #F77737);" title="Instagram">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M7.8 2h8.4C19.4 2 22 4.6 22 7.8v8.4a5.8 5.8 0 01-5.8 5.8H7.8C4.6 22 2 19.4 2 16.2V7.8A5.8 5.8 0 017.8 2m-.2 2A3.6 3.6 0 004 7.6v8.8C4 18.39 5.61 20 7.6 20h8.8a3.6 3.6 0 003.6-3.6V7.6C20 5.61 18.39 4 16.4 4H7.6m9.65 1.5a1.25 1.25 0 110 2.5 1.25 1.25 0 010-2.5M12 7a5 5 0 110 10 5 5 0 010-10m0 2a3 3 0 100 6 3 3 0 000-6z"/></svg>
                            </div>
                            <div class="app-icon w-8 h-8 rounded-xl bg-[#25D366] flex items-center justify-center" title="WhatsApp">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                            </div>
                            <div class="app-icon w-8 h-8 rounded-xl bg-[#FF0000] flex items-center justify-center" title="YouTube">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg>
                            </div>
                            <div class="app-icon w-8 h-8 rounded-xl bg-[#1877F2] flex items-center justify-center" title="Facebook">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg>
                            </div>
                            <div class="app-icon w-8 h-8 rounded-xl bg-black flex items-center justify-center" title="TikTok">
                                <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M19.59 6.69a4.83 4.83 0 01-3.77-4.25V2h-3.45v13.67a2.89 2.89 0 01-2.88 2.5 2.89 2.89 0 01-2.89-2.89 2.89 2.89 0 012.89-2.89c.28 0 .54.04.79.1v-3.5a6.37 6.37 0 00-.79-.05A6.34 6.34 0 003.15 15.2a6.34 6.34 0 0010.86 4.46V13a8.28 8.28 0 005.58 2.17V11.7a4.83 4.83 0 01-3.77-1.24V6.69h3.77z"/></svg>
                            </div>
                            <div class="app-icon w-8 h-8 rounded-xl bg-black flex items-center justify-center" title="Netflix">
                                <svg class="w-3.5 h-3.5" fill="#E50914" viewBox="0 0 24 24"><path d="M5.398 0v.006c3.028 8.556 5.37 15.175 8.348 23.596 2.344.058 4.85.398 4.854.398-2.8-7.924-5.923-16.747-8.487-24zm8.489 0v9.63L18.6 22.951c-.043-7.86-.004-15.913.002-22.95zM5.398 1.05V24c1.873-.225 2.81-.312 4.715-.398v-9.22z"/></svg>
                            </div>
                        </div>
                    </div>
                </section>

                <a href="{{ route('voucher.activate') }}{{ request()->has('mac') ? '?source=mikrotik&mac=' . request('mac') . '&ip=' . request('ip') : '' }}"
                   class="glass-card flex items-center gap-3 rounded-2xl px-4 py-3 animate-slide-up-delay group/voucher transition-transform active:scale-[0.99]">
                    <span class="inline-flex w-10 h-10 flex-shrink-0 items-center justify-center rounded-xl bg-green-pale text-green group-hover/voucher:bg-green group-hover/voucher:text-white transition-colors">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z"/></svg>
                    </span>
                    <span class="text-left flex-1 min-w-0">
                        <span class="block text-[14px] font-extrabold text-ink leading-tight">Ative seu voucher</span>
                        <span class="block text-[11px] text-muted leading-tight mt-0.5">Acesso com código</span>
                    </span>
                    <svg class="w-4 h-4 text-gray-300 group-hover/voucher:text-green transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M9 5l7 7-7 7"/></svg>
                </a>

                <!-- Ajuda compacta -->
                <details class="group glass-card rounded-2xl animate-slide-up-delay overflow-hidden">
                    <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3.5">
                        <span class="inline-flex w-10 h-10 flex-shrink-0 items-center justify-center rounded-xl bg-blue-pale text-blue">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <span class="flex-1 text-[15px] font-extrabold text-ink">Precisa de ajuda?</span>
                        <svg class="w-5 h-5 text-gray-400 transition-transform group-open:rotate-180" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </summary>
                    <div class="px-2 pb-2 space-y-1">
                        <button onclick="openVideoTutorial()" class="flex items-center gap-3 rounded-xl px-2.5 py-3 w-full hover:bg-gray-50 transition-colors group/help">
                            <span class="inline-flex w-9 h-9 flex-shrink-0 items-center justify-center rounded-lg bg-red-pale text-red">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                            </span>
                            <span class="text-left flex-1">
                                <span class="block text-[14px] font-bold text-ink">Como se conectar?</span>
                                <span class="block text-[12px] text-gray-600">Vídeo passo a passo</span>
                            </span>
                            <svg class="w-4 h-4 text-gray-300 group-hover/help:text-red" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>

                        <button type="button" onclick="openRecoveryModal()" class="flex items-center gap-3 rounded-xl px-2.5 py-3 w-full hover:bg-gray-50 transition-colors group/help">
                            <span class="inline-flex w-9 h-9 flex-shrink-0 items-center justify-center rounded-lg bg-amber-50 text-amber-600">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                            </span>
                            <span class="text-left flex-1">
                                <span class="block text-[14px] font-bold text-ink">Já paguei mas sem internet?</span>
                                <span class="block text-[12px] text-gray-600">Recuperar com telefone</span>
                            </span>
                            <svg class="w-4 h-4 text-gray-300 group-hover/help:text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </div>
                </details>

            </div>
        </main>

        <!-- Footer -->
        <footer class="text-center py-5 px-4">
            <div class="flex items-center justify-center gap-2 mb-1">
                <svg class="w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0"/></svg>
                <p class="text-[11px] text-gray-400 font-semibold">WiFi Tocantins Express</p>
            </div>
            <p class="text-[10px] text-gray-400/80">Internet de alta velocidade via Starlink</p>
        </footer>
    </div>

    <!-- Modal Vídeo Tutorial -->
    <div id="video-tutorial-modal" class="fixed inset-0 bg-black/90 z-50 hidden flex-col lg:items-center lg:justify-center">
        <div class="hidden lg:flex flex-col w-full max-w-2xl bg-gray-900 rounded-2xl overflow-hidden shadow-2xl">
            <div class="flex items-center justify-between px-5 py-3 bg-gray-800">
                <p class="text-white font-semibold text-sm">Como se conectar ao WiFi</p>
                <button onclick="closeVideoTutorial()" class="flex items-center gap-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-bold px-4 py-2 rounded-full transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    FECHAR
                </button>
            </div>
            <video id="tutorial-video-desktop" class="w-full max-h-[70vh] object-contain bg-black" controls playsinline preload="none">
                <source src="{{ asset('videos/CaptivePortalVideo.mp4') }}" type="video/mp4">
            </video>
        </div>
        <div class="flex flex-col flex-1 w-full lg:hidden">
            <div class="flex items-center justify-between px-4 py-3 bg-gray-900 flex-shrink-0">
                <p class="text-white font-semibold text-sm">Como se conectar ao WiFi</p>
                <button onclick="closeVideoTutorial()" class="flex items-center gap-1.5 bg-red-600 hover:bg-red-700 text-white text-sm font-bold px-4 py-2 rounded-full transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M6 18L18 6M6 6l12 12"/></svg>
                    FECHAR
                </button>
            </div>
            <div class="flex-1 flex items-center justify-center bg-black">
                <video id="tutorial-video" class="w-full h-full object-contain" controls playsinline preload="none">
                    <source src="{{ asset('videos/CaptivePortalVideo.mp4') }}" type="video/mp4">
                </video>
            </div>
        </div>
    </div>

    <script>
    function openVideoTutorial() {
        const modal = document.getElementById('video-tutorial-modal');
        modal.classList.remove('hidden'); modal.classList.add('flex');
        document.body.style.overflow = 'hidden';
        const isDesktop = window.innerWidth >= 1024;
        const video = document.getElementById(isDesktop ? 'tutorial-video-desktop' : 'tutorial-video');
        if (video) { video.currentTime = 0; video.play(); }
    }
    function closeVideoTutorial() {
        const modal = document.getElementById('video-tutorial-modal');
        modal.classList.add('hidden'); modal.classList.remove('flex');
        document.body.style.overflow = 'auto';
        ['tutorial-video', 'tutorial-video-desktop'].forEach(id => { const v = document.getElementById(id); if (v) v.pause(); });
    }
    document.getElementById('video-tutorial-modal').addEventListener('click', function(e) { if (e.target === this) closeVideoTutorial(); });
    </script>

    <!-- Oferta de desconto: proposta do parceiro OU pagar normal -->
    @if($discount_offer_enabled ?? false)
    <div id="discount-choice-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[60] hidden">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl w-full max-w-sm animate-slide-up shadow-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-emerald-600 to-emerald-700 px-5 py-4 text-center">
                    <p class="text-white font-extrabold text-base">Quer pagar menos?</p>
                    <p class="text-emerald-100 text-xs mt-1">Veja uma proposta e ganhe desconto no plano</p>
                </div>

                <div class="p-5 space-y-3">
                    <button id="btn-skip-discount" onclick="skipDiscountOffer()" class="w-full bg-gray-100 hover:bg-gray-200 text-gray-700 rounded-2xl p-4 text-left transition-all active:scale-[0.98] border border-gray-200">
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 bg-gray-200 rounded-xl flex items-center justify-center flex-shrink-0">
                                <svg class="w-5 h-5 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/></svg>
                            </div>
                            <div>
                                <p class="font-bold text-sm text-gray-800">Pular e pagar normal</p>
                                <p class="text-gray-500 text-xs mt-0.5">Sem proposta, sem desconto</p>
                                <p class="text-gray-600 text-xs font-bold mt-1" id="discount-choice-normal-price"></p>
                            </div>
                        </div>
                    </button>

                    <button id="btn-see-offer" onclick="startDiscountOffer()" class="w-full bg-gradient-to-r from-purple-500 to-purple-600 hover:from-purple-600 hover:to-purple-700 text-white rounded-2xl p-4 text-left transition-all active:scale-[0.98] shadow-lg relative overflow-hidden">
                        <div class="absolute top-2 right-3 bg-yellow-400 text-yellow-900 text-[9px] font-extrabold px-2 py-0.5 rounded-full">ECONOMIZE</div>
                        <div class="flex items-center gap-3">
                            <div class="w-11 h-11 bg-white/20 rounded-xl flex items-center justify-center flex-shrink-0">
                                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a5 5 0 00-10 0v2M5 9h14l1 12H4L5 9z"/></svg>
                            </div>
                            <div class="pr-16">
                                <p class="font-extrabold text-sm leading-snug">Tenha agora R${{ number_format($video_discount_amount ?? 1, 2, ',', '.') }} de desconto</p>
                                <p class="text-purple-100 text-xs mt-0.5">Veja uma proposta de crédito no seu nome</p>
                            </div>
                        </div>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- CPF e consentimento -->
    <div id="discount-consent-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[70] hidden">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl w-full max-w-sm animate-slide-up shadow-2xl overflow-hidden max-h-[94vh] overflow-y-auto">
                <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-5 py-4 text-center">
                    <p class="text-white font-extrabold text-base">Seus dados</p>
                    <p class="text-purple-100 text-xs mt-1">Para montar a proposta no seu nome</p>
                </div>

                <div class="p-5">
                    <label for="discount-phone" class="block text-xs font-bold text-gray-700 mb-1.5">Telefone</label>
                    <input type="tel" id="discount-phone" maxlength="16" inputmode="numeric"
                        class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:border-purple-500 text-base font-medium">

                    <label for="discount-cpf" class="block text-xs font-bold text-gray-700 mb-1.5 mt-4">CPF</label>
                    <input type="tel" id="discount-cpf" placeholder="000.000.000-00" maxlength="14" inputmode="numeric"
                        class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:outline-none focus:border-purple-500 text-base font-medium tracking-wide">

                    <label class="flex items-start gap-2.5 mt-4 cursor-pointer">
                        <input type="checkbox" id="discount-consent" class="mt-0.5 w-5 h-5 flex-shrink-0 accent-purple-600">
                        <span class="text-[12px] text-gray-600 leading-snug">
                            Autorizo o envio do meu CPF para a <strong>Cash Cash</strong> consultar uma proposta de crédito no meu nome.
                        </span>
                    </label>

                    <div id="discount-consent-error" class="hidden mt-3 bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-xs"></div>

                    <button id="discount-consent-submit" onclick="submitDiscountConsent()"
                        class="mt-4 w-full bg-gradient-to-r from-purple-600 to-purple-700 hover:from-purple-700 hover:to-purple-800 text-white font-bold py-3.5 rounded-xl shadow-md text-sm transition-all active:scale-[0.98] disabled:opacity-60">
                        Ver proposta e ganhar desconto
                    </button>

                    <button onclick="skipDiscountOffer()" class="mt-2 w-full text-[13px] font-semibold text-gray-500 hover:text-gray-700 py-2 underline">
                        Pular e pagar normal
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Aguardando o passageiro ver a proposta -->
    <div id="discount-waiting-modal" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-[70] hidden">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl w-full max-w-sm animate-slide-up shadow-2xl overflow-hidden">
                <div class="bg-gradient-to-r from-purple-600 to-purple-700 px-5 py-4 text-center">
                    <p class="text-white font-extrabold text-base">Sua proposta está aberta</p>
                    <p class="text-purple-100 text-xs mt-1">Numa aba nova, aqui do lado</p>
                </div>

                <div class="p-5">
                    <div class="rounded-xl border-2 border-purple-200 bg-purple-50 p-4 text-center">
                        <p class="text-[13px] font-bold text-purple-900">Falta para liberar o desconto</p>
                        <p id="discount-waiting-timer" class="text-4xl font-black text-purple-700 leading-none my-2 tabular-nums">--</p>
                        <div class="h-2.5 w-full rounded-full bg-gray-200 overflow-hidden">
                            <div id="discount-waiting-bar" class="h-full bg-purple-500 rounded-full transition-all duration-500" style="width:0%"></div>
                        </div>
                    </div>

                    <p id="discount-waiting-hint" class="text-[13px] text-gray-600 leading-snug mt-3 text-center">
                        Volte para a aba da proposta e veja o que a Cash Cash preparou. O tempo só conta enquanto você está lá.
                    </p>

                    <div id="discount-waiting-error" class="hidden mt-3 bg-amber-50 border border-amber-200 text-amber-800 px-3 py-2 rounded-lg text-xs"></div>

                    <button id="discount-reopen-btn" onclick="reopenDiscountOffer()"
                        class="mt-4 w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-3.5 rounded-xl shadow-md text-sm transition-all active:scale-[0.98]">
                        Abrir a proposta
                    </button>

                    <button onclick="skipDiscountOffer()" class="mt-2 w-full text-[13px] font-semibold text-gray-500 hover:text-gray-700 py-2 underline">
                        Pular e pagar normal
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        const DISCOUNT_AMOUNT = {{ $video_discount_amount ?? 1 }};
        const MIN_SECONDS = {{ $cashcash_min_seconds ?? 20 }};

        let decided = false;              // já pulou ou já ganhou o desconto
        let pendingConnectAction = null;  // callback original do fluxo de pagamento
        let lead = null;                  // { lead_id } da proposta em andamento
        let confirming = false;

        window.DISCOUNT_APPLIED = false;
        window.DISCOUNT_AMOUNT = 0;

        function el(id) { return document.getElementById(id); }
        function show(id) { el(id)?.classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
        function hide(id) { el(id)?.classList.add('hidden'); }
        function hideAll() {
            ['discount-choice-modal', 'discount-consent-modal', 'discount-waiting-modal'].forEach(hide);
            document.body.style.overflow = 'auto';
        }

        /** Segue o fluxo de pagamento original, intocado. */
        function continueToPayment() {
            hideAll();
            if (pendingConnectAction) {
                const go = pendingConnectAction;
                pendingConnectAction = null;
                go();
            }
        }

        /**
         * Intercepta o clique em pagar. Se a oferta ainda não foi decidida,
         * mostra a escolha; senão segue direto.
         */
        window.DISCOUNT_OFFER_INTERCEPT = function(originalCallback) {
            if (decided) { originalCallback(); return; }
            pendingConnectAction = originalCallback;

            el('loading-overlay')?.classList.add('hidden');

            const price = window.WIFI_PRICE || 6.99;
            const normalEl = el('discount-choice-normal-price');
            if (normalEl) normalEl.textContent = 'Pagar R$' + price.toFixed(2).replace('.', ',');

            show('discount-choice-modal');
        };

        /** Pulou: preço normal, fluxo normal. */
        window.skipDiscountOffer = function() {
            decided = true;
            continueToPayment();
        };

        /** Escolheu ver a proposta: pede CPF e consentimento. */
        window.startDiscountOffer = function() {
            hide('discount-choice-modal');

            const phoneField = el('discount-phone');
            if (phoneField && !phoneField.value) {
                phoneField.value = el('user_phone')?.value || '';
            }
            el('discount-consent-error')?.classList.add('hidden');

            show('discount-consent-modal');
        };

        /** Envia CPF + consentimento e abre a proposta numa aba nova. */
        window.submitDiscountConsent = async function() {
            const errorBox = el('discount-consent-error');
            const button = el('discount-consent-submit');
            const cpf = (el('discount-cpf')?.value || '').replace(/\D/g, '');
            const phone = (el('discount-phone')?.value || '').replace(/\D/g, '');

            function fail(message) {
                if (errorBox) { errorBox.textContent = message; errorBox.classList.remove('hidden'); }
                if (button) { button.disabled = false; button.textContent = 'Ver proposta e ganhar desconto'; }
            }

            if (phone.length < 10 || phone.length > 11) return fail('Digite o telefone com DDD.');
            if (cpf.length !== 11) return fail('Digite os 11 números do CPF.');
            if (!el('discount-consent')?.checked) return fail('Marque a autorização para continuar.');

            if (button) { button.disabled = true; button.textContent = 'Carregando proposta...'; }
            errorBox?.classList.add('hidden');

            // Cadastra o telefone ANTES de sair do portal: o passageiro vai para
            // o app do parceiro e volta com a página recarregada.
            try {
                if (typeof window.DISCOUNT_REGISTER_PHONE === 'function') {
                    await window.DISCOUNT_REGISTER_PHONE(phone);
                }
            } catch (e) {
                return fail(e?.message === 'sem-mac'
                    ? 'Não identificamos seu aparelho. Reconecte no WiFi do ônibus e tente de novo.'
                    : 'Não conseguimos salvar seu telefone. Tente de novo.');
            }

            let data;
            try {
                const response = await fetch('/api/cashcash/handoff', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({
                        cpf: cpf,
                        phone: phone || null,
                        mac_address: window.wifiPortal?.deviceMac || null,
                        user_id: window.wifiPortal?.currentUserId || null,
                    }),
                });
                data = await response.json();
            } catch (e) {
                return fail('Sem conexão para carregar a proposta. Siga com o pagamento normal.');
            }

            if (!data || !data.success) {
                if (data?.fallback_url) {           // plano B do guia: só em 502/503
                    window.location.replace(data.fallback_url);
                    return;
                }
                return fail(data?.message || 'Não conseguimos carregar a proposta agora.');
            }

            // Mesma aba, como o guia pede. O portal recarrega quando ele voltar
            // e retoma pelo lead pendente — por isso nada precisa ficar na memória.
            window.location.replace(data.url);      // a url nunca é logada
        };

        /**
         * Reabre a proposta. O link do parceiro é de uso único e expira em 5 min,
         * então o servidor gera um handoff novo.
         */
        window.reopenDiscountOffer = async function() {
            if (!lead) return;
            const botao = el('discount-reopen-btn');
            const box = el('discount-waiting-error');
            if (botao) { botao.disabled = true; botao.textContent = 'Abrindo...'; }

            try {
                const response = await fetch('/api/cashcash/reopen', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ lead_id: lead.lead_id }),
                });
                const data = await response.json();

                if (data?.success && data.url) {
                    window.location.replace(data.url);
                    return;
                }
                if (box) {
                    box.textContent = data?.message || 'Não conseguimos reabrir a proposta.';
                    box.classList.remove('hidden');
                }
            } catch (e) {
                if (box) {
                    box.textContent = 'Sem conexão para reabrir a proposta.';
                    box.classList.remove('hidden');
                }
            }

            if (botao) { botao.disabled = false; botao.textContent = 'Abrir a proposta'; }
        };

        function renderWaiting(restam) {
            const left = Math.max(0, restam == null ? MIN_SECONDS : restam);
            const timer = el('discount-waiting-timer');
            const bar = el('discount-waiting-bar');
            if (timer) timer.textContent = left + 's';
            if (bar) bar.style.width = ((MIN_SECONDS - left) / MIN_SECONDS * 100) + '%';
        }

        /**
         * Pergunta ao servidor se o tempo na proposta já dá direito ao desconto.
         * Quem mede é o servidor, entre a abertura do link e a volta ao portal.
         */
        async function tryConfirm() {
            if (!lead || confirming || decided) return null;
            confirming = true;

            let data;
            try {
                const response = await fetch('/api/cashcash/confirm', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                    },
                    body: JSON.stringify({ lead_id: lead.lead_id }),
                });
                data = await response.json();
            } catch (e) {
                confirming = false;
                return null;
            }

            confirming = false;
            return data;
        }

        /**
         * Aplica o desconto nos cards de plano.
         * Mesma mecânica que o vídeo usava — nada de cobrança muda aqui.
         */
        function applyDiscount(amount) {
            window.DISCOUNT_APPLIED = true;
            window.DISCOUNT_AMOUNT = amount;

            document.querySelectorAll('[data-plan-option]').forEach(function(card) {
                if (card.dataset.planType === 'interval') return;
                const original = Number(card.dataset.planOriginalPrice || card.dataset.planPrice);
                if (!card.dataset.planOriginalPrice) card.dataset.planOriginalPrice = card.dataset.planPrice;

                const novo = Math.max(0.01, original - amount);
                card.dataset.planPrice = String(novo);

                const display = card.querySelector('[data-plan-price-display]');
                if (display) display.textContent = 'R$' + novo.toFixed(2).replace('.', ',');
            });

            const selected = document.querySelector('.plan-card-selected');
            if (selected && typeof selectWifiPlan === 'function') selectWifiPlan(selected);
        }

        function discountToast(amount) {
            const aviso = document.createElement('div');
            aviso.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-[200] bg-emerald-600 text-white font-bold text-sm px-5 py-3 rounded-xl shadow-2xl';
            aviso.textContent = 'Desconto de R$' + amount.toFixed(2).replace('.', ',') + ' liberado!';
            document.body.appendChild(aviso);
            setTimeout(function() { aviso.remove(); }, 5000);
        }

        // Máscaras
        document.addEventListener('DOMContentLoaded', function() {
            const cpfField = el('discount-cpf');
            if (cpfField) {
                cpfField.addEventListener('input', function() {
                    let v = this.value.replace(/\D/g, '').slice(0, 11);
                    v = v.replace(/(\d{3})(\d)/, '$1.$2')
                         .replace(/(\d{3})\.(\d{3})(\d)/, '$1.$2.$3')
                         .replace(/\.(\d{3})(\d{1,2})$/, '.$1-$2');
                    this.value = v;
                });
            }

            const phoneField = el('discount-phone');
            if (phoneField) {
                phoneField.addEventListener('input', function() {
                    let v = this.value.replace(/\D/g, '').slice(0, 11);
                    if (v.length > 10) v = v.replace(/(\d{2})(\d{5})(\d{4})/, '($1) $2-$3');
                    else if (v.length > 6) v = v.replace(/(\d{2})(\d{4})(\d{0,4})/, '($1) $2-$3');
                    else if (v.length > 2) v = v.replace(/(\d{2})(\d{0,5})/, '($1) $2');
                    this.value = v;
                });
            }
        });

        // Desconto já concedido (refresh da página ou volta do app): restaura.
        @if($discount_already_granted ?? false)
        document.addEventListener('DOMContentLoaded', function() {
            applyDiscount({{ $discount_already_granted_amount ?? 0 }});
            decided = true;
        });
        @endif

        // Voltou do app do parceiro: confere no servidor quanto tempo ficou lá.
        @if($discount_pending_lead_id ?? null)
        document.addEventListener('DOMContentLoaded', async function() {
            lead = { lead_id: {{ $discount_pending_lead_id }} };

            const data = await tryConfirm();
            if (!data) return;

            if (data.granted) {
                applyDiscount(Number(data.discount_amount) || DISCOUNT_AMOUNT);
                decided = true;
                discountToast(Number(data.discount_amount) || DISCOUNT_AMOUNT);
                return;
            }

            // Voltou cedo demais: oferece reabrir a proposta.
            renderWaiting(data.remaining_seconds);
            const hint = el('discount-waiting-hint');
            if (hint && data.message) hint.textContent = data.message;
            show('discount-waiting-modal');
        });
        @endif
    })();
    </script>
    @endif

    <!-- Modal de Recuperação por Telefone -->
    <div id="recovery-modal" class="fixed inset-0 bg-black/60 z-[55] hidden backdrop-blur-sm">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl w-full max-w-sm animate-slide-up shadow-2xl overflow-hidden">
                <!-- Header -->
                <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-5 py-4 text-center">
                    <p class="text-white font-extrabold text-base">Recuperar acesso</p>
                    <p class="text-amber-50 text-xs mt-1">Já pagou mas não conseguiu conectar?</p>
                </div>

                <div class="p-5">
                    <div id="recovery-form-area">
                        <p class="text-sm text-gray-600 mb-4 text-center">
                            Informe o telefone que você usou para pagar. Vamos liberar seu acesso automaticamente.
                        </p>

                        <input type="tel" id="recovery-phone" placeholder="(63) 9 8101-3050" maxlength="16"
                            class="w-full border-2 border-gray-300 rounded-xl px-4 py-3.5 focus:outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-200 transition-all text-base text-center font-medium">

                        <div id="recovery-error" class="hidden mt-3 bg-red-50 border border-red-200 text-red-700 px-3 py-2 rounded-lg text-xs"></div>

                        <button id="recovery-submit-btn" onclick="submitRecovery()" class="mt-4 w-full bg-gradient-to-r from-amber-500 to-orange-500 hover:from-amber-600 hover:to-orange-600 text-white font-bold py-3.5 rounded-xl shadow-md text-sm flex items-center justify-center gap-2 transition-all active:scale-[0.98]">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            RECUPERAR ACESSO
                        </button>

                        <button onclick="closeRecoveryModal()" class="mt-2 w-full text-gray-500 text-xs py-2 hover:text-gray-700">
                            Cancelar
                        </button>
                    </div>

                    <!-- Tela de sucesso (escondida) -->
                    <div id="recovery-success-area" class="hidden text-center py-2">
                        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-3">
                            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        </div>
                        <p class="text-base font-extrabold text-green-700 mb-1">Acesso liberado!</p>
                        <p class="text-sm text-gray-600 mb-4" id="recovery-success-msg">Sua internet será liberada em até 30 segundos.</p>
                        
                        <div class="bg-blue-50 border border-blue-200 rounded-xl p-3 mb-4 text-left">
                            <p class="text-xs font-bold text-blue-800 mb-2">Se ainda não funcionar, faça isso:</p>
                            <ol class="text-xs text-blue-700 space-y-1 list-decimal list-inside">
                                <li>Desconecte do WiFi</li>
                                <li>Desligue o WiFi do celular</li>
                                <li>Ligue o WiFi de novo e reconecte</li>
                            </ol>
                        </div>

                        <button onclick="closeRecoveryModal()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-sm shadow-md">
                            FECHAR
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        window.openRecoveryModal = function() {
            const modal = document.getElementById('recovery-modal');
            modal.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            
            // Reset
            document.getElementById('recovery-form-area').classList.remove('hidden');
            document.getElementById('recovery-success-area').classList.add('hidden');
            document.getElementById('recovery-error').classList.add('hidden');
            document.getElementById('recovery-phone').value = '';
            
            setTimeout(() => document.getElementById('recovery-phone').focus(), 100);
        };

        window.closeRecoveryModal = function() {
            document.getElementById('recovery-modal').classList.add('hidden');
            document.body.style.overflow = 'auto';
        };

        // Máscara de telefone
        const phoneInput = document.getElementById('recovery-phone');
        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let v = e.target.value.replace(/\D/g, '');
                if (v.length > 11) v = v.substring(0, 11);
                if (v.length > 10) {
                    v = '(' + v.substring(0, 2) + ') ' + v.substring(2, 3) + ' ' + v.substring(3, 7) + '-' + v.substring(7);
                } else if (v.length > 6) {
                    v = '(' + v.substring(0, 2) + ') ' + v.substring(2, 6) + '-' + v.substring(6);
                } else if (v.length > 2) {
                    v = '(' + v.substring(0, 2) + ') ' + v.substring(2);
                }
                e.target.value = v;
            });
            phoneInput.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') submitRecovery();
            });
        }

        window.submitRecovery = function() {
            const input = document.getElementById('recovery-phone');
            const errorEl = document.getElementById('recovery-error');
            const btn = document.getElementById('recovery-submit-btn');
            const phone = (input.value || '').replace(/\D/g, '');

            errorEl.classList.add('hidden');

            if (phone.length < 10) {
                errorEl.textContent = 'Informe um telefone válido com DDD.';
                errorEl.classList.remove('hidden');
                return;
            }

            // Pegar MAC e IP atuais (do portal.js)
            const macAddress = (window.wifiPortal && window.wifiPortal.deviceMac) || '';
            const ipAddress = (window.wifiPortal && window.wifiPortal.deviceIp) || '';

            const originalHtml = btn.innerHTML;
            btn.innerHTML = '<span class="animate-pulse">⏳ VERIFICANDO...</span>';
            btn.disabled = true;

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            fetch('/api/reativar-acesso', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
                body: JSON.stringify({ phone, mac_address: macAddress, ip_address: ipAddress }),
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('recovery-form-area').classList.add('hidden');
                    document.getElementById('recovery-success-area').classList.remove('hidden');
                    if (data.message) {
                        document.getElementById('recovery-success-msg').textContent = data.message;
                    }
                } else {
                    errorEl.textContent = data.message || 'Não foi possível recuperar o acesso. Verifique o telefone.';
                    errorEl.classList.remove('hidden');
                    btn.innerHTML = originalHtml;
                    btn.disabled = false;
                }
            })
            .catch(() => {
                errorEl.textContent = 'Erro de conexão. Tente novamente.';
                errorEl.classList.remove('hidden');
                btn.innerHTML = originalHtml;
                btn.disabled = false;
            });
        };

        // Fechar clicando fora
        document.getElementById('recovery-modal').addEventListener('click', function(e) {
            if (e.target === this) closeRecoveryModal();
        });
    })();
    </script>

    <!-- Registration Modal -->
    <div id="registration-modal" class="fixed inset-0 bg-black/50 z-50 hidden backdrop-blur-sm">
        <div class="flex items-center justify-center h-full p-4">
            <div class="bg-white rounded-2xl p-6 sm:p-8 w-full max-w-md animate-slide-up shadow-xl">
                <div class="flex justify-between items-center mb-5">
                    <h3 class="text-lg font-bold text-gray-900">Acesso rápido</h3>
                    <button id="close-registration-modal" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 transition-colors">&times;</button>
                </div>
                <p class="text-sm text-gray-500 mb-5">Informe seus dados para gerar o QR Code PIX.</p>
                <form id="registration-form" class="space-y-4">
                    <div id="registration-errors" class="hidden bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl text-sm"></div>
                    <div>
                        <label for="user_phone" class="block text-sm font-medium text-gray-700 mb-1.5">Telefone com DDD</label>
                        <input type="tel" id="user_phone" name="phone" required placeholder="(63) 9 8101-3050" maxlength="16" autofocus
                            class="w-full border border-gray-300 rounded-xl px-4 py-3.5 focus:outline-none focus:border-green focus:ring-2 focus:ring-green/20 transition-all text-base text-center font-medium">
                    </div>
                    <!-- Autorização do WhatsApp: vem marcada; o passageiro pode desmarcar ou responder PARAR -->
                    <label class="flex items-start gap-3 rounded-xl bg-emerald-50 border-2 border-emerald-200 p-3 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-100/60 transition-colors">
                        <input type="checkbox" id="whatsapp-payment-opt-in" name="whatsapp_payment_opt_in" value="1" checked class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="leading-snug">
                            <span class="block text-[12px] font-bold text-emerald-900">📲 Receber comprovante no WhatsApp</span>
                            <span class="block text-[11px] text-emerald-800 mt-0.5">Confirmação e 1 lembrete se não concluir. Responda <strong>PARAR</strong> para sair.</span>
                        </span>
                    </label>
                    <button type="submit" id="registration-submit-btn" class="connect-button w-full text-white font-bold py-3.5 rounded-xl shadow-md text-sm">
                        GERAR QR CODE PIX
                    </button>
                </form>
                <p class="text-center text-xs text-gray-400 mt-4">Pagamento seguro &bull; Liberação automática</p>
            </div>
        </div>
    </div>

    <!-- Payment Modal -->
    <div id="payment-modal" class="fixed inset-0 bg-black/50 z-40 hidden backdrop-blur-sm">
        <div class="flex items-end sm:items-center justify-center h-full p-0 sm:p-4">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl p-6 sm:p-8 w-full max-w-md animate-slide-up shadow-xl">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-lg font-bold text-gray-900">Pagamento PIX</h3>
                    <button id="close-modal" class="w-8 h-8 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-500 transition-colors">&times;</button>
                </div>
                <div class="bg-emerald-50 rounded-xl p-5 mb-5 text-center border border-emerald-100">
                    <p id="selected-plan-price" class="text-3xl font-extrabold text-emerald-700">R$6,99</p>
                    <p id="selected-plan-name" class="text-sm text-emerald-600 mt-1">Viagem completa / {{ $session_duration ?? 12 }} horas de acesso</p>
                </div>
                <label class="flex items-start gap-3 rounded-xl bg-emerald-50 border-2 border-emerald-200 p-3 mb-4 cursor-pointer has-[:checked]:border-emerald-500 has-[:checked]:bg-emerald-100/60 transition-colors">
                    <input type="checkbox" id="whatsapp-payment-opt-in-payment" value="1" checked class="mt-0.5 h-4 w-4 flex-shrink-0 rounded border-gray-300 text-emerald-600 focus:ring-emerald-500">
                    <span class="leading-snug">
                        <span class="block text-[12px] font-bold text-emerald-900">📲 Receber comprovante no WhatsApp</span>
                        <span class="block text-[11px] text-emerald-800 mt-0.5">Confirmação e 1 lembrete se não concluir. Responda <strong>PARAR</strong> para sair.</span>
                    </span>
                </label>
                <button data-payment="pix" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3.5 rounded-xl transition-colors shadow-md text-sm">
                    PAGAR AGORA
                </button>
                <p class="text-center text-xs text-gray-400 mt-3">Pagamento seguro e instantâneo</p>
            </div>
        </div>
    </div>

    <script>
        // ===== VOUCHER SYSTEM =====
        document.addEventListener('DOMContentLoaded', function() {
            function applyVoucher(inputId, buttonId) {
                const input = document.getElementById(inputId);
                const button = document.getElementById(buttonId);
                if (!input || !button) return;
                const voucherCode = input.value.trim().toUpperCase();
                if (!voucherCode) { alert('Por favor, digite o código do voucher'); return; }
                button.disabled = true; button.textContent = '...';
                fetch('/api/voucher/validate', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                    body: JSON.stringify({ voucher_code: voucherCode })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert(`${data.message}\n\nTipo: ${data.voucher_type === 'unlimited' ? 'Ilimitado' : 'Limitado'}\nHoras: ${data.hours_granted}h\nVálido até: ${new Date(data.expires_at).toLocaleString('pt-BR')}` +
                            (data.voucher_type === 'limited' ? `\nHoras restantes hoje: ${data.remaining_hours_today}h` : ''));
                        setTimeout(() => { window.location.href = 'https://www.google.com'; }, 2000);
                    } else { alert(data.message); button.disabled = false; button.textContent = 'OK'; }
                })
                .catch(() => { alert('Erro ao processar voucher. Tente novamente.'); button.disabled = false; button.textContent = 'OK'; });
            }
            ['mobile', 'desktop'].forEach(suffix => {
                const btn = document.getElementById(`apply-voucher-${suffix}`);
                if (btn) btn.addEventListener('click', () => applyVoucher(`voucher-code-${suffix}`, `apply-voucher-${suffix}`));
                const input = document.getElementById(`voucher-code-${suffix}`);
                if (input) input.addEventListener('keypress', e => { if (e.key === 'Enter') applyVoucher(`voucher-code-${suffix}`, `apply-voucher-${suffix}`); });
            });
        });
    </script>

    <script>
        window.PORTAL_MAC = @json(request('mac') ?: request('mikrotik_mac') ?: request('client_mac'));
        window.PORTAL_IP = @json(request('ip') ?: request('client_ip'));
        window.WIFI_PRICE = {{ ($plan_full_enabled ?? true) ? ($wifi_price_full ?? 6.99) : ($wifi_price_short ?? 5.99) }};
        window.SESSION_DURATION = {{ ($plan_full_enabled ?? true) ? ($session_duration ?? 12) : ($session_duration_short ?? 1) }};
        window.WIFI_SELECTED_PLAN = null;

        function selectWifiPlan(card) {
            const interval = card.dataset.planType === 'interval';
            const plan = interval ? window.IntervalPlan.selection() : {
                amount: Number(card.dataset.planPrice), duration: Number(card.dataset.planDuration),
                name: card.dataset.planName, suffix: card.dataset.planSuffix
            };
            const { amount, duration, name, suffix } = plan;
            window.WIFI_PRICE = amount;
            window.SESSION_DURATION = duration;
            window.WIFI_SELECTED_PLAN = plan;
            if (window.wifiPortal) window.wifiPortal._prefetchedQR = null;
            document.getElementById('interval-plan-fields')?.classList.toggle('hidden', !interval);
            document.getElementById('interval-plan-option')?.setAttribute('aria-expanded', String(interval));
            ['connect-btn', 'connect-btn-desktop'].forEach(id => {
                const button = document.getElementById(id);
                if (!button) return;
                button.disabled = interval && !plan.valid;
                button.style.opacity = button.disabled ? '0.5' : '';
                button.children[0].textContent = interval ? 'PAGAR INTERVALO' : 'CONECTAR AGORA';
                button.children[1].textContent = interval ? 'Pagamento único via PIX' : 'Pague via PIX e navegue na hora · rápido e fácil';
            });
            if (window.wifiPortal) window.wifiPortal.sessionDurationHours = duration;

            document.querySelectorAll('[data-plan-option]').forEach(option => {
                const selected = option === card;
                option.classList.toggle('plan-card-selected', selected);
                option.classList.toggle('border-gray-200', !selected);
                option.classList.toggle('bg-white', !selected);
                const radio = option.querySelector('[data-plan-radio]');
                if (radio) {
                    radio.classList.toggle('border-[5px]', selected);
                    radio.classList.toggle('border-green', selected);
                    radio.classList.toggle('border-2', !selected);
                    radio.classList.toggle('border-gray-300', !selected);
                    if (selected) { radio.classList.add('h-6', 'w-6'); radio.classList.remove('h-5', 'w-5'); }
                    else { radio.classList.add('h-5', 'w-5'); radio.classList.remove('h-6', 'w-6'); }
                }
            });

            const formatted = amount.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            const priceEl = document.getElementById('selected-plan-price');
            const nameEl = document.getElementById('selected-plan-name');
            if (priceEl) priceEl.textContent = formatted;
            if (nameEl) nameEl.textContent = interval
                ? `${name}: ${plan.interval_start.split('-').reverse().join('/')} a ${plan.interval_end.split('-').reverse().join('/')} · ${duration} horas seguidas`
                : `${name} ${suffix}`;
        }

        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('[data-plan-option]').forEach(card => {
                card.addEventListener('click', () => selectWifiPlan(card));
            });
            // Auto-selecionar plano: default primeiro, senão o primeiro disponível
            const defaultPlan = document.querySelector('[data-plan-default="true"]');
            const firstPlan = document.querySelector('[data-plan-option]');
            const planToSelect = defaultPlan || firstPlan;
            if (planToSelect) selectWifiPlan(planToSelect);
        });
    </script>

    <script src="{{ asset('js/mac-detector.js') }}?v={{ filemtime(public_path('js/mac-detector.js')) }}"></script>
    <script src="{{ asset('js/qrcode.min.js') }}"></script>
    <script src="{{ asset('js/interval-plan.js') }}?v={{ filemtime(public_path('js/interval-plan.js')) }}"></script>
    <script src="{{ asset('js/portal.js') }}?v={{ filemtime(public_path('js/portal.js')) }}"></script>

    @if($discount_offer_enabled ?? false)
    <script>
    /**
     * A oferta de desconto vem ANTES de pedir o telefone.
     *
     *  - Pular e pagar normal  -> cadastro de sempre, só telefone
     *  - Tenha R$X de desconto -> telefone + CPF numa tela só
     *
     * Por isso interceptamos showRegistrationModal (usuário novo) e
     * processPixPayment (usuário já cadastrado), que são os dois pontos
     * logo depois de o MAC ser resolvido.
     */
    document.addEventListener('DOMContentLoaded', function() {
        var checkPortal = setInterval(function() {
            if (window.wifiPortal) {
                clearInterval(checkPortal);

                var portal = window.wifiPortal;
                var originalShowRegistration = portal.showRegistrationModal.bind(portal);
                var originalProcessPix = portal.processPixPayment.bind(portal);
                var originalProcessPixFast = portal.processPixPaymentFast.bind(portal);

                function comOferta(original) {
                    return function() {
                        if (window.WIFI_SELECTED_PLAN?.plan_type === 'interval') return original();
                        if (typeof window.DISCOUNT_OFFER_INTERCEPT === 'function') {
                            window.DISCOUNT_OFFER_INTERCEPT(original);
                        } else {
                            original();
                        }
                    };
                }

                // Usuário novo: a oferta aparece no lugar do cadastro de telefone.
                portal.showRegistrationModal = comOferta(originalShowRegistration);

                // Usuário já cadastrado: a oferta aparece antes de gerar o PIX.
                portal.processPixPayment = comOferta(originalProcessPix);
                portal.processPixPaymentFast = comOferta(originalProcessPixFast);

                // Para o fluxo com desconto: cadastra o telefone antes de sair
                // do portal, para que na volta ele já esteja registrado.
                window.DISCOUNT_REGISTER_PHONE = async function(phone) {
                    if (portal.currentUserId) return portal.currentUserId;

                    if (!portal.deviceMac || portal.deviceMac === 'DETECTING...') {
                        await portal.ensureRealIdentifiers();
                    }
                    if (!portal.deviceMac || !portal.isValidMacAddress(portal.deviceMac)) {
                        throw new Error('sem-mac');
                    }

                    var resp = await fetch('/api/register-for-payment', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': portal.getCSRFToken(),
                        },
                        body: JSON.stringify({
                            phone: phone,
                            email: '',
                            user_id: portal.currentUserId,
                            mac_address: portal.deviceMac,
                            ip_address: portal.deviceIp,
                            whatsapp_payment_opt_in: document.getElementById('whatsapp-payment-opt-in')?.checked ? 1 : 0,
                        }),
                    });

                    var result = await resp.json();
                    if (!result.success) throw new Error(result.message || 'registro');
                    portal.currentUserId = result.user_id;
                    return result.user_id;
                };
            }
        }, 100);
    });
    </script>
    @endif

    @include('components.chat-widget')
</body>
</html>
