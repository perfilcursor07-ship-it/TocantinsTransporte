<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Cadastro PIX Motorista - WiFi Tocantins</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: "Segoe UI", system-ui, -apple-system, sans-serif; }
    </style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-white to-slate-100 min-h-screen py-8 px-4">
@php
    $hasOld = (bool) old('full_name');
    $currentMonthLabel = $currentMonthLabel ?? \App\Models\DriverPixProfileMonth::labelFor(now());
    $ehMesPassado = $ehMesPassado ?? false;
@endphp
<div class="w-full max-w-lg mx-auto">
    <div class="text-center mb-7">
        <div class="w-14 h-14 bg-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-emerald-200">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">Cadastro PIX — Motorista</h1>
        <p class="text-sm text-slate-500 mt-2 max-w-sm mx-auto">
            Confirme seus dados de <strong class="text-slate-700">{{ $currentMonthLabel }}</strong> para receber o pagamento
        </p>
    </div>

    @if($ehMesPassado)
    <div class="mb-5 p-4 rounded-2xl bg-amber-50 border border-amber-200">
        <p class="text-sm font-bold text-amber-900">Este link é do mês de {{ $currentMonthLabel }}</p>
        <p class="text-xs text-amber-800 mt-1">
            Você está confirmando os dados daquele mês, não do mês atual. Informe o carro em que você estava em {{ $currentMonthLabel }}.
        </p>
    </div>
    @endif

    @if($errors->any())
    <div class="mb-5 p-4 rounded-2xl bg-red-50 border border-red-200 text-red-700 text-sm space-y-1">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
    @endif

    {{-- ETAPA 1: identificar --}}
    <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 p-5 mb-5">
        <div class="flex items-start gap-3">
            <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-sm font-bold text-slate-800">Comece pela busca</h2>
                <p class="text-xs text-slate-500 mt-0.5">Digite seu <strong>CPF</strong> ou <strong>telefone</strong> e toque em Buscar. Depois preencha só o que faltar.</p>

                <div class="flex gap-2 mt-3">
                    <input type="text" id="lookupIdentifier" inputmode="numeric" placeholder="CPF ou telefone com DDD"
                           class="flex-1 px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white transition">
                    <button type="button" id="lookupBtn"
                            class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold whitespace-nowrap transition disabled:opacity-60">
                        Buscar
                    </button>
                </div>

                <div id="lookupFeedback" class="hidden mt-3 text-xs rounded-xl px-3 py-2.5 leading-relaxed"></div>

                <div id="lookupHistory" class="hidden mt-3">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-slate-400 mb-1.5">Últimos envios</p>
                    <div id="lookupHistoryList" class="flex flex-wrap gap-1.5"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- ETAPA 2: formulário (só após busca ou erro de validação) --}}
    <div id="formCard" class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 overflow-hidden {{ $hasOld ? '' : 'hidden' }}">
        <form action="{{ route('driver-pix.store', $link->token) }}" method="POST" id="pixForm">
            @csrf
            <input type="hidden" name="keep_pix_key" id="keepPixKeyInput" value="{{ old('keep_pix_key', '0') }}">
            <input type="hidden" name="reference_month" id="referenceMonth" value="{{ old('reference_month', $currentMonth) }}">

            <div class="px-6 pt-5 pb-2">
                <div class="flex items-center justify-between gap-3 mb-4">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-600">Seus dados</p>
                    <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-2 py-1 rounded-lg">{{ $currentMonthLabel }}</span>
                </div>
                {{-- Campos lado a lado. Nome e CPF ficam travados quando o
                     cadastro foi encontrado: o motorista só ajusta telefone e carro. --}}
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Nome completo <span class="text-red-500">*</span></label>
                        <input type="text" name="full_name" id="fullName" value="{{ old('full_name') }}" required placeholder="Seu nome completo"
                               class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition uppercase">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">CPF <span class="text-red-500">*</span></label>
                        <input type="text" name="cpf" id="cpf" inputmode="numeric" value="{{ old('cpf') }}" required placeholder="000.000.000-00"
                               class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Telefone (WhatsApp) <span class="text-red-500">*</span></label>
                        <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" required placeholder="(63) 99999-9999"
                               class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Número do ônibus / carro <span class="text-red-500">*</span></label>
                        <input type="text" name="bus_number" id="busNumber" value="{{ old('bus_number') }}" required placeholder="Ex: 5013"
                               class="w-full px-3 py-2.5 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition uppercase font-semibold tracking-wide">
                        <p class="text-[11px] text-slate-400 mt-1">Mude se trocou de carro neste mês.</p>
                    </div>
                </div>

                <p id="lockedHint" class="hidden text-[11px] text-slate-400 mt-2">
                    Nome e CPF vêm do seu cadastro e não mudam aqui. Se estiver errado, fale com a administração.
                </p>

                {{-- Quanto o carro fez no mês e o prêmio que isso dá --}}
                <div id="busPrizeBox" class="hidden mt-4 rounded-2xl border p-3.5">
                    <p class="text-[10px] font-bold uppercase tracking-widest" id="busPrizeTitle"></p>
                    <div class="flex items-end justify-between gap-3 mt-1.5">
                        <div>
                            <p class="text-[11px] text-slate-500">Este carro já fez</p>
                            <p class="text-xl font-bold text-slate-800" id="busRevenue"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[11px] text-slate-500">Prêmio do carro</p>
                            <p class="text-xl font-bold" id="busPrize"></p>
                        </div>
                    </div>
                    <p class="text-[11px] mt-2 leading-relaxed" id="busPrizeNote"></p>
                </div>

            <div class="px-6 py-5 mt-2 bg-slate-50/80 border-t border-slate-100">
                <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-600 mb-4">Chave PIX para receber</p>

                {{-- Chave já cadastrada: mostra e pede confirmação explícita. --}}
                <div id="keepPixBox" class="hidden mb-4 rounded-2xl border-2 border-emerald-300 bg-emerald-50 p-4">
                    <p class="text-[11px] font-bold uppercase tracking-widest text-emerald-700">Sua chave cadastrada</p>
                    <p class="text-lg font-bold text-slate-800 mt-1 break-all" id="keepPixValue"></p>
                    <p class="text-[11px] text-slate-500 mt-0.5" id="keepPixInfo"></p>

                    <label class="flex items-start gap-2.5 mt-3 cursor-pointer">
                        <input type="checkbox" id="keepPixCheckbox" checked
                               class="mt-0.5 w-5 h-5 flex-shrink-0 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-semibold text-slate-800 leading-snug">
                            Confirmo que o pagamento é para esta chave
                        </span>
                    </label>

                    <button type="button" id="changePixBtn"
                            class="mt-2 text-[12px] font-semibold text-slate-500 underline hover:text-slate-700">
                        Trocar minha chave PIX
                    </button>
                </div>

                <div id="pixFields" class="space-y-4">
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Chave PIX <span class="text-red-500">*</span></label>
                        <input type="text" name="pix_key" id="pixKey" value="{{ old('pix_key') }}" placeholder="CPF, e-mail, telefone ou chave aleatória"
                               class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                        <p class="text-[11px] text-slate-400 mt-1.5">Use exatamente a mesma chave cadastrada no seu banco.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-slate-700 mb-1.5">Confirmar chave PIX <span class="text-red-500">*</span></label>
                        <input type="text" name="pix_key_confirmation" id="pixKeyConfirmation" value="{{ old('pix_key_confirmation') }}" placeholder="Digite a chave PIX novamente"
                               class="w-full px-4 py-3 bg-white border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 transition">
                    </div>
                </div>
            </div>

            <div class="px-6 pb-6 pt-4">
                <button type="submit" id="submitBtn" class="w-full bg-emerald-600 hover:bg-emerald-700 active:scale-[0.99] text-white py-3.5 rounded-xl font-bold text-sm transition-all shadow-lg shadow-emerald-200/50">
                    Confirmar dados de {{ $currentMonthLabel }}
                </button>
            </div>
        </form>
    </div>

    <p id="formHint" class="text-center text-xs text-slate-400 mt-6 leading-relaxed {{ $hasOld ? 'hidden' : '' }}">
        Depois da busca, os campos aparecem para você completar o que faltar.
    </p>
</div>

<script>
(function () {
    const lookupUrl = @json(route('driver-pix.lookup', $link->token));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const currentMonth = @json($currentMonth);

    const el = (id) => document.getElementById(id);
    const identifier = el('lookupIdentifier');
    const feedback = el('lookupFeedback');
    const history = el('lookupHistory');
    const historyList = el('lookupHistoryList');
    const formCard = el('formCard');
    const formHint = el('formHint');
    const keepBox = el('keepPixBox');
    const keepCheckbox = el('keepPixCheckbox');
    const keepInput = el('keepPixKeyInput');
    const keepInfo = el('keepPixInfo');
    const pixFields = el('pixFields');
    const pixKey = el('pixKey');
    const pixKeyConfirmation = el('pixKeyConfirmation');

    const busStatusUrl = @json(route('driver-pix.bus-status', $link->token));

    /** Trava nome e CPF quando o cadastro já existe. */
    function lockIdentity(lock) {
        [el('fullName'), el('cpf')].forEach((campo) => {
            if (!campo) return;
            campo.readOnly = lock;
            campo.classList.toggle('bg-slate-100', lock);
            campo.classList.toggle('text-slate-500', lock);
            campo.classList.toggle('cursor-not-allowed', lock);
            campo.classList.toggle('bg-slate-50', !lock);
        });
        el('lockedHint')?.classList.toggle('hidden', !lock);
    }

    /** Mostra quanto o carro fez no mês e o prêmio que isso dá. */
    function renderBusPrize(carro) {
        const box = el('busPrizeBox');
        if (!box) return;

        if (!carro) {
            box.classList.add('hidden');
            return;
        }

        const premiado = Number(carro.premio) > 0;
        box.className = 'mt-4 rounded-2xl border p-3.5 ' +
            (premiado ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-slate-50');

        el('busPrizeTitle').textContent = 'Carro ' + carro.carro + ' · ' + carro.mes;
        el('busPrizeTitle').className = 'text-[10px] font-bold uppercase tracking-widest ' +
            (premiado ? 'text-emerald-700' : 'text-slate-400');

        el('busRevenue').textContent = carro.receita_fmt;
        el('busPrize').textContent = carro.premio_fmt;
        el('busPrize').className = 'text-xl font-bold ' + (premiado ? 'text-emerald-700' : 'text-slate-400');

        let nota;
        if (premiado && carro.motoristas > 1) {
            nota = 'O prêmio é do carro e dividido entre os ' + carro.motoristas +
                   ' motoristas cadastrados nele: <strong>' + carro.premio_por_motorista_fmt +
                   '</strong> para cada um.';
        } else if (premiado) {
            nota = 'O prêmio é do carro. Se outro motorista também se cadastrar neste carro, o valor é dividido entre vocês.';
        } else if (carro.falta_fmt) {
            nota = 'Faltam <strong>' + carro.falta_fmt + '</strong> em vendas neste carro para alcançar o prêmio de ' +
                   carro.proximo_premio_fmt + '.';
        } else {
            nota = 'Este carro ainda não atingiu nenhuma faixa de prêmio neste mês.';
        }

        nota += '<br><span class="text-slate-400">Valor parcial do mês — o fechamento é no fim do mês.</span>';
        el('busPrizeNote').innerHTML = nota;
        el('busPrizeNote').className = 'text-[11px] mt-2 leading-relaxed ' +
            (premiado ? 'text-emerald-900' : 'text-slate-500');

        box.classList.remove('hidden');
    }

    /** Consulta o carro digitado (usado quando o motorista troca de carro). */
    async function fetchBusPrize(numero) {
        const limpo = (numero || '').trim();
        if (!limpo) { renderBusPrize(null); return; }

        try {
            const resp = await fetch(busStatusUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ bus_number: limpo }),
            });
            const data = await resp.json();
            renderBusPrize(data?.carro || null);
        } catch (e) {
            renderBusPrize(null);
        }
    }

    // Trocar a chave PIX: abre os campos de digitação.
    document.addEventListener('DOMContentLoaded', function () {
        el('changePixBtn')?.addEventListener('click', function () {
            if (keepCheckbox) keepCheckbox.checked = false;
            applyKeepState();
            el('pixKey')?.focus();
        });

        // Trocou de carro: recalcula o prêmio daquele carro.
        let tempo = null;
        el('busNumber')?.addEventListener('input', function () {
            clearTimeout(tempo);
            const valor = this.value;
            tempo = setTimeout(() => fetchBusPrize(valor), 600);
        });
    });

    function maskPhone(value) {
        let v = value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 10) return v.replace(/^(\d{2})(\d{5})(\d{4}).*/, '($1) $2-$3');
        if (v.length > 6) return v.replace(/^(\d{2})(\d{4})(\d{0,4}).*/, '($1) $2-$3');
        if (v.length > 2) return v.replace(/^(\d{2})(\d{0,5})/, '($1) $2');
        return v;
    }

    function maskCpf(value) {
        let v = value.replace(/\D/g, '').slice(0, 11);
        if (v.length > 9) return v.replace(/^(\d{3})(\d{3})(\d{3})(\d{0,2}).*/, '$1.$2.$3-$4');
        if (v.length > 6) return v.replace(/^(\d{3})(\d{3})(\d{0,3})/, '$1.$2.$3');
        if (v.length > 3) return v.replace(/^(\d{3})(\d{0,3})/, '$1.$2');
        return v;
    }

    function looksLikePhone(digits) {
        return digits.length >= 10 && digits.length <= 11 && digits[2] === '9';
    }

    el('phone')?.addEventListener('input', (e) => { e.target.value = maskPhone(e.target.value); });
    el('cpf')?.addEventListener('input', (e) => { e.target.value = maskCpf(e.target.value); });
    identifier?.addEventListener('input', (e) => {
        const digits = e.target.value.replace(/\D/g, '');
        e.target.value = looksLikePhone(digits) ? maskPhone(digits) : maskCpf(digits);
    });

    function showForm() {
        formCard.classList.remove('hidden');
        formHint.classList.add('hidden');
        el('referenceMonth').value = currentMonth;
    }

    function applyKeepState() {
        const keep = keepCheckbox.checked && !keepBox.classList.contains('hidden');
        keepInput.value = keep ? '1' : '0';
        pixFields.classList.toggle('hidden', keep);
        pixKey.required = !keep;
        pixKeyConfirmation.required = !keep;
        if (keep) {
            pixKey.value = '';
            pixKeyConfirmation.value = '';
        }
    }

    keepCheckbox?.addEventListener('change', applyKeepState);

    function showFeedback(message, tone) {
        const tones = {
            success: 'bg-emerald-50 border border-emerald-200 text-emerald-800',
            warn: 'bg-amber-50 border border-amber-200 text-amber-800',
            error: 'bg-red-50 border border-red-200 text-red-700',
        };
        feedback.className = 'mt-3 text-xs rounded-xl px-3 py-2.5 leading-relaxed ' + (tones[tone] || tones.warn);
        feedback.innerHTML = message;
        feedback.classList.remove('hidden');
    }

    function fillFromIdentifier(value) {
        const digits = value.replace(/\D/g, '');
        const formatted = (value || '').trim();
        // CPF formatado (000.000.000-00) ou 11 dígitos sem padrão de celular → só CPF
        const isCpfInput = /^\d{3}\.\d{3}\.\d{3}-\d{2}$/.test(formatted)
            || (digits.length === 11 && !looksLikePhone(digits));

        if (isCpfInput) {
            if (!el('cpf').value) el('cpf').value = maskCpf(digits);
            return;
        }

        // Telefone (10 ou 11 dígitos com 9 após o DDD)
        if (digits.length >= 10 && digits.length <= 11) {
            if (!el('phone').value) el('phone').value = maskPhone(digits);
        }
    }

    function clearFormFields() {
        ['fullName', 'cpf', 'phone', 'busNumber', 'pixKey', 'pixKeyConfirmation'].forEach((id) => {
            if (el(id)) el(id).value = '';
        });
        keepBox.classList.add('hidden');
        keepCheckbox.checked = false;
        applyKeepState();
    }

    async function runLookup() {
        const value = (identifier.value || '').trim();
        const digits = value.replace(/\D/g, '');
        if (digits.length < 10) {
            showFeedback('Digite o CPF completo ou o telefone com DDD.', 'warn');
            return;
        }

        const btn = el('lookupBtn');
        btn.disabled = true;
        btn.textContent = '...';

        try {
            const res = await fetch(lookupUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({ identifier: value }),
            });

            const data = await res.json();
            showForm();

            if (!res.ok || !data.found) {
                clearFormFields();
                fillFromIdentifier(value);
                history.classList.add('hidden');
                lockIdentity(false);
                renderBusPrize(null);
                showFeedback(data.message || 'Não encontramos seu cadastro. Preencha os campos abaixo.', 'warn');
                focusFirstEmpty();
                return;
            }

            const p = data.profile;
            el('fullName').value = p.full_name || '';
            el('cpf').value = p.cpf || '';
            el('phone').value = p.phone || '';
            el('busNumber').value = p.bus_number || '';
            fillFromIdentifier(value);
            lockIdentity(true);
            renderBusPrize(data.carro || null);

            if (p.has_pix_key) {
                keepBox.classList.remove('hidden');
                keepCheckbox.checked = true;
                const valor = el('keepPixValue');
                if (valor) valor.textContent = p.pix_key_masked || '';
                keepInfo.textContent = 'Tipo: ' + (p.pix_key_type || 'chave');
            } else {
                keepBox.classList.add('hidden');
                keepCheckbox.checked = false;
            }
            applyKeepState();

            showFeedback(
                '<strong>Cadastro encontrado:</strong> ' + (p.full_name || 'motorista') +
                ' — ' + p.status_label + '.<br>Complete o que estiver em branco e confira o ônibus deste mês.',
                'success'
            );

            if (data.months && data.months.length) {
                historyList.innerHTML = data.months.map((m) => {
                    const tone = m.status === 'approved'
                        ? 'bg-emerald-100 text-emerald-800'
                        : (m.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-800');
                    return '<span class="px-2 py-1 rounded-lg text-[10px] font-bold ' + tone + '">' +
                        m.label + ' · carro ' + m.bus_number + ' · ' + m.status_label + '</span>';
                }).join('');
                history.classList.remove('hidden');
            } else {
                history.classList.add('hidden');
            }

            focusFirstEmpty();
        } catch (err) {
            showForm();
            clearFormFields();
            fillFromIdentifier(value);
            lockIdentity(false);
            showFeedback('Não foi possível consultar agora. Preencha o formulário manualmente.', 'error');
            focusFirstEmpty();
        } finally {
            btn.disabled = false;
            btn.textContent = 'Buscar';
        }
    }

    function focusFirstEmpty() {
        const order = ['fullName', 'cpf', 'phone', 'busNumber', 'pixKey'];
        for (const id of order) {
            const field = el(id);
            if (field && !field.value && !field.closest('.hidden')) {
                field.focus();
                field.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
        }
    }

    el('lookupBtn')?.addEventListener('click', runLookup);
    identifier?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            runLookup();
        }
    });

    el('pixForm')?.addEventListener('submit', function () {
        el('fullName').value = el('fullName').value.toUpperCase();
        el('busNumber').value = el('busNumber').value.toUpperCase();
        el('referenceMonth').value = currentMonth;
        applyKeepState();
        el('submitBtn').disabled = true;
        el('submitBtn').textContent = 'Enviando...';
        setTimeout(() => {
            el('submitBtn').disabled = false;
            el('submitBtn').textContent = el('submitBtn').dataset.label || 'Enviar';
        }, 6000);
    });

    if (@json($hasOld)) {
        applyKeepState();
    } else {
        applyKeepState();
    }
})();
</script>
</body>
</html>
