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
        @keyframes fade-up { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-up { animation: fade-up .35s ease-out both; }
    </style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-white to-slate-100 min-h-screen flex flex-col">
@php
    $hasOld = (bool) old('full_name');
    $currentMonthLabel = $currentMonthLabel ?? \App\Models\DriverPixProfileMonth::labelFor(now());
    $ehMesPassado = $ehMesPassado ?? false;
@endphp
<main class="flex-1 p-4 sm:p-6 lg:p-8 pb-20 animate-fade-up">
<div class="w-full max-w-4xl mx-auto">
    <div class="text-center mb-4">
        <div class="w-9 h-9 bg-emerald-600 rounded-xl flex items-center justify-center mx-auto mb-2 shadow-md shadow-emerald-200">
            <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h1 class="text-base font-bold text-slate-800">Cadastro PIX — Motorista</h1>
        <p class="text-[11px] text-slate-500 mt-0.5">
            Confirme seus dados de <strong class="text-slate-700">{{ $currentMonthLabel }}</strong>
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
    <div class="bg-white rounded-2xl shadow-md shadow-slate-200/60 border border-slate-100 p-3 mb-3">
        <div class="flex items-start gap-3">
            <div class="w-8 h-8 rounded-lg bg-blue-50 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="text-[13px] font-bold text-slate-800">Comece pela busca</h2>
                <p class="text-[11px] text-slate-500 mt-0.5">Digite seu <strong>CPF</strong> ou <strong>telefone</strong>.</p>

                <div class="flex gap-2 mt-3">
                    <input type="text" id="lookupIdentifier" inputmode="numeric" placeholder="CPF ou telefone com DDD"
                           class="flex-1 px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 focus:bg-white transition">
                    <button type="button" id="lookupBtn"
                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-sm font-bold whitespace-nowrap transition disabled:opacity-60">
                        Buscar
                    </button>
                </div>

                <div id="lookupFeedback" class="hidden mt-2.5 text-[11px] rounded-lg px-2.5 py-2 leading-snug"></div>

            </div>
        </div>
    </div>

    {{-- ETAPA 2: formulário (só após busca ou erro de validação) --}}
    <div id="formCard" class="bg-white rounded-2xl shadow-md shadow-slate-200/60 border border-slate-100 overflow-hidden {{ $hasOld ? '' : 'hidden' }}">
        <form action="{{ route('driver-pix.store', $link->token) }}" method="POST" id="pixForm">
            @csrf
            <input type="hidden" name="keep_pix_key" id="keepPixKeyInput" value="{{ old('keep_pix_key', '0') }}">
            <input type="hidden" name="reference_month" id="referenceMonth" value="{{ old('reference_month', $currentMonth) }}">

            <div class="px-3 pt-3 pb-1">
                <div class="flex items-center justify-between gap-3 mb-2.5">
                    <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-600">Seus dados</p>
                    <span class="text-[10px] font-semibold text-slate-500 bg-slate-100 px-2 py-1 rounded-lg">{{ $currentMonthLabel }}</span>
                </div>
                {{-- Campos lado a lado. Nome e CPF ficam travados quando o
                     cadastro foi encontrado: o motorista só ajusta telefone e carro. --}}
                <div class="grid grid-cols-2 gap-2">
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Nome completo <span class="text-red-500">*</span></label>
                        <input type="text" name="full_name" id="fullName" value="{{ old('full_name') }}" required placeholder="Seu nome completo"
                               class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-[13px] focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition uppercase">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">CPF <span class="text-red-500">*</span></label>
                        <input type="text" name="cpf" id="cpf" inputmode="numeric" value="{{ old('cpf') }}" required placeholder="000.000.000-00"
                               class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-[13px] focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Telefone (WhatsApp) <span class="text-red-500">*</span></label>
                        <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" required placeholder="(63) 99999-9999"
                               class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-[13px] focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="block text-[11px] font-semibold text-slate-600 mb-0.5">Número do ônibus / carro <span class="text-red-500">*</span></label>
                        <input type="text" name="bus_number" id="busNumber" value="{{ old('bus_number') }}" required placeholder="Ex: 5013"
                               class="w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-[13px] focus:ring-2 focus:ring-emerald-500 focus:border-emerald-500 focus:bg-white transition uppercase font-semibold tracking-wide">
                        <p class="text-[10px] text-slate-400 mt-0.5">Mude se trocou de carro.</p>
                    </div>
                </div>

                <p id="lockedHint" class="hidden text-[10px] text-slate-400 mt-1.5 leading-snug">
                    Nome e CPF não mudam aqui. Se estiver errado, fale com a administração.
                </p>

                {{-- Quanto o carro fez no mês e o prêmio que isso dá --}}
                <div id="busPrizeBox" class="hidden mt-2 rounded-xl border p-2.5">
                    <p class="text-[10px] font-bold uppercase tracking-wider" id="busPrizeTitle"></p>
                    <div class="flex items-end justify-between gap-3 mt-1">
                        <div>
                            <p class="text-[10px] text-slate-500">Este carro já fez</p>
                            <p class="text-base font-bold text-slate-800 leading-tight" id="busRevenue"></p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] text-slate-500">Prêmio do carro</p>
                            <p class="text-base font-bold leading-tight" id="busPrize"></p>
                        </div>
                    </div>
                    <p class="text-[10px] mt-1.5 leading-snug" id="busPrizeNote"></p>
                </div>
                <div id="paymentNotice" class="hidden mt-2 rounded-xl border px-3 py-2 text-sm leading-relaxed">
                    <p id="paymentNoticeText"></p>
                </div>

            <div class="px-3 py-3 mt-1 bg-slate-50/80 border-t border-slate-100">
                <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-600 mb-4">Chave PIX para receber</p>

                {{-- Chave já cadastrada: mostra e pede confirmação explícita. --}}
                <div id="keepPixBox" class="hidden mb-2 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-2.5">
                    <div class="flex items-center justify-between gap-2">
                        <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-700">Sua chave cadastrada</p>
                        <span class="text-[10px] text-emerald-800 bg-emerald-100 px-1.5 py-0.5 rounded" id="keepPixInfo"></span>
                    </div>

                    <p class="text-sm font-bold text-slate-900 mt-0.5 break-all leading-snug" id="keepPixValue"></p>
                    <p class="text-[10px] text-slate-500 mt-0.5">Confira se está exatamente igual à do seu banco.</p>

                    <label class="flex items-start gap-2 mt-2 cursor-pointer">
                        <input type="checkbox" id="keepPixCheckbox" checked
                               class="mt-0.5 w-4 h-4 flex-shrink-0 rounded border-slate-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-[13px] font-semibold text-slate-800 leading-snug">
                            Confirmo que o pagamento é para esta chave
                        </span>
                    </label>

                    <button type="button" id="changePixBtn"
                            class="mt-1 text-[11px] font-semibold text-slate-500 underline hover:text-slate-700">
                        Trocar minha chave PIX
                    </button>
                </div>

                <div id="pixFields" class="space-y-3">
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

            <div class="px-3 pb-3 pt-2">
                <button type="submit" id="submitBtn" disabled data-label="Confirmar dados de {{ $currentMonthLabel }}" class="w-full bg-emerald-600 hover:bg-emerald-700 active:scale-[0.99] text-white py-3 rounded-xl font-bold text-sm transition-all shadow-md shadow-emerald-200/50 disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-none">
                    Confirmar dados de {{ $currentMonthLabel }}
                </button>
            </div>
        </form>
    </div>

    <p id="formHint" class="text-center text-xs text-slate-400 mt-6 leading-relaxed {{ $hasOld ? 'hidden' : '' }}">
        Depois da busca, os campos aparecem para você completar o que faltar.
    </p>
</div>
</main>

<script>
(function () {
    const lookupUrl = @json(route('driver-pix.lookup', $link->token));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    const currentMonth = @json($currentMonth);

    const el = (id) => document.getElementById(id);
    const identifier = el('lookupIdentifier');
    const feedback = el('lookupFeedback');
    const formCard = el('formCard');
    const formHint = el('formHint');
    const keepBox = el('keepPixBox');
    const keepCheckbox = el('keepPixCheckbox');
    const keepInput = el('keepPixKeyInput');
    const keepInfo = el('keepPixInfo');
    const pixFields = el('pixFields');
    const pixKey = el('pixKey');
    const pixKeyConfirmation = el('pixKeyConfirmation');
    let carroAtingiuMeta = false;
    let pagamentoBloqueia = false;

    const busStatusUrl = @json(route('driver-pix.bus-status', $link->token));

    function updateSubmitButton() {
        const button = el('submitBtn');
        if (!button) return;
        button.disabled = !carroAtingiuMeta || pagamentoBloqueia;
    }

    function renderPayment(payment) {
        const notice = el('paymentNotice');
        const text = el('paymentNoticeText');
        pagamentoBloqueia = Boolean(payment);
        if (!notice || !text) {
            updateSubmitButton();
            return;
        }

        if (!payment) {
            notice.classList.add('hidden');
            updateSubmitButton();
            return;
        }

        text.textContent = payment.message || 'Já existe um pagamento para este mês e carro. Caso ainda não tenha recebido, aguarde o pagamento.';
        notice.className = 'mt-3 rounded-xl border px-3 py-2.5 text-sm leading-relaxed ' +
            (payment.status === 'paid'
                ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
                : 'border-amber-200 bg-amber-50 text-amber-900');
        updateSubmitButton();
    }

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
            carroAtingiuMeta = false;
            updateSubmitButton();
            return;
        }

        const premiado = Number(carro.premio) > 0;
        carroAtingiuMeta = premiado;
        updateSubmitButton();
        box.className = 'mt-4 rounded-2xl border p-3.5 ' +
            (premiado ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-slate-50');

        el('busPrizeTitle').textContent = 'Carro ' + carro.carro + ' · ' + carro.mes;
        el('busPrizeTitle').className = 'text-[10px] font-bold uppercase tracking-widest ' +
            (premiado ? 'text-emerald-700' : 'text-slate-400');

        el('busRevenue').textContent = carro.receita_fmt;
        el('busPrize').textContent = carro.premio_fmt;
        el('busPrize').className = 'text-xl font-bold ' + (premiado ? 'text-emerald-700' : 'text-slate-400');

        let nota;
        if (premiado) {
            nota = 'Observação: o prêmio será dividido entre os motoristas do carro, conforme a quantidade cadastrada.';
        } else if (carro.falta_fmt) {
            nota = 'Faltam <strong>' + carro.falta_fmt + '</strong> em vendas neste carro para alcançar o prêmio de ' +
                   carro.proximo_premio_fmt + '.';
        } else {
            nota = 'Este carro ainda não atingiu nenhuma faixa de prêmio neste mês.';
        }

        if (!premiado) {
            nota += '<br><strong>A confirmação será liberada quando o carro alcançar a meta mínima de ' +
                    carro.meta_minima_fmt + ' em vendas.</strong>';
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
        if (!limpo) {
            renderBusPrize(null);
            renderPayment(null);
            return;
        }

        try {
            const body = { bus_number: limpo };
            const identifierValue = el('cpf')?.value || el('phone')?.value || '';
            if (identifierValue.replace(/\D/g, '').length >= 10) {
                body.identifier = identifierValue;
            }

            const resp = await fetch(busStatusUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrf,
                },
                body: JSON.stringify(body),
            });
            const data = await resp.json();
            renderBusPrize(data?.carro || null);
            renderPayment(data?.pagamento || null);
        } catch (e) {
            renderBusPrize(null);
            renderPayment(null);
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
            renderBusPrize(null);
            renderPayment(null);
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
                lockIdentity(false);
                renderBusPrize(null);
                renderPayment(null);
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
            renderPayment(data.pagamento || null);

            if (p.has_pix_key) {
                keepBox.classList.remove('hidden');
                keepCheckbox.checked = true;
                const valor = el('keepPixValue');
                if (valor) valor.textContent = p.pix_key || p.pix_key_masked || '';
                keepInfo.textContent = p.pix_key_type || 'chave';
            } else {
                keepBox.classList.add('hidden');
                keepCheckbox.checked = false;
            }
            applyKeepState();

            showFeedback(
                data.pagamento
                    ? data.pagamento.message
                    : '<strong>' + (p.full_name || 'Motorista') + '</strong> · ' + p.status_label +
                      '. Confira o carro e a chave PIX.',
                data.pagamento ? 'warn' : 'success'
            );

            focusFirstEmpty();
        } catch (err) {
            showForm();
            clearFormFields();
            fillFromIdentifier(value);
            lockIdentity(false);
            renderBusPrize(null);
            renderPayment(null);
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

    el('pixForm')?.addEventListener('submit', function (event) {
        if (!carroAtingiuMeta || pagamentoBloqueia) {
            event.preventDefault();
            updateSubmitButton();
            return;
        }

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
        fetchBusPrize(el('busNumber')?.value || '');
    } else {
        applyKeepState();
    }
})();
</script>
</body>
</html>
