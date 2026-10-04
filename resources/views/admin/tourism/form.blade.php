@extends('layouts.admin')

@php
    $editing = $trip->exists;
    $isAdmin = auth()->user()->role === 'admin';
    $input = 'w-full px-3 py-2.5 text-sm text-ink bg-surface border border-border rounded-lg focus:outline-none focus:ring-2 focus:ring-green/30 focus:border-green transition-all';
    $label = 'block text-[11px] font-semibold text-ink2 uppercase tracking-wider mb-1.5';
    $dt = fn ($field) => old($field, $trip->{$field}?->format('Y-m-d\TH:i'));
    $paymentMode = old('payment_mode', $trip->payment_mode);
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
            <p class="text-[10px] font-bold uppercase tracking-widest text-white/60 mb-0.5">Turismo · Viagem</p>
            <h1 class="text-xl font-bold text-white">{{ $editing ? $trip->title : 'Nova Viagem' }}</h1>
            <p class="text-xs text-white/70 mt-0.5">
                {{ $editing ? 'Status: '.$trip->statusLabel() : 'Escolha o ônibus, o período e como será o pagamento' }}
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

    <form action="{{ $editing ? route('admin.tourism.update', $trip) : route('admin.tourism.store') }}" method="POST"
          class="grid grid-cols-1 lg:grid-cols-3 gap-5">
        @csrf
        @if($editing) @method('PUT') @endif

        <div class="lg:col-span-2 bg-white rounded-xl border border-border shadow-card overflow-hidden">
            <div class="border-b border-border px-5 py-3">
                <h2 class="text-sm font-bold text-ink">Dados da viagem</h2>
            </div>
            <div class="p-5 space-y-6">

                <!-- 1. Viagem e ônibus -->
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <span class="w-5 h-5 bg-green text-white rounded-full flex items-center justify-center text-[10px] font-bold">1</span>
                        <h3 class="text-[11px] font-bold text-muted uppercase tracking-wider">Viagem e ônibus</h3>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="md:col-span-2">
                            <label for="title" class="{{ $label }}">Nome da viagem / grupo <span class="text-red normal-case">*</span></label>
                            <input type="text" name="title" id="title" value="{{ old('title', $trip->title) }}" maxlength="120" required
                                   class="{{ $input }}" placeholder="Ex.: Excursão Jalapão — Grupo da Igreja">
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
                            <label for="starts_at" class="{{ $label }}">Início da viagem <span class="text-red normal-case">*</span></label>
                            <input type="datetime-local" name="starts_at" id="starts_at" value="{{ $dt('starts_at') }}" required class="{{ $input }}">
                        </div>
                        <div>
                            <label for="ends_at" class="{{ $label }}">Fim da viagem <span class="text-red normal-case">*</span></label>
                            <input type="datetime-local" name="ends_at" id="ends_at" value="{{ $dt('ends_at') }}" required class="{{ $input }}">
                        </div>
                        <div>
                            <label for="passengers_count" class="{{ $label }}">Passageiros <span class="text-muted normal-case font-normal">(opcional)</span></label>
                            <input type="number" name="passengers_count" id="passengers_count" value="{{ old('passengers_count', $trip->passengers_count) }}" min="1" max="200"
                                   class="{{ $input }}" placeholder="Ex.: 40">
                        </div>
                    </div>
                </div>

                <!-- 2. Pagamento e plano -->
                <div>
                    <div class="flex items-center gap-2 mb-4">
                        <span class="w-5 h-5 bg-green text-white rounded-full flex items-center justify-center text-[10px] font-bold">2</span>
                        <h3 class="text-[11px] font-bold text-muted uppercase tracking-wider">Pagamento e plano</h3>
                    </div>

                    <p class="{{ $label }}">Quem paga o WiFi? <span class="text-red normal-case">*</span></p>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4">
                        @foreach(\App\Models\TourismTrip::PAYMENT_MODES as $key => $text)
                            <label class="flex items-start gap-3 rounded-lg border-2 border-border bg-surface p-3 cursor-pointer has-[:checked]:border-green has-[:checked]:bg-green-pale transition-colors">
                                <input type="radio" name="payment_mode" value="{{ $key }}" class="mt-0.5 accent-green" @checked($paymentMode === $key) required>
                                <span>
                                    <span class="block text-sm font-bold text-ink">{{ $text }}</span>
                                    <span class="block text-[11px] text-muted mt-0.5">
                                        {{ $key === 'individual' ? 'Cada passageiro paga pelo portal do WiFi.' : 'Um responsável paga por todos; o administrador libera o ônibus.' }}
                                    </span>
                                </span>
                            </label>
                        @endforeach
                    </div>

                    <div id="group-fields" class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4 rounded-lg border border-green/20 bg-green-pale/40 p-4 {{ $paymentMode === 'group' ? '' : 'hidden' }}">
                        <div>
                            <label for="responsible_name" class="{{ $label }}">Quem vai pagar <span class="text-red normal-case">*</span></label>
                            <input type="text" name="responsible_name" id="responsible_name" value="{{ old('responsible_name', $trip->responsible_name) }}" maxlength="120"
                                   class="{{ $input }}" placeholder="Nome do responsável">
                        </div>
                        <div>
                            <label for="responsible_phone" class="{{ $label }}">Telefone <span class="text-red normal-case">*</span></label>
                            <input type="tel" name="responsible_phone" id="responsible_phone" value="{{ old('responsible_phone', $trip->responsible_phone) }}" maxlength="20"
                                   class="{{ $input }}" placeholder="(63) 9 0000-0000">
                        </div>
                        <div>
                            <label for="agreed_amount" class="{{ $label }}">Valor combinado <span class="text-muted normal-case font-normal">(opcional)</span></label>
                            <input type="number" name="agreed_amount" id="agreed_amount" value="{{ old('agreed_amount', $trip->agreed_amount) }}" min="0" step="0.01"
                                   class="{{ $input }}" placeholder="R$">
                        </div>
                    </div>

                    <div>
                        <label for="plan" class="{{ $label }}">Plano <span class="text-red normal-case">*</span></label>
                        <select name="plan" id="plan" required class="{{ $input }}">
                            @foreach(\App\Models\TourismTrip::PLANS as $key => $text)
                                <option value="{{ $key }}" @selected(old('plan', $trip->plan) === $key)>{{ $text }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <!-- 3. Observações -->
                <div>
                    <label for="notes" class="{{ $label }}">Observações <span class="text-muted normal-case font-normal">(opcional)</span></label>
                    <textarea name="notes" id="notes" rows="3" maxlength="2000" class="{{ $input }}"
                              placeholder="Ex.: saída da rodoviária às 6h, paradas previstas, combinado com o responsável...">{{ old('notes', $trip->notes) }}</textarea>
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
                    <p>1. Você cadastra a viagem, o ônibus e como será o pagamento.</p>
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
    // Mostra os dados do responsável só quando uma pessoa paga por todos.
    document.querySelectorAll('input[name="payment_mode"]').forEach(function (radio) {
        radio.addEventListener('change', function () {
            document.getElementById('group-fields').classList.toggle('hidden', this.value !== 'group');
        });
    });
</script>
@endsection
