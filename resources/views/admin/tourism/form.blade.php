@extends('layouts.admin')

@php
    $editing = $trip->exists;
    $isAdmin = auth()->user()->role === 'admin';
    $input = 'w-full px-3 py-2.5 text-sm text-ink bg-surface border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-green/30 focus:border-green transition-all';
    $label = 'block text-[11px] font-semibold text-ink2 uppercase tracking-wider mb-1.5';
    $dt = fn ($field) => old($field, $trip->{$field}?->format('Y-m-d\TH:i'));
    $paymentMode = old('payment_mode', $trip->payment_mode);
    $coverage = old('coverage', $trip->coverage);
    $money = fn ($v) => 'R$ '.number_format($v, 2, ',', '.');
@endphp

@section('title', $editing ? 'Editar Viagem' : 'Nova Viagem')

@section('breadcrumb')
    <span class="text-muted">›</span>
    <a href="{{ route('admin.tourism.index') }}" class="text-muted hover:text-green transition-colors">Turismo</a>
    <span class="text-muted">›</span>
    <span class="text-green font-semibold">{{ $editing ? 'Editar' : 'Nova' }}</span>
@endsection

@section('page-title', $editing ? 'Editar Viagem' : 'Nova Viagem')

@section('content')
<div class="max-w-8xl mx-auto">

    <div class="bg-gradient-to-r from-green-dark via-green to-green-light rounded-xl px-5 py-4 mb-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-white/60 mb-0.5">
                Turismo · Viagem{{ $editing && $trip->contract_number ? ' · Contrato '.$trip->contract_number : '' }}
            </p>
            <h1 class="text-xl font-bold text-white">{{ $editing ? $trip->title : 'Nova Viagem' }}</h1>
            <p class="text-xs text-white/70 mt-0.5">
                {{ $editing ? 'Status: '.$trip->statusLabel() : 'Use os dados do contrato: ônibus, datas e como será paga a internet' }}
            </p>
        </div>
        <a href="{{ route('admin.tourism.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white/15 border border-white/20 text-white font-semibold text-xs rounded-lg hover:bg-white/25 transition-colors">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            Voltar
        </a>
    </div>

    @if($errors->any())
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red/20 bg-red-pale px-4 py-3">
            <svg class="w-4 h-4 text-red flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            <ul class="text-xs text-red font-medium space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <form id="tourism-form" action="{{ $editing ? route('admin.tourism.update', $trip) : route('admin.tourism.store') }}" method="POST"
          class="grid grid-cols-1 lg:grid-cols-3 gap-5"
          data-price-short="{{ $pricing['price_12h'] }}" data-hours-short="{{ $pricing['hours_12h'] }}" data-price-day="{{ $pricing['price_24h'] }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="lg:col-span-2 bg-white rounded-xl border border-border shadow-card overflow-hidden">
            <div class="border-b border-border px-5 py-3">
                <h2 class="text-sm font-bold text-ink">Dados da viagem</h2>
            </div>
            <div class="p-5 space-y-6">

                <!-- 1. Contrato e ônibus -->
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <span class="w-5 h-5 bg-green text-white rounded-full flex items-center justify-center text-[10px] font-bold">1</span>
                        <h3 class="text-[11px] font-bold text-muted uppercase tracking-wider">Contrato e ônibus</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="contract_number" class="{{ $label }}">Nº do contrato</label>
                            <input type="text" name="contract_number" id="contract_number" value="{{ old('contract_number', $trip->contract_number) }}" maxlength="30"
                                   class="{{ $input }}" placeholder="Ex.: 01/2026">
                        </div>
                        <div class="md:col-span-2">
                            <label for="title" class="{{ $label }}">Contratante / grupo <span class="text-red normal-case">*</span></label>
                            <input type="text" name="title" id="title" value="{{ old('title', $trip->title) }}" maxlength="120" required
                                   class="{{ $input }}" placeholder="Ex.: Sônia Maria — Campos do Jordão / Gramado">
                        </div>
                        <div class="md:col-span-2">
                            <label for="bus_id" class="{{ $label }}">Ônibus <span class="text-red normal-case">*</span></label>
                            @if($buses->isEmpty())
                                <p class="text-xs text-red">Nenhum ônibus ativo cadastrado. Peça ao administrador para cadastrar em MikroTik › Ônibus.</p>
                            @else
                                <select name="bus_id" id="bus_id" required class="{{ $input }}">
                                    <option value="">Escolha o ônibus</option>
                                    @foreach($buses as $bus)
                                        <option value="{{ $bus->id }}" @selected((int) old('bus_id', $trip->bus_id) === $bus->id)>
                                            {{ $bus->name }}{{ $bus->plate ? ' — '.$bus->plate : '' }}{{ $bus->route_description ? ' ('.$bus->route_description.')' : '' }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                        <div>
                            <label for="passengers_count" class="{{ $label }}">Passageiros</label>
                            <input type="number" name="passengers_count" id="passengers_count" value="{{ old('passengers_count', $trip->passengers_count) }}" min="1" max="200"
                                   class="{{ $input }}" placeholder="Ex.: 53">
                        </div>
                    </div>
                </div>

                <!-- 2. Datas -->
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <span class="w-5 h-5 bg-green text-white rounded-full flex items-center justify-center text-[10px] font-bold">2</span>
                        <h3 class="text-[11px] font-bold text-muted uppercase tracking-wider">Datas da viagem (como no contrato)</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label for="starts_at" class="{{ $label }}">Ida — saída <span class="text-red normal-case">*</span></label>
                            <input type="datetime-local" name="starts_at" id="starts_at" value="{{ $dt('starts_at') }}" required class="{{ $input }}">
                        </div>
                        <div>
                            <label for="ends_at" class="{{ $label }}">Volta — chegada <span class="text-red normal-case">*</span></label>
                            <input type="datetime-local" name="ends_at" id="ends_at" value="{{ $dt('ends_at') }}" required class="{{ $input }}">
                        </div>
                    </div>
                </div>

                <!-- 3. Pagamento da internet -->
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <span class="w-5 h-5 bg-green text-white rounded-full flex items-center justify-center text-[10px] font-bold">3</span>
                        <h3 class="text-[11px] font-bold text-muted uppercase tracking-wider">Pagamento da internet</h3>
                    </div>

                    <p class="{{ $label }}">Quem paga o WiFi? <span class="text-red normal-case">*</span></p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                        @foreach(\App\Models\TourismTrip::PAYMENT_MODES as $key => $text)
                            <label class="flex items-start gap-3 rounded-lg border-2 border-border bg-surface p-3 cursor-pointer has-[:checked]:border-green has-[:checked]:bg-green-pale transition-colors">
                                <input type="radio" name="payment_mode" value="{{ $key }}" class="mt-0.5 accent-green" @checked($paymentMode === $key) required>
                                <span>
                                    <span class="block text-sm font-bold text-ink">{{ $text }}</span>
                                    <span class="block text-[11px] text-muted mt-0.5">
                                        {{ $key === 'individual' ? 'Cada passageiro escolhe e paga o próprio plano no portal do WiFi.' : 'Um responsável paga a internet de todos; o administrador libera o ônibus.' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <!-- Cada passageiro paga: só uma explicação -->
                    <div id="individual-info" class="rounded-lg border border-blue/20 bg-blue-pale/50 px-4 py-3 text-xs text-ink2 {{ $paymentMode === 'group' ? 'hidden' : '' }}">
                        Não é preciso escolher plano: cada passageiro escolhe no portal
                        (<strong>{{ $pricing['hours_12h'] }} horas por {{ $money($pricing['price_12h']) }}</strong> ou
                        <strong>vários dias por {{ $money($pricing['price_24h']) }} cada 24 horas</strong>).
                    </div>

                    <!-- Uma pessoa paga tudo -->
                    <div id="group-fields" class="space-y-4 {{ $paymentMode === 'group' ? '' : 'hidden' }}">
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 rounded-lg border border-green/20 bg-green-pale/40 p-4">
                            <div>
                                <label for="responsible_name" class="{{ $label }}">Quem vai pagar <span class="text-red normal-case">*</span></label>
                                <input type="text" name="responsible_name" id="responsible_name" value="{{ old('responsible_name', $trip->responsible_name) }}" maxlength="120"
                                       class="{{ $input }}" placeholder="Líder da viagem">
                            </div>
                            <div>
                                <label for="responsible_phone" class="{{ $label }}">Telefone <span class="text-red normal-case">*</span></label>
                                <input type="tel" name="responsible_phone" id="responsible_phone" value="{{ old('responsible_phone', $trip->responsible_phone) }}" maxlength="20"
                                       class="{{ $input }}" placeholder="(63) 9 0000-0000">
                            </div>
                            <div>
                                <label for="agreed_amount" class="{{ $label }}">Valor combinado</label>
                                <input type="number" name="agreed_amount" id="agreed_amount" value="{{ old('agreed_amount', $trip->agreed_amount) }}" min="0" step="0.01"
                                       class="{{ $input }}" placeholder="R$">
                            </div>
                        </div>

                        <div>
                            <p class="{{ $label }}">A internet paga cobre <span class="text-red normal-case">*</span></p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                @foreach(\App\Models\TourismTrip::COVERAGES as $key => $text)
                                    <label class="flex items-start gap-3 rounded-lg border-2 border-border bg-surface p-3 cursor-pointer has-[:checked]:border-green has-[:checked]:bg-green-pale transition-colors">
                                        <input type="radio" name="coverage" value="{{ $key }}" class="mt-0.5 accent-green" @checked($coverage === $key)>
                                        <span>
                                            <span class="block text-sm font-bold text-ink">{{ $text }}</span>
                                            <span class="block text-[11px] text-muted mt-0.5">
                                                {{ $key === 'all_days' ? 'Internet liberada da saída da ida até a chegada da volta, todos os dias.' : 'Internet só nos trechos da ida e da volta. Nos dias entre eles, não.' }}
                                            </span>
                                        </span>
                                    </label>
                                @endforeach
                            </div>
                        </div>

                        <div id="round-trip-fields" class="grid grid-cols-1 md:grid-cols-2 gap-4 {{ $coverage === 'round_trip' ? '' : 'hidden' }}">
                            <div>
                                <label for="outbound_arrives_at" class="{{ $label }}">Ida — chegada <span class="text-red normal-case">*</span></label>
                                <input type="datetime-local" name="outbound_arrives_at" id="outbound_arrives_at" value="{{ $dt('outbound_arrives_at') }}" class="{{ $input }}">
                            </div>
                            <div>
                                <label for="return_departs_at" class="{{ $label }}">Volta — saída <span class="text-red normal-case">*</span></label>
                                <input type="datetime-local" name="return_departs_at" id="return_departs_at" value="{{ $dt('return_departs_at') }}" class="{{ $input }}">
                            </div>
                        </div>

                        <!-- Cálculo pelos preços do portal (ao vivo) -->
                        <div class="rounded-lg border border-border bg-surface p-4">
                            <p class="text-[11px] font-bold text-muted uppercase tracking-wider mb-2">Cálculo pelos preços do portal</p>
                            <p class="text-[11px] text-muted mb-3">
                                Trecho de até {{ $pricing['hours_12h'] }}h = plano de {{ $pricing['hours_12h'] }} horas ({{ $money($pricing['price_12h']) }}).
                                Acima disso = diárias de 24h ({{ $money($pricing['price_24h']) }} cada), sempre 24 horas cheias.
                            </p>
                            <div id="plan-estimate" class="text-sm text-ink2 space-y-1">
                                <p class="text-muted text-xs">Preencha as datas para ver o cálculo.</p>
                            </div>
                            <button type="button" id="use-estimate" class="hidden mt-3 text-xs font-semibold text-green hover:underline">
                                Usar este cálculo em "Como será o plano"
                            </button>
                        </div>

                        <div>
                            <label for="plan_details" class="{{ $label }}">Como será o plano de internet <span class="text-red normal-case">*</span></label>
                            <textarea name="plan_details" id="plan_details" rows="4" maxlength="2000" class="{{ $input }}"
                                      placeholder="Ex.: Sônia paga a internet dos 53 passageiros só na ida e na volta. Ida: 04/01 06:00 até 05/01 09:00 (2 diárias de 24h). Volta: 15/01 09:00 até 16/01 21:00 (2 diárias de 24h). Valor combinado: R$ ...">{{ old('plan_details', $trip->plan_details) }}</textarea>
                            <p class="text-[11px] text-muted mt-1">Escreva de forma que o administrador saiba exatamente quando liberar o ônibus e o que foi combinado.</p>
                        </div>
                    </div>
                </div>

                <!-- Observações -->
                <div>
                    <label for="notes" class="{{ $label }}">Observações <span class="text-muted normal-case font-normal">(opcional)</span></label>
                    <textarea name="notes" id="notes" rows="3" maxlength="2000" class="{{ $input }}"
                              placeholder="Ex.: embarque no Posto Rodopetro, paradas previstas...">{{ old('notes', $trip->notes) }}</textarea>
                </div>
            </div>
        </div>

        <!-- Lateral -->
        <div class="space-y-5">
            @if($isAdmin && $editing)
                <div class="bg-white rounded-xl border-2 border-green/30 shadow-card overflow-hidden">
                    <div class="border-b border-border px-5 py-3 bg-green-pale/50">
                        <h2 class="text-sm font-bold text-ink">Área do administrador</h2>
                    </div>
                    <div class="p-5 space-y-4">
                        <div>
                            <label for="status" class="{{ $label }}">Status</label>
                            <select name="status" id="status" class="{{ $input }}">
                                @foreach(\App\Models\TourismTrip::STATUSES as $key => $text)
                                    <option value="{{ $key }}" @selected(old('status', $trip->status) === $key)>{{ $text }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="admin_notes" class="{{ $label }}">Anotações do administrador</label>
                            <textarea name="admin_notes" id="admin_notes" rows="4" maxlength="2000" class="{{ $input }}"
                                      placeholder="Ex.: ônibus liberado sem cobrança de 10/10 a 12/10.">{{ old('admin_notes', $trip->admin_notes) }}</textarea>
                        </div>
                        <p class="text-[11px] text-muted">Por enquanto o cadastro não muda nada no MikroTik. A configuração do carro é feita por você.</p>
                    </div>
                </div>
            @else
                <div class="bg-white rounded-xl border border-border shadow-card p-5 text-xs text-muted space-y-2">
                    <p class="font-bold text-ink text-sm">Como funciona</p>
                    <p>1. Você cadastra a viagem com os dados do contrato.</p>
                    <p>2. A viagem fica <strong class="text-gold">Aguardando administrador</strong>.</p>
                    <p>3. O administrador configura o ônibus e marca como <strong class="text-green">Configurada</strong>.</p>
                    <p>Enquanto estiver aguardando, você pode editar ou excluir.</p>
                </div>
            @endif

            <button type="submit" class="w-full bg-green text-white font-bold text-sm py-3 rounded-lg hover:bg-green-light transition-colors shadow-card">
                {{ $editing ? 'Salvar alterações' : 'Cadastrar viagem' }}
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const form = document.getElementById('tourism-form');
    const $ = id => document.getElementById(id);
    // (data-price-12h não viraria dataset.price12h: hífen + número não é convertido)
    const price12 = Number(form.dataset.priceShort), hours12 = Number(form.dataset.hoursShort), price24 = Number(form.dataset.priceDay);
    const money = v => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const fmt = d => d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }).replace(',', '');
    const checked = name => (form.querySelector(`input[name="${name}"]:checked`) || {}).value;
    let lastText = '';

    // Mesmo cálculo do servidor (TourismTrip::estimateWindows).
    function estimate(start, end) {
        const hours = Math.ceil((end - start) / 3600000);
        if (hours <= hours12) return { hours, plan: `plano de ${hours12} horas`, price: price12 };
        const days = Math.ceil(hours / 24);
        return { hours, plan: `${days} ${days === 1 ? 'diária' : 'diárias'} de 24h`, price: days * price24 };
    }

    function update() {
        const group = checked('payment_mode') === 'group';
        const roundTrip = checked('coverage') === 'round_trip';
        $('group-fields').classList.toggle('hidden', !group);
        $('individual-info').classList.toggle('hidden', group);
        $('round-trip-fields').classList.toggle('hidden', !roundTrip);

        const box = $('plan-estimate');
        const start = new Date($('starts_at').value), end = new Date($('ends_at').value);
        let windows = [];
        if (roundTrip) {
            const oa = new Date($('outbound_arrives_at').value), rd = new Date($('return_departs_at').value);
            if (start < oa && oa <= rd && rd < end) windows = [['Ida', start, oa], ['Volta', rd, end]];
        } else if (checked('coverage') === 'all_days' && start < end) {
            windows = [['Viagem inteira', start, end]];
        }
        if (!windows.length) {
            box.innerHTML = '<p class="text-muted text-xs">Escolha o que a internet cobre e preencha as datas (em ordem) para ver o cálculo.</p>';
            $('use-estimate').classList.add('hidden');
            return;
        }

        let total = 0;
        const lines = windows.map(([label, a, b]) => {
            const e = estimate(a, b);
            total += e.price;
            return { text: `${label}: ${fmt(a)} até ${fmt(b)} (${e.hours}h) = ${e.plan} — ${money(e.price)}`, label, e };
        });
        const pax = Number($('passengers_count').value) || 0;
        const totalText = `Por passageiro: ${money(total)}` + (pax ? ` · ${pax} passageiros: ${money(total * pax)}` : '');
        box.innerHTML = lines.map(l => `<p>${l.text}</p>`).join('') + `<p class="font-bold text-ink pt-1">${totalText}</p>`;

        lastText = `${$('responsible_name').value || 'O responsável'} paga a internet ${roundTrip ? 'só na ida e na volta' : 'de todos os dias da viagem'}`
            + (pax ? ` para ${pax} passageiros` : '') + '.\n' + lines.map(l => l.text).join('\n') + '\n' + totalText + ' (preços do portal).';
        $('use-estimate').classList.remove('hidden');
    }

    $('use-estimate').addEventListener('click', () => { $('plan_details').value = lastText; });
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
})();
</script>
@endsection
