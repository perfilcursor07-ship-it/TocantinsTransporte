@extends('layouts.admin')

@php
    $editing = $trip->exists;
    $isAdmin = auth()->user()->role === 'admin';
    $input = 'w-full px-3 py-2 text-sm text-ink bg-surface border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-green/30 focus:border-green transition-all';
    $label = 'block text-[11px] font-semibold text-ink2 uppercase tracking-wider mb-1';
    $req = '<span class="text-red normal-case">*</span>';
    $dt = fn ($field) => old($field, $trip->{$field}?->format('Y-m-d\TH:i'));
    $coverage = old('coverage', $trip->coverage ?? 'all_days');
    $maxDiscount = \App\Models\TourismTrip::MAX_DISCOUNT;
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

    <div class="flex items-center justify-between gap-3 mb-4">
        <div>
            <h1 class="text-lg font-bold text-ink">{{ $editing ? $trip->title : 'Nova viagem' }}</h1>
            <p class="text-xs text-muted">
                @if($editing)
                    {{ $trip->contract_number ? 'Contrato '.$trip->contract_number.' · ' : '' }}{{ $trip->statusLabel() }}
                @else
                    Preencha com os dados do contrato. O valor é calculado sozinho.
                @endif
            </p>
        </div>
        <a href="{{ route('admin.tourism.index') }}" class="px-3 py-1.5 border border-border text-ink2 font-semibold text-xs rounded-lg hover:border-green hover:text-green transition-colors">Voltar</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-xl border border-green/20 bg-green-pale px-4 py-2.5 text-sm text-green font-medium">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-xl border border-red/20 bg-red-pale px-4 py-2.5 text-xs text-red font-medium">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <ul class="mb-4 rounded-xl border border-red/20 bg-red-pale px-4 py-2.5 text-xs text-red font-medium space-y-0.5">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    @endif

    <form id="tourism-form" action="{{ $editing ? route('admin.tourism.update', $trip) : route('admin.tourism.store') }}" method="POST"
          class="grid grid-cols-1 lg:grid-cols-3 gap-5 items-start"
          data-price-day="{{ $pricing['price_24h'] }}" data-max-discount="{{ $maxDiscount }}">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="{{ $editing ? 'lg:col-span-2' : 'lg:col-span-3' }} space-y-5">
        <div class="bg-white rounded-xl border border-border shadow-card p-5">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-4">
                <div class="col-span-2 md:col-span-3">
                    <label for="title" class="{{ $label }}">Contratante / grupo {!! $req !!}</label>
                    <input type="text" name="title" id="title" value="{{ old('title', $trip->title) }}" maxlength="120" required autofocus
                           class="{{ $input }}" placeholder="Ex.: Sônia Maria — Campos do Jordão / Gramado">
                </div>
                <div class="col-span-2 md:col-span-1">
                    <label for="contract_number" class="{{ $label }}">Nº do contrato</label>
                    <input type="text" name="contract_number" id="contract_number" value="{{ old('contract_number', $trip->contract_number) }}" maxlength="30"
                           class="{{ $input }}" placeholder="01/2026">
                </div>

                <div class="col-span-2 md:col-span-3">
                    <label for="bus_id" class="{{ $label }}">Ônibus {!! $req !!}</label>
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
                <div class="col-span-2 md:col-span-1">
                    <label for="passengers_count" class="{{ $label }}">Passageiros {!! $req !!}</label>
                    <input type="number" name="passengers_count" id="passengers_count" value="{{ old('passengers_count', $trip->passengers_count) }}" min="1" max="200" required
                           class="{{ $input }}" placeholder="53">
                </div>

                <div class="col-span-2">
                    <label for="responsible_name" class="{{ $label }}">Quem vai pagar {!! $req !!}</label>
                    <input type="text" name="responsible_name" id="responsible_name" value="{{ old('responsible_name', $trip->responsible_name) }}" maxlength="120" required
                           class="{{ $input }}" placeholder="Líder da viagem">
                </div>
                <div class="col-span-2">
                    <label for="responsible_phone" class="{{ $label }}">WhatsApp de quem paga {!! $req !!}</label>
                    <input type="tel" name="responsible_phone" id="responsible_phone" value="{{ old('responsible_phone', $trip->responsible_phone) }}" maxlength="20" required
                           class="{{ $input }}" placeholder="(63) 9 0000-0000">
                </div>

                <div class="col-span-2 md:col-span-4">
                    <p class="{{ $label }}">Internet liberada {!! $req !!}</p>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach(\App\Models\TourismTrip::COVERAGES as $key => $text)
                            <label class="flex items-center gap-2 rounded-lg border-2 border-border bg-surface px-3 py-2 cursor-pointer has-[:checked]:border-green has-[:checked]:bg-green-pale transition-colors">
                                <input type="radio" name="coverage" value="{{ $key }}" class="accent-green" @checked($coverage === $key) required>
                                <span class="text-sm font-semibold text-ink">{{ $text }}</span>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="col-span-1">
                    <label for="starts_at" class="{{ $label }}">Ida — saída {!! $req !!}</label>
                    <input type="datetime-local" name="starts_at" id="starts_at" value="{{ $dt('starts_at') }}" required class="{{ $input }}">
                </div>
                <div class="col-span-1 round-trip-only">
                    <label for="outbound_arrives_at" class="{{ $label }}">Ida — chegada {!! $req !!}</label>
                    <input type="datetime-local" name="outbound_arrives_at" id="outbound_arrives_at" value="{{ $dt('outbound_arrives_at') }}" class="{{ $input }}">
                </div>
                <div class="col-span-1 round-trip-only">
                    <label for="return_departs_at" class="{{ $label }}">Volta — saída {!! $req !!}</label>
                    <input type="datetime-local" name="return_departs_at" id="return_departs_at" value="{{ $dt('return_departs_at') }}" class="{{ $input }}">
                </div>
                <div class="col-span-1">
                    <label for="ends_at" class="{{ $label }}">Volta — chegada {!! $req !!}</label>
                    <input type="datetime-local" name="ends_at" id="ends_at" value="{{ $dt('ends_at') }}" required class="{{ $input }}">
                </div>
                <!-- Só em "todos os dias da viagem" (o JS esconde em ida e volta) -->
                <div class="col-span-1" id="discount-field">
                    <label for="discount_percent" class="{{ $label }}">Desconto <span class="text-muted normal-case font-normal">(até {{ $maxDiscount }}%)</span></label>
                    <div class="relative">
                        <input type="number" name="discount_percent" id="discount_percent" min="0" max="{{ $maxDiscount }}" step="0.5"
                               value="{{ old('discount_percent', (float) $trip->discount_percent ?: '') }}" class="{{ $input }} pr-8" placeholder="0">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-sm font-bold text-ink2 pointer-events-none">%</span>
                    </div>
                </div>

                <div class="col-span-2 md:col-span-4">
                    <label for="notes" class="{{ $label }}">Observações <span class="text-muted normal-case font-normal">(opcional)</span></label>
                    <textarea name="notes" id="notes" rows="2" maxlength="2000" class="{{ $input }}"
                              placeholder="Ex.: embarque no Posto Rodopetro">{{ old('notes', $trip->notes) }}</textarea>
                </div>
            </div>
        </div>

        <!-- Valor (ao vivo; o servidor refaz o mesmo cálculo ao salvar) -->
        <div class="bg-white rounded-xl border border-border shadow-card p-5 grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <p class="text-[11px] font-bold text-muted uppercase tracking-wider">Valor da internet</p>
                <p class="text-[11px] text-muted mb-3">Diárias de 24h × {{ $money($pricing['price_24h']) }} por passageiro</p>
                <div id="plan-estimate" class="text-xs text-ink2 space-y-1"></div>
            </div>

            <div class="md:border-l md:border-border md:pl-5">
                <div class="flex items-end justify-between gap-3">
                    <span class="text-[11px] font-bold text-muted uppercase tracking-wider">Total</span>
                    <span id="total-value" class="text-2xl font-bold text-green">—</span>
                </div>

                <button type="submit" class="mt-3 w-full bg-green text-white font-bold text-sm py-3 rounded-lg hover:bg-green-light transition-colors">
                    {{ $editing ? 'Salvar alterações' : 'Cadastrar viagem' }}
                </button>
                @unless($editing)
                    <p class="text-[11px] text-muted text-center mt-2">Depois de salvar, gere o link de pagamento PIX.</p>
                @endunless
            </div>
        </div>
        </div>

        <!-- Lateral (só ao editar): pagamento e área do administrador -->
        @if($editing)
        <div class="space-y-5 lg:sticky lg:top-4">
                @php
                    $waDigits = preg_replace('/\D/', '', (string) $trip->responsible_phone);
                    $waDigits = strlen($waDigits) <= 11 ? '55'.$waDigits : $waDigits;
                    $waText = "Olá {$trip->responsible_name}! Segue o link para pagar a internet WiFi da viagem \"{$trip->title}\""
                        .' ('.$money($trip->agreed_amount).'): '.$trip->paymentUrl();
                @endphp
                <div class="bg-white rounded-xl border-2 {{ $trip->isPaid() ? 'border-green' : 'border-gold/40' }} shadow-card overflow-hidden" id="payment-card">
                    <div class="border-b border-border px-5 py-3 flex items-center justify-between gap-2">
                        <h2 class="text-sm font-bold text-ink">Pagamento</h2>
                        @if($trip->hasPaymentLink())
                            <span class="text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded {{ $trip->isPaid() ? 'bg-green/10 text-green' : 'bg-gold-pale text-gold border border-gold/20' }}">
                                {{ $trip->paymentStatusLabel() }}
                            </span>
                        @endif
                    </div>
                    <div class="p-5 space-y-3">
                        @if($trip->isPaid())
                            <div class="rounded-lg bg-green-pale border border-green/20 px-3 py-2.5 text-xs text-green font-semibold">
                                Pago em {{ $trip->paid_at?->format('d/m/Y H:i') }} · {{ $money($trip->paid_amount) }}
                            </div>
                        @elseif($trip->price_24h === null)
                            <p class="text-[11px] text-muted">Salve a viagem para o sistema calcular o valor e liberar o link de pagamento.</p>
                        @elseif($trip->status === 'cancelled')
                            <p class="text-[11px] text-muted">Viagem cancelada.</p>
                        @elseif(! $trip->hasPaymentLink())
                            <button type="submit" form="payment-generate-form"
                                    class="w-full bg-gold text-white font-bold text-sm py-2.5 rounded-lg hover:opacity-90 transition-opacity">
                                Gerar link de pagamento (PIX)
                            </button>
                            <p class="text-[11px] text-muted">Gera um PIX PagBank no valor total salvo. Envie o link para {{ $trip->responsible_name }}.</p>
                        @else
                            <div>
                                <label class="{{ $label }}">Link para quem vai pagar</label>
                                <div class="flex gap-2">
                                    <input type="text" readonly value="{{ $trip->paymentUrl() }}" id="payment-url" class="{{ $input }} !text-xs" onclick="this.select()">
                                    <button type="button" id="copy-payment-url" class="px-3 rounded-lg border border-border text-xs font-semibold text-ink2 hover:border-green hover:text-green transition-colors">Copiar</button>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <a href="https://wa.me/{{ $waDigits }}?text={{ rawurlencode($waText) }}" target="_blank" rel="noopener"
                                   class="text-center bg-green text-white font-semibold text-xs py-2 rounded-lg hover:bg-green-light transition-colors">Enviar no WhatsApp</a>
                                <a href="{{ $trip->paymentUrl() }}" target="_blank" rel="noopener"
                                   class="text-center border border-border text-ink2 font-semibold text-xs py-2 rounded-lg hover:border-green hover:text-green transition-colors">Abrir link</a>
                            </div>
                            <button type="submit" form="payment-check-form" class="w-full text-xs font-semibold text-blue hover:underline">
                                Verificar pagamento agora
                            </button>
                            <p class="text-[11px] text-muted">Quando o PIX for pago, o status muda sozinho para <strong class="text-green">Pago</strong>. Se o valor mudar antes disso, o mesmo link passa a cobrar o novo valor.</p>
                        @endif
                    </div>
                </div>

            @if($isAdmin)
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
            @endif
        </div>
        @endif
    </form>

    @if($editing)
        <form id="payment-generate-form" action="{{ route('admin.tourism.payment.generate', $trip) }}" method="POST" class="hidden">@csrf</form>
        <form id="payment-check-form" action="{{ route('admin.tourism.payment.check', $trip) }}" method="POST" class="hidden">@csrf</form>
    @endif
</div>

<script>
(function () {
    const form = document.getElementById('tourism-form');
    const $ = id => document.getElementById(id);
    const price24 = Number(form.dataset.priceDay), maxDiscount = Number(form.dataset.maxDiscount);
    const money = v => v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    const checked = name => (form.querySelector(`input[name="${name}"]:checked`) || {}).value;
    const round2 = v => Math.round(v * 100) / 100;
    const show = (el, on) => { el.style.display = on ? '' : 'none'; };

    // Mesmo cálculo do servidor (TourismTrip::estimateWindows / quote): sempre diárias de 24h.
    function days(start, end) {
        return Math.max(1, Math.ceil(Math.ceil((end - start) / 3600000) / 24));
    }

    function update() {
        const roundTrip = checked('coverage') === 'round_trip', allDays = checked('coverage') === 'all_days';
        document.querySelectorAll('.round-trip-only').forEach(el => {
            show(el, roundTrip);
            el.querySelector('input').required = roundTrip;
        });
        show($('discount-field'), allDays);

        const box = $('plan-estimate');
        const start = new Date($('starts_at').value), end = new Date($('ends_at').value);
        let windows = [];
        if (roundTrip) {
            const oa = new Date($('outbound_arrives_at').value), rd = new Date($('return_departs_at').value);
            if (start < oa && oa <= rd && rd < end) windows = [['Ida', start, oa], ['Volta', rd, end]];
        } else if (allDays && start < end) {
            windows = [['Viagem', start, end]];
        }
        const pax = Number($('passengers_count').value) || 0;
        if (!windows.length || !pax) {
            box.innerHTML = '<p class="text-muted">Preencha passageiros e datas para ver o valor.</p>';
            $('total-value').textContent = '—';
            return;
        }

        let perPassenger = 0;
        const rows = windows.map(([label, a, b]) => {
            const n = days(a, b), price = round2(n * price24);
            perPassenger += price;
            return [`${label}: ${n} ${n === 1 ? 'diária' : 'diárias'}`, money(price)];
        });
        perPassenger = round2(perPassenger);
        const subtotal = round2(perPassenger * pax);
        const pct = allDays ? Math.min(maxDiscount, Math.max(0, Number($('discount_percent').value) || 0)) : 0;
        const discount = round2(subtotal * pct / 100);

        rows.push([`${pax} passageiros × ${money(perPassenger)}`, money(subtotal)]);
        if (pct) rows.push([`Desconto ${pct.toLocaleString('pt-BR')}%`, '− ' + money(discount)]);
        box.innerHTML = rows.map(([a, b]) => `<p class="flex justify-between gap-2"><span>${a}</span><span class="font-semibold">${b}</span></p>`).join('');
        $('total-value').textContent = money(round2(subtotal - discount));
    }

    const copy = $('copy-payment-url');
    if (copy) copy.addEventListener('click', () => {
        const url = $('payment-url');
        url.select();
        (navigator.clipboard ? navigator.clipboard.writeText(url.value) : Promise.reject())
            .catch(() => document.execCommand('copy'))
            .finally(() => { copy.textContent = 'Copiado!'; setTimeout(() => copy.textContent = 'Copiar', 2000); });
    });

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();
})();
</script>
@endsection
