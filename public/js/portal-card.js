/**
 * Pagamento com cartão de crédito (PagBank) na tela de pagamento do portal.
 *
 * Este arquivo só é carregado quando o admin liga o cartão em /admin/settings.
 * Ele NÃO altera o portal.js: espera a tela do PIX aparecer e encaixa o cartão
 * dentro dela. Se qualquer coisa aqui falhar, a tela do PIX continua como está.
 *
 * O número do cartão é criptografado no aparelho pelo SDK do PagBank e nunca
 * é enviado em texto para o nosso servidor.
 */
(function () {
    'use strict';

    var SDK_URL = 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js';
    var cfg = null;          // { enabled, mode, public_key }
    var cfgPromise = null;
    var sdkPromise = null;
    var submitting = false;

    function portal() { return window.wifiPortal || null; }
    function paymentId() { var p = portal(); return p ? p.currentPaymentId : null; }
    function onlyDigits(v) { return String(v || '').replace(/\D/g, ''); }

    function loadConfig() {
        if (!cfgPromise) {
            cfgPromise = fetch('/api/payment/card/config', { headers: { 'Accept': 'application/json' } })
                .then(function (r) { return r.json(); })
                .then(function (j) { cfg = j; return j; })
                .catch(function () { cfgPromise = null; return { enabled: false }; });
        }
        return cfgPromise;
    }

    function injectSdkOnce() {
        return new Promise(function (resolve, reject) {
            if (window.PagSeguro && typeof window.PagSeguro.encryptCard === 'function') { resolve(); return; }
            var s = document.createElement('script');
            s.src = SDK_URL;
            s.async = true;
            var timer = setTimeout(function () { s.remove(); reject(new Error('timeout')); }, 8000);
            s.onload = function () {
                clearTimeout(timer);
                if (window.PagSeguro && typeof window.PagSeguro.encryptCard === 'function') resolve();
                else reject(new Error('sdk'));
            };
            s.onerror = function () { clearTimeout(timer); s.remove(); reject(new Error('network')); };
            document.head.appendChild(s);
        });
    }

    /**
     * Carrega o SDK. Se o hotspot ainda estiver bloqueando, pede a mesma
     * liberação temporária de 3 min que o PIX usa e tenta de novo por ~30s
     * (o roteador sincroniza a cada 15s).
     */
    function loadSdk(onWaiting) {
        if (sdkPromise) return sdkPromise;
        sdkPromise = (async function () {
            var askedBypass = false;
            for (var attempt = 0; attempt < 6; attempt++) {
                try { await injectSdkOnce(); return; } catch (e) { /* tenta de novo */ }
                if (!askedBypass) {
                    askedBypass = true;
                    if (onWaiting) onWaiting();
                    try {
                        var p = portal();
                        if (p && typeof p.detectAndBypass === 'function' && paymentId()) p.detectAndBypass(paymentId());
                    } catch (e) { /* segue tentando */ }
                }
                await new Promise(function (r) { setTimeout(r, 5000); });
            }
            throw new Error('sdk-unavailable');
        })();
        sdkPromise.catch(function () { sdkPromise = null; });
        return sdkPromise;
    }

    function el(html) {
        var d = document.createElement('div');
        d.innerHTML = html.trim();
        return d.firstChild;
    }

    function inputHtml(id, label, attrs) {
        return '<label style="display:block;margin-bottom:8px">' +
            '<span style="display:block;font-size:12px;font-weight:700;color:#374151;margin-bottom:3px">' + label + '</span>' +
            '<input id="' + id + '" ' + attrs + ' style="width:100%;box-sizing:border-box;border:2px solid #d1d5db;border-radius:10px;padding:11px 12px;font-size:16px;color:#111827;background:#fff;outline:none">' +
            '</label>';
    }

    function buildPanel(mode) {
        return el(
            '<div id="card-pay-panel" style="display:none">' +
                '<p style="font-size:15px;font-weight:800;color:#111827;margin:0 0 2px">Pagar com cartão de crédito</p>' +
                '<p style="font-size:12px;color:#6b7280;margin:0 0 10px">Crédito à vista. A internet libera assim que aprovar.</p>' +
                '<div id="card-pay-bypass" style="display:none;font-size:13px;line-height:1.35;border-radius:10px;padding:9px 11px;margin-bottom:10px"></div>' +
                '<div id="card-pay-msg" style="display:none;font-size:13px;border-radius:10px;padding:9px 11px;margin-bottom:10px"></div>' +
                '<form id="card-pay-form" autocomplete="on" novalidate>' +
                    inputHtml('card-number', 'Número do cartão', 'type="text" inputmode="numeric" autocomplete="cc-number" maxlength="23" placeholder="0000 0000 0000 0000"') +
                    '<div style="display:flex;gap:8px">' +
                        '<div style="flex:1">' + inputHtml('card-exp', 'Validade', 'type="text" inputmode="numeric" autocomplete="cc-exp" maxlength="5" placeholder="MM/AA"') + '</div>' +
                        '<div style="flex:1">' + inputHtml('card-cvv', 'CVV', 'type="text" inputmode="numeric" autocomplete="cc-csc" maxlength="4" placeholder="123"') + '</div>' +
                    '</div>' +
                    inputHtml('card-holder', 'Nome impresso no cartão', 'type="text" autocomplete="cc-name" maxlength="80" placeholder="NOME COMO NO CARTÃO"') +
                    inputHtml('card-cpf', 'CPF do titular', 'type="text" inputmode="numeric" maxlength="14" placeholder="000.000.000-00"') +
                    '<button id="card-pay-submit" type="submit" style="width:100%;background:#2563eb;color:#fff;font-weight:900;font-size:17px;border:0;border-radius:12px;padding:14px;margin-top:4px">PAGAR COM CARTÃO</button>' +
                '</form>' +
                '<p style="font-size:11px;color:#9ca3af;text-align:center;margin:8px 0 0">🔒 Dados criptografados no seu aparelho. Processado pelo PagBank.</p>' +
                '<div style="height:72px"></div>' +
            '</div>'
        );
    }

    function showMsg(text, kind) {
        var box = document.getElementById('card-pay-msg');
        if (!box) return;
        if (!text) { box.style.display = 'none'; return; }
        var colors = {
            error: ['#fef2f2', '#991b1b', '#fecaca'],
            info: ['#eff6ff', '#1e40af', '#bfdbfe'],
            ok: ['#ecfdf5', '#065f46', '#a7f3d0']
        }[kind || 'info'];
        box.style.display = 'block';
        box.style.background = colors[0];
        box.style.color = colors[1];
        box.style.border = '1px solid ' + colors[2];
        box.textContent = text;
    }

    function showBypassInfo(text, kind) {
        var box = document.getElementById('card-pay-bypass');
        if (!box) return;
        var colors = kind === 'warn' ? ['#fffbeb', '#92400e', '#fde68a'] : ['#ecfdf5', '#065f46', '#a7f3d0'];
        box.style.display = 'block';
        box.style.background = colors[0];
        box.style.color = colors[1];
        box.style.border = '1px solid ' + colors[2];
        box.textContent = text;
    }

    /**
     * Mesma liberação temporária de 3 min do PIX (mesmo endpoint, mesmo limite
     * de 2 por hora), para o passageiro abrir o app do banco e pegar os dados
     * do cartão virtual. Usa a função do próprio portal, sem regra nova.
     */
    var bypassAskedFor = null;
    async function requestBypass() {
        var p = portal();
        var pid = paymentId();
        if (!p || !pid || typeof p.detectAndBypass !== 'function' || bypassAskedFor === pid) return;
        bypassAskedFor = pid;
        showBypassInfo('Liberando a internet por 3 minutos para você abrir o app do banco…', 'ok');
        try { await Promise.resolve(p.detectAndBypass(pid)); } catch (e) { /* trata abaixo */ }
        // Se o PIX já tinha pedido a liberação, espera a resposta dele.
        for (var i = 0; i < 12 && p._bypassMode === 'checking'; i++) {
            await new Promise(function (r) { setTimeout(r, 1000); });
        }

        if (p._bypassMode === 'success') {
            showBypassInfo(p._bypassAlreadyConnected
                ? 'Sua internet já está liberada.'
                : 'Internet liberada por 3 minutos (pode levar até 15 segundos). Se o cartão é virtual, abra o app do banco, copie os dados e volte para esta tela.', 'ok');
        } else if (p._bypassMode === 'limit') {
            showBypassInfo('Você já usou as 2 liberações desta hora. Para pegar o cartão virtual no app do banco, use os dados móveis (4G/5G).', 'warn');
        } else if (p._bypassMode === 'blocked') {
            showBypassInfo('A liberação temporária não está disponível. Use os dados móveis (4G/5G) para abrir o app do banco.', 'warn');
        } else {
            bypassAskedFor = null; // falhou: tenta de novo no próximo toque
            showBypassInfo('Não foi possível liberar a internet agora. Se precisar abrir o app do banco, use os dados móveis (4G/5G).', 'warn');
        }
    }

    function setBusy(busy, label) {
        submitting = busy;
        var btn = document.getElementById('card-pay-submit');
        if (!btn) return;
        btn.disabled = busy;
        btn.style.opacity = busy ? '0.6' : '1';
        btn.textContent = label || (busy ? 'PROCESSANDO…' : 'PAGAR COM CARTÃO');
    }

    function bindMasks(root) {
        var num = root.querySelector('#card-number');
        var exp = root.querySelector('#card-exp');
        var cvv = root.querySelector('#card-cvv');
        var cpf = root.querySelector('#card-cpf');
        num.addEventListener('input', function () {
            num.value = onlyDigits(num.value).slice(0, 19).replace(/(\d{4})(?=\d)/g, '$1 ');
        });
        exp.addEventListener('input', function () {
            var d = onlyDigits(exp.value).slice(0, 4);
            exp.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
        });
        cvv.addEventListener('input', function () { cvv.value = onlyDigits(cvv.value).slice(0, 4); });
        cpf.addEventListener('input', function () {
            var d = onlyDigits(cpf.value).slice(0, 11);
            cpf.value = d.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        });
    }

    function readForm() {
        var number = onlyDigits(document.getElementById('card-number').value);
        var exp = onlyDigits(document.getElementById('card-exp').value);
        var cvv = onlyDigits(document.getElementById('card-cvv').value);
        var holder = document.getElementById('card-holder').value.trim().replace(/\s+/g, ' ');
        var cpf = onlyDigits(document.getElementById('card-cpf').value);

        if (number.length < 13) return { error: 'Confira o número do cartão.' };
        if (exp.length !== 4) return { error: 'Informe a validade no formato MM/AA.' };
        var month = parseInt(exp.slice(0, 2), 10);
        if (month < 1 || month > 12) return { error: 'Mês de validade inválido.' };
        if (cvv.length < 3) return { error: 'Confira o CVV (código de segurança).' };
        if (holder.length < 3) return { error: 'Informe o nome impresso no cartão.' };
        if (cpf.length !== 11) return { error: 'Informe o CPF do titular (11 números).' };

        return {
            number: number,
            expMonth: exp.slice(0, 2),
            expYear: '20' + exp.slice(2),
            securityCode: cvv,
            holder: holder,
            cpf: cpf
        };
    }

    async function submit() {
        if (submitting) return;
        var data = readForm();
        if (data.error) { showMsg(data.error, 'error'); return; }
        var pid = paymentId();
        if (!pid) { showMsg('Pagamento não encontrado. Feche e tente de novo.', 'error'); return; }

        setBusy(true, 'PREPARANDO…');
        showMsg('', null);

        try {
            await loadSdk(function () { showMsg('Preparando o pagamento seguro… pode levar até 30 segundos.', 'info'); });
        } catch (e) {
            setBusy(false);
            showMsg(cfg && cfg.mode === 'both'
                ? 'Não foi possível carregar o pagamento com cartão. Use o PIX.'
                : 'Não foi possível carregar o pagamento com cartão. Tente de novo em instantes.', 'error');
            return;
        }

        var encrypted;
        try {
            var enc = window.PagSeguro.encryptCard({
                publicKey: cfg.public_key,
                holder: data.holder,
                number: data.number,
                expMonth: data.expMonth,
                expYear: data.expYear,
                securityCode: data.securityCode
            });
            if (enc.hasErrors || !enc.encryptedCard) {
                setBusy(false);
                showMsg('Dados do cartão inválidos. Confira número, validade, CVV e nome.', 'error');
                return;
            }
            encrypted = enc.encryptedCard;
        } catch (e) {
            setBusy(false);
            showMsg('Não foi possível ler os dados do cartão. Confira e tente de novo.', 'error');
            return;
        }

        setBusy(true, 'PROCESSANDO…');
        showMsg('Processando o pagamento. Não feche esta tela.', 'info');

        var result = null;
        try {
            var response = await fetch('/api/payment/card/charge', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify({ payment_id: pid, encrypted_card: encrypted, holder_name: data.holder, cpf: data.cpf })
            });
            result = await response.json();
        } catch (e) {
            setBusy(false);
            showMsg('Sem resposta do servidor. Aguarde 1 minuto: se o cartão foi aprovado, a internet libera sozinha.', 'error');
            return;
        }

        if (result && result.success && result.status === 'PAID') {
            showMsg('Pagamento aprovado! Liberando sua internet…', 'ok');
            setBusy(true, 'APROVADO ✓');
            // O próprio portal confirma e segue o fluxo normal de liberação.
            try { portal().checkPaymentStatus(pid); } catch (e) { /* o polling automático confirma */ }
            return;
        }

        if (result && result.success && result.status === 'IN_ANALYSIS') {
            showMsg(result.message || 'Cartão em análise. A internet libera sozinha quando aprovar.', 'info');
            setBusy(true, 'EM ANÁLISE…');
            return;
        }

        setBusy(false);
        showMsg((result && result.message) || 'Não foi possível processar o cartão.', 'error');
    }

    function mount(modal) {
        var step1 = modal.querySelector('#step-1-content');
        if (!step1 || step1.dataset.cardMounted) return;
        step1.dataset.cardMounted = '1';

        var mode = cfg.mode;
        var pixChildren = Array.prototype.slice.call(step1.children);
        var panel = buildPanel(mode);
        step1.appendChild(panel);
        bindMasks(panel);

        var tabs = null;
        function paintTabs(active) {
            if (!tabs) return;
            tabs.querySelectorAll('button').forEach(function (b) {
                var on = b.dataset.tab === active;
                b.style.background = on ? '#2563eb' : '#fff';
                b.style.color = on ? '#fff' : '#1f2937';
                b.style.borderColor = on ? '#2563eb' : '#d1d5db';
            });
        }
        function showCard() {
            pixChildren.forEach(function (c) { c.dataset.cardPrevDisplay = c.style.display || ''; c.style.display = 'none'; });
            panel.style.display = 'block';
            paintTabs('card');
            // Adianta o carregamento do SDK enquanto a pessoa digita.
            loadSdk().catch(function () {});
        }
        function showPix() {
            panel.style.display = 'none';
            pixChildren.forEach(function (c) { c.style.display = c.dataset.cardPrevDisplay || ''; });
            paintTabs('pix');
        }

        if (mode === 'both') {
            // Escolha da forma de pagamento no TOPO da tela, sempre visível
            // (embaixo ficava escondida atrás do ícone do chat).
            var tabStyle = 'flex:1;font-weight:800;font-size:15px;border:2px solid #d1d5db;border-radius:12px;padding:12px 6px;line-height:1.1';
            tabs = el(
                '<div id="card-pay-tabs" style="margin-bottom:12px">' +
                    '<p style="font-size:12px;font-weight:700;color:#6b7280;margin:0 0 6px;text-align:center">Como você quer pagar?</p>' +
                    '<div style="display:flex;gap:8px">' +
                        '<button type="button" data-tab="pix" style="' + tabStyle + '">PIX</button>' +
                        '<button type="button" data-tab="card" style="' + tabStyle + '">💳 Cartão de crédito</button>' +
                    '</div>' +
                '</div>'
            );
            step1.insertBefore(tabs, step1.firstChild);
            paintTabs('pix');
            tabs.querySelector('[data-tab="pix"]').addEventListener('click', showPix);
            // Ao escolher cartão, libera os 3 min (igual ao "copiar código" do PIX).
            tabs.querySelector('[data-tab="card"]').addEventListener('click', function () { showCard(); requestBypass(); });
        } else {
            showCard();
            // Só cartão: não há botão de escolha, então a liberação sai no
            // primeiro toque no formulário (nunca sozinha, sem ação da pessoa).
            panel.addEventListener('pointerdown', requestBypass, { once: true });
            panel.addEventListener('focusin', requestBypass, { once: true });
            modal.querySelectorAll('p').forEach(function (p) {
                if (p.textContent.trim() === 'Pagamento PIX') p.textContent = 'Pagamento com cartão';
            });
            var stepText = modal.querySelector('#step-1-text');
            if (stepText) stepText.textContent = 'Cartão';
        }

        panel.querySelector('#card-pay-form').addEventListener('submit', function (e) {
            e.preventDefault();
            submit();
        });
    }

    function tryMount() {
        var modal = document.getElementById('pix-modal');
        if (!modal) return;
        loadConfig().then(function (c) {
            if (!c || !c.enabled || !c.public_key) return;
            var current = document.getElementById('pix-modal');
            if (current) {
                try { mount(current); } catch (e) { console.error('Cartão: falha ao montar', e); }
            }
        });
    }

    function start() {
        loadConfig();
        try {
            new MutationObserver(function () {
                if (document.getElementById('pix-modal')) tryMount();
            }).observe(document.body, { childList: true, subtree: true });
        } catch (e) { /* sem observer, fica só o PIX */ }
        tryMount();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
    else start();
})();
