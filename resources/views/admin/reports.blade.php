@extends('layouts.admin')

@section('title', 'Relatórios')

@section('breadcrumb')
    <span class="text-muted">›</span>
    <span class="text-green font-semibold">Relatórios</span>
@endsection

@section('page-title', 'Relatórios')

@push('scripts')
    <script src="{{ asset('js/reports.js') }}"></script>
@endpush

@section('content')
<div class="ui-modern space-y-6">
    @if(session('success'))
        <div class="flex items-center gap-3 rounded-2xl bg-emerald-50 ring-1 ring-emerald-200 px-4 py-3 text-sm text-emerald-800 font-semibold">
            <span class="w-8 h-8 rounded-xl bg-emerald-500 text-white flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            </span>
            {{ session('success') }}
        </div>
    @endif

    @if(session('error'))
        <div class="flex items-center gap-3 rounded-2xl bg-red-50 ring-1 ring-red-200 px-4 py-3 text-sm text-red-800 font-semibold">
            <span class="w-8 h-8 rounded-xl bg-red-500 text-white flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
            </span>
            {{ session('error') }}
        </div>
    @endif

    @php
        $periodLabel = \Carbon\Carbon::parse($startDate)->format('d/m/Y H:i') . ' — ' . \Carbon\Carbon::parse($endDate)->format('d/m/Y H:i');
        $fieldClass = 'w-full px-3 py-2.5 text-sm text-ink bg-gray-50 ring-1 ring-black/[0.08] border-0 rounded-xl focus:outline-none focus:ring-2 focus:ring-emerald-500 focus:bg-white transition-all';
        $labelClass = 'block text-xs font-semibold text-ink2 mb-1.5';
    @endphp

    <!-- Filtros Avançados -->
    <section class="rep-card rounded-2xl overflow-hidden">
        <button type="button" id="toggleAdvancedFilters"
                class="w-full flex items-center justify-between gap-3 px-5 py-4 hover:bg-gray-50/70 transition-colors">
            <div class="flex items-center gap-3 min-w-0">
                <span class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L13 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 017 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                </span>
                <div class="text-left min-w-0">
                    <p class="text-base font-bold text-ink leading-tight">Filtros</p>
                    <p class="text-xs text-muted truncate">Período: {{ $periodLabel }}</p>
                </div>
            </div>
            <span class="w-8 h-8 rounded-lg bg-gray-100 flex items-center justify-center flex-shrink-0">
                <svg id="filterChevron" class="w-4 h-4 text-muted transition-transform duration-200" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </span>
        </button>
        <div id="advancedFiltersPanel" class="border-t border-black/[0.06] px-5 py-5 hidden">
        <form method="GET" action="{{ route('admin.reports') }}">
            <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4 mb-5">
                <div>
                    <label class="{{ $labelClass }}">Data e hora inicial</label>
                    <input type="datetime-local" name="start_date" value="{{ $startDate }}" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Data e hora final</label>
                    <input type="datetime-local" name="end_date" value="{{ $endDate }}" class="{{ $fieldClass }}">
                </div>
                <div>
                    <label class="{{ $labelClass }}">Status do pagamento</label>
                    <select name="payment_status" class="{{ $fieldClass }}">
                        <option value="all" {{ $paymentStatus == 'all' ? 'selected' : '' }}>Todos</option>
                        <option value="pending" {{ $paymentStatus == 'pending' ? 'selected' : '' }}>Pendente</option>
                        <option value="completed" {{ $paymentStatus == 'completed' ? 'selected' : '' }}>Pago</option>
                        <option value="refunded" {{ $paymentStatus == 'refunded' ? 'selected' : '' }}>Estorno</option>
                        <option value="cancelled" {{ $paymentStatus == 'cancelled' ? 'selected' : '' }}>Cancelado</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Ônibus</label>
                    <select name="bus" class="{{ $fieldClass }}">
                        <option value="all" {{ ($busFilter ?? 'all') == 'all' ? 'selected' : '' }}>Todos os Ônibus</option>
                        @foreach($busList as $bus)
                            <option value="{{ $bus->mikrotik_serial }}" {{ ($busFilter ?? '') == $bus->mikrotik_serial ? 'selected' : '' }}>
                                {{ $bus->name }}
                            </option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <button type="submit" class="inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-sm px-5 py-2.5 rounded-xl shadow-sm shadow-emerald-600/30 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    Aplicar filtros
                </button>
                <a href="{{ route('admin.reports') }}" class="inline-flex items-center gap-2 bg-white ring-1 ring-black/10 text-ink2 font-bold text-sm px-5 py-2.5 rounded-xl hover:bg-gray-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    Limpar
                </a>
            </div>
        </form>
        </div>
    </section>

    <!-- Cards de Estatísticas -->
    <section class="rep-card rounded-2xl grid grid-cols-2 lg:grid-cols-5 divide-black/[0.06] lg:divide-x overflow-hidden">

        <!-- Líquido (destaque) -->
        <div class="col-span-2 lg:col-span-1 px-4 py-3 bg-emerald-50/60 border-b lg:border-b-0 border-black/[0.06]">
            <p class="text-[11px] font-semibold text-emerald-700 uppercase tracking-wide">Receita líquida</p>
            <p class="mt-0.5 text-xl font-extrabold text-emerald-700 tracking-tight leading-tight">R$ {{ number_format($stats['total_revenue'], 2, ',', '.') }}</p>
            <p class="text-[11px] text-muted">Ticket médio R$ {{ number_format($stats['avg_payment'], 2, ',', '.') }}</p>
            @if(($stats['refunded_revenue'] ?? 0) > 0)
            <p class="text-[10px] text-red-600">Bruto R$ {{ number_format($stats['completed_revenue'] ?? 0, 2, ',', '.') }} − Estornos R$ {{ number_format($stats['refunded_revenue'], 2, ',', '.') }}</p>
            @endif
        </div>

        <div class="px-4 py-3 border-b lg:border-b-0 border-r lg:border-r-0 border-black/[0.06]">
            <p class="text-[11px] font-semibold text-muted uppercase tracking-wide">Pendente</p>
            <p class="mt-0.5 text-xl font-extrabold text-ink tracking-tight leading-tight">R$ {{ number_format($stats['pending_payments'], 2, ',', '.') }}</p>
            <p class="text-[11px] text-muted">Aguardando pagamento</p>
        </div>

        <div class="px-4 py-3 border-b lg:border-b-0 border-black/[0.06]">
            <p class="text-[11px] font-semibold text-muted uppercase tracking-wide">Pagamentos</p>
            <p class="mt-0.5 text-xl font-extrabold text-ink tracking-tight leading-tight">{{ $stats['total_payments'] }}</p>
            <p class="text-[11px] text-muted">
                <span class="font-semibold text-emerald-700">{{ $stats['completed_payments_count'] }} pagos</span> ·
                <span class="font-semibold text-amber-600">{{ $stats['pending_payments_count'] }} pend.</span> ·
                <span class="font-semibold text-red-600">{{ $stats['refunded_payments_count'] ?? 0 }} estornos</span>
            </p>
        </div>

        <div class="px-4 py-3 border-r lg:border-r-0 border-black/[0.06]">
            <p class="text-[11px] font-semibold text-muted uppercase tracking-wide">Usuários</p>
            <p class="mt-0.5 text-xl font-extrabold text-ink tracking-tight leading-tight">{{ $stats['total_users'] }}</p>
            <p class="text-[11px] text-muted"><span class="inline-flex items-center gap-1 font-semibold text-emerald-700"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>{{ $stats['connected_users'] }}</span> conectados agora</p>
        </div>

        <div class="px-4 py-3">
            <p class="text-[11px] font-semibold text-muted uppercase tracking-wide">Sessões</p>
            <p class="mt-0.5 text-xl font-extrabold text-ink tracking-tight leading-tight">{{ $stats['active_sessions'] }}</p>
            <p class="text-[11px] text-muted">No período selecionado</p>
        </div>
    </section>

    <!-- Receita por Ônibus -->
    @if($revenueByBus->count() > 0)
    <section class="rep-card rounded-2xl overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-black/[0.06]">
            <div>
                <h3 class="text-sm font-bold text-ink">Receita por ônibus</h3>
                <p class="text-[11px] text-muted">{{ $periodLabel }}</p>
            </div>
            <span class="text-[11px] text-muted">{{ $revenueByBus->where('total', '>', 0)->count() }} de {{ $revenueByBus->count() }} com receita</span>
        </div>
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 2xl:grid-cols-6 gap-2.5 p-3">
            @php $maxRevenue = $revenueByBus->max('total') ?: 1; @endphp
            @foreach($revenueByBus as $bus)
            @php $share = ($bus->total / max($maxRevenue, 0.01)) * 100; $hasRevenue = $bus->total > 0; @endphp
            <div class="rounded-xl p-3 ring-1 transition-shadow hover:shadow-md {{ $hasRevenue ? 'ring-black/[0.06] bg-white' : 'ring-black/[0.04] bg-gray-50/70' }}" title="{{ $bus->bus_id }}">
                @if(auth()->user()?->role === 'admin' && $bus->bus_id !== 'desconhecido')
                <button type="button"
                        onclick='openBusAdjustModal(@json($bus->bus_id), @json($bus->bus_name))'
                        class="group flex items-center gap-1 max-w-full text-left text-xs font-semibold text-ink2 hover:text-emerald-700"
                        title="Ajustar a receita deste carro no período">
                    <span class="truncate">{{ $bus->bus_name }}</span>
                    <svg class="w-3.5 h-3.5 flex-shrink-0 text-muted group-hover:text-emerald-700" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536M9 13l6.232-6.232a2.5 2.5 0 113.536 3.536L12.536 16.5H9V13z"/></svg>
                </button>
                @else
                <p class="text-xs font-semibold text-ink2 truncate">{{ $bus->bus_name }}</p>
                @endif
                <p class="mt-1 text-base font-extrabold tracking-tight leading-tight {{ $hasRevenue ? 'text-emerald-700' : 'text-gray-400' }}">R$ {{ number_format($bus->total, 2, ',', '.') }}</p>
                <div class="mt-2 h-1 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-emerald-500 rounded-full" style="width: {{ max(0, $share) }}%"></div>
                </div>
                <div class="mt-1.5 flex items-center justify-between text-[10px] text-muted">
                    <span>{{ $bus->count }} pgto{{ $bus->count === 1 ? '' : 's' }}</span>
                    <span class="font-semibold">{{ number_format($share, 0) }}%</span>
                </div>
                @if(($bus->refunded_count ?? 0) > 0)
                <p class="text-[10px] text-red-600 mt-0.5">{{ $bus->refunded_count }} estorno(s) − R$ {{ number_format($bus->refunded_total ?? 0, 2, ',', '.') }}</p>
                @endif
            </div>
            @endforeach
        </div>
    </section>
    @endif

    <!-- Gráficos -->
    <section class="grid grid-cols-1 xl:grid-cols-5 gap-4">
        <div class="rep-card rounded-2xl p-4 xl:col-span-3">
            <div class="flex flex-wrap justify-between items-start gap-2 mb-4">
                <div>
                    <h3 class="text-base font-bold text-ink">Receita por dia</h3>
                    <p class="text-xs text-muted mt-0.5">{{ $periodLabel }}</p>
                </div>
            </div>
            <div class="relative h-56"><canvas id="revenueChart" class="w-full h-full"></canvas></div>
        </div>
        <div class="rep-card rounded-2xl p-4 xl:col-span-2">
            <div class="mb-4">
                <h3 class="text-base font-bold text-ink">Pagamentos por status</h3>
                <p class="text-xs text-muted mt-0.5">Distribuição no período</p>
            </div>
            <div class="relative h-56"><canvas id="paymentsStatusChart" class="w-full h-full"></canvas></div>
        </div>
    </section>

    <!-- Abas de Conteúdo -->
    <section class="rep-card rounded-2xl overflow-hidden">
        <!-- Navegação das Abas -->
        <div class="flex gap-1 px-3 pt-3 border-b border-black/[0.06] bg-gray-50/60">
            <button onclick="showTab('payments')" id="tab-payments"
                    class="tab-button inline-flex items-center gap-2 px-4 py-3 text-sm font-bold rounded-t-xl text-green border-b-2 border-green bg-green-pale transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                Pagamentos
                <span class="rounded-full bg-white/80 ring-1 ring-black/[0.06] px-2 py-0.5 text-[11px] font-bold text-ink2">{{ $payments->total() }}</span>
            </button>
            @if($canViewUsersTab)
            <button onclick="showTab('users')" id="tab-users"
                    class="tab-button inline-flex items-center gap-2 px-4 py-3 text-sm font-bold rounded-t-xl text-muted border-b-2 border-transparent hover:text-ink transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/></svg>
                Usuários
                <span class="rounded-full bg-white/80 ring-1 ring-black/[0.06] px-2 py-0.5 text-[11px] font-bold text-ink2">{{ $users->total() }}</span>
            </button>
            @endif
        </div>

        <!-- Aba de Pagamentos -->
        <div id="content-payments" class="tab-content">
            <div class="flex flex-wrap justify-between items-center gap-3 px-5 py-4">
                <div>
                    <h3 class="text-base font-bold text-ink">Lista de pagamentos</h3>
                    <p class="text-xs text-muted">{{ $periodLabel }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    @if(auth()->user()?->role === 'admin')
                    <form id="bulk-delete-form" method="POST" action="{{ route('admin.reports.payments.bulk-destroy') }}" onsubmit="return confirmBulkDelete();">
                        @csrf
                        @method('DELETE')
                        <button id="bulk-delete-button" type="submit" disabled
                                class="inline-flex items-center gap-1.5 bg-red-50 ring-1 ring-red-200 text-red-700 font-bold text-xs px-3.5 py-2 rounded-xl opacity-50 cursor-not-allowed transition-all">
                            Excluir selecionados (0)
                        </button>
                    </form>
                    @endif
                    <a href="{{ route('admin.reports.export', ['type' => 'payments', 'format' => 'csv', 'start_date' => $startDate, 'end_date' => $endDate, 'payment_status' => $paymentStatus]) }}"
                       class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-3.5 py-2 rounded-xl shadow-sm shadow-emerald-600/30 transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        Exportar CSV
                    </a>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full rep-table">
                    <thead>
                        <tr>
                            @if(auth()->user()?->role === 'admin')
                            <th class="w-10"><input type="checkbox" id="select-all-payments" class="rounded border-border accent-green"></th>
                            @endif
                            <th>ID</th>
                            <th>Usuário</th>
                            <th>Valor</th>
                            <th>Tipo</th>
                            <th>Status</th>
                            <th>Comprovante</th>
                            @if(auth()->user()?->role === 'admin')
                            <th>Veículo</th>
                            @endif
                            <th>Pago em</th>
                            <th>Criado</th>
                            @if(auth()->user()?->role === 'admin')
                            <th></th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $busSerialMap = $busList->pluck('name', 'mikrotik_serial');
                        @endphp
                        @forelse($payments as $payment)
                        <tr>
                            @if(auth()->user()?->role === 'admin')
                            <td><input type="checkbox" class="payment-checkbox rounded border-border accent-green" value="{{ $payment->id }}"></td>
                            @endif
                            <td class="text-xs text-muted font-mono">#{{ $payment->id }}</td>
                            <td>
                                <div class="flex items-center gap-2.5 min-w-[160px]">
                                    <span class="w-8 h-8 rounded-full bg-gray-100 text-ink2 flex items-center justify-center text-xs font-bold flex-shrink-0">{{ strtoupper(mb_substr($payment->user->name ?? '?', 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink truncate">{{ $payment->user->name ?? 'N/A' }}</p>
                                        <p class="text-[11px] text-muted truncate">{{ $payment->user->email ?? 'N/A' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="text-sm font-extrabold text-emerald-700 whitespace-nowrap">R$ {{ number_format($payment->amount, 2, ',', '.') }}</td>
                            <td>
                                <span class="text-[11px] font-bold px-2 py-0.5 rounded-md {{ $payment->payment_type === 'pix' ? 'bg-sky-50 text-sky-700' : 'bg-emerald-50 text-emerald-700' }}">
                                    {{ $payment->payment_type === 'pix' ? 'PIX' : 'Cartão' }}
                                </span>
                            </td>
                            <td>
                                @php
                                    $stMap = [
                                        'completed' => 'bg-emerald-50 text-emerald-700 ring-emerald-200',
                                        'pending'   => 'bg-amber-50 text-amber-700 ring-amber-200',
                                        'failed'    => 'bg-red-50 text-red-700 ring-red-200',
                                        'refunded'  => 'bg-red-50 text-red-700 ring-red-200',
                                        'cancelled' => 'bg-gray-100 text-muted ring-gray-200',
                                    ];
                                    $stDot = [
                                        'completed' => 'bg-emerald-500',
                                        'pending'   => 'bg-amber-500',
                                        'failed'    => 'bg-red-500',
                                        'refunded'  => 'bg-red-500',
                                        'cancelled' => 'bg-gray-400',
                                    ];
                                    $stLabel = [
                                        'completed' => 'Pago',
                                        'pending' => 'Pendente',
                                        'failed' => 'Falhou',
                                        'refunded' => 'Estorno',
                                        'cancelled' => 'Cancelado',
                                    ];
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2 py-0.5 rounded-full ring-1 whitespace-nowrap {{ $stMap[$payment->status] ?? 'bg-gray-100 text-muted ring-gray-200' }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $stDot[$payment->status] ?? 'bg-gray-400' }}"></span>
                                    {{ $stLabel[$payment->status] ?? ucfirst($payment->status) }}
                                </span>
                                @if($payment->status === 'refunded' && $payment->refunded_at)
                                    <p class="text-[11px] text-muted mt-1">{{ $payment->refunded_at->format('d/m/Y H:i') }}</p>
                                @endif
                            </td>
                            <td>
                                @if($payment->status === 'refunded' && $payment->hasRefundReceipt())
                                    <a href="{{ route('admin.reports.payments.refund-receipt', $payment) }}"
                                       target="_blank"
                                       class="inline-flex items-center gap-1 text-[11px] font-bold bg-sky-50 text-sky-700 px-2.5 py-1 rounded-lg hover:bg-sky-100 transition-colors whitespace-nowrap">
                                        Ver comprovante
                                    </a>
                                @elseif($payment->status === 'refunded')
                                    <span class="text-xs text-muted">Sem anexo</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                            @if(auth()->user()?->role === 'admin')
                            <td>
                                @php
                                    $serial = data_get($payment->payment_data, 'transferred_mikrotik_id') ?: ($payment->user->last_mikrotik_id ?? null);
                                    $busName = $serial ? ($busSerialMap[$serial] ?? null) : null;
                                    $vehicleLabel = $busName ? "{$busName} ({$serial})" : ($serial ?: 'Sem veículo');
                                @endphp
                                @if($busName)
                                    <span class="inline-flex items-center gap-1 text-[11px] font-bold bg-sky-50 text-sky-700 px-2 py-0.5 rounded-md whitespace-nowrap">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8m-8 4h8m-4 4v4m-4-4h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                                        {{ $busName }}
                                    </span>
                                    <p class="text-[11px] text-muted font-mono mt-0.5">{{ $serial }}</p>
                                @elseif($serial)
                                    <span class="text-xs text-muted font-mono">{{ $serial }}</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                            @endif
                            <td class="text-xs text-ink2 whitespace-nowrap">{{ $payment->paid_at ? $payment->paid_at->format('d/m/Y H:i') : '—' }}</td>
                            <td class="text-xs text-muted whitespace-nowrap">{{ $payment->created_at->format('d/m/Y H:i') }}</td>
                            @if(auth()->user()?->role === 'admin')
                            <td>
                                <div class="relative flex justify-end">
                                    <button type="button"
                                            onclick="togglePaymentActions('payment-actions-{{ $payment->id }}')"
                                            class="inline-flex items-center gap-1.5 text-xs font-bold bg-white ring-1 ring-black/10 text-ink2 px-3 py-1.5 rounded-lg hover:bg-gray-50 transition-colors">
                                        Ações
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                    <div id="payment-actions-{{ $payment->id }}"
                                         class="payment-actions-menu hidden absolute right-0 top-full mt-1.5 z-30 w-48 overflow-hidden rounded-xl ring-1 ring-black/10 bg-white shadow-xl p-1">
                                        <button type="button"
                                                onclick='openPaymentEditModal(@json($payment->id), @json($serial), @json($vehicleLabel), @json((float) $payment->amount))'
                                                class="block w-full px-3 py-2 text-left text-xs font-semibold text-ink2 hover:bg-gray-50 rounded-lg">
                                            Editar
                                        </button>
                                        @if(in_array($payment->status, ['pending', 'failed', 'refunded'], true))
                                        <form method="POST" action="{{ route('admin.reports.payments.toggle-status', $payment) }}"
                                              onsubmit="return confirm('Marcar pagamento #{{ $payment->id }} como PAGO?\nO valor entrará nos totais de receita.');">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="completed">
                                            <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-semibold text-emerald-700 hover:bg-emerald-50 rounded-lg">
                                                Marcar pago
                                            </button>
                                        </form>
                                        @endif
                                        @if($payment->status === 'completed')
                                        <form method="POST" action="{{ route('admin.reports.payments.toggle-status', $payment) }}"
                                              onsubmit="return confirm('Marcar pagamento #{{ $payment->id }} como PENDENTE?\nO valor sairá dos totais de receita.');">
                                            @csrf
                                            @method('PATCH')
                                            <input type="hidden" name="status" value="pending">
                                            <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-semibold text-amber-700 hover:bg-amber-50 rounded-lg">
                                                Marcar pendente
                                            </button>
                                        </form>
                                        <button type="button"
                                                onclick="openRefundModal({{ $payment->id }}, '{{ number_format($payment->amount, 2, ',', '.') }}')"
                                                class="block w-full px-3 py-2 text-left text-xs font-semibold text-red-600 hover:bg-red-50 rounded-lg">
                                            Estorno
                                        </button>
                                        @endif
                                        <div class="my-1 h-px bg-black/[0.06]"></div>
                                        <form method="POST" action="{{ route('admin.reports.payments.destroy', $payment) }}" onsubmit="return confirm('Excluir este registro de pagamento?');">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="block w-full px-3 py-2 text-left text-xs font-semibold text-red-600 hover:bg-red-50 rounded-lg">
                                                Excluir
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                            @endif
                        </tr>
                        @empty
                        <tr>
                            <td colspan="{{ auth()->user()?->role === 'admin' ? 11 : 8 }}" class="py-14 text-center">
                                <div class="w-14 h-14 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-7 h-7 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                                </div>
                                <p class="text-sm font-medium text-muted">Nenhum pagamento encontrado no período.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($payments->hasPages())
            <div class="px-5 py-4 border-t border-black/[0.06] flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-muted">Mostrando <span class="font-bold text-ink2">{{ $payments->firstItem() }}–{{ $payments->lastItem() }}</span> de <span class="font-bold text-ink2">{{ $payments->total() }}</span></p>
                <div class="flex items-center gap-1.5">
                    @if($payments->onFirstPage())
                        <span class="px-3.5 py-2 rounded-xl bg-gray-100 text-gray-400 text-xs font-bold">← Anterior</span>
                    @else
                        <a href="{{ $payments->previousPageUrl() }}" class="px-3.5 py-2 rounded-xl bg-white ring-1 ring-black/10 text-ink2 hover:bg-gray-50 text-xs font-bold transition-colors">← Anterior</a>
                    @endif
                    <span class="px-3.5 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">
                        {{ $payments->currentPage() }} / {{ $payments->lastPage() }}
                    </span>
                    @if($payments->hasMorePages())
                        <a href="{{ $payments->nextPageUrl() }}" class="px-3.5 py-2 rounded-xl bg-white ring-1 ring-black/10 text-ink2 hover:bg-gray-50 text-xs font-bold transition-colors">Próxima →</a>
                    @else
                        <span class="px-3.5 py-2 rounded-xl bg-gray-100 text-gray-400 text-xs font-bold">Próxima →</span>
                    @endif
                </div>
            </div>
            @endif
        </div>

        @if($canViewUsersTab)
        <!-- Aba de Usuários -->
        <div id="content-users" class="tab-content hidden">
            <div class="flex flex-wrap justify-between items-center gap-3 px-5 py-4">
                <div>
                    <h3 class="text-base font-bold text-ink">Lista de usuários</h3>
                    <p class="text-xs text-muted">{{ $periodLabel }}</p>
                </div>
                <a href="{{ route('admin.reports.export', ['type' => 'users', 'format' => 'csv', 'start_date' => $startDate, 'end_date' => $endDate, 'user_status' => $userStatus]) }}"
                   class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs px-3.5 py-2 rounded-xl shadow-sm shadow-emerald-600/30 transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    Exportar CSV
                </a>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full rep-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Usuário</th>
                            <th>MAC</th>
                            <th>Status</th>
                            <th>Conectado</th>
                            <th>Expira</th>
                            <th>Cadastro</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($users as $user)
                        <tr>
                            <td class="text-xs text-muted font-mono">#{{ $user->id }}</td>
                            <td>
                                <div class="flex items-center gap-2.5 min-w-[160px]">
                                    <span class="w-8 h-8 rounded-full bg-gray-100 text-ink2 flex items-center justify-center text-xs font-bold flex-shrink-0">{{ strtoupper(mb_substr($user->name ?? '?', 0, 1)) }}</span>
                                    <div class="min-w-0">
                                        <p class="text-sm font-semibold text-ink truncate">{{ $user->name ?? 'N/A' }}</p>
                                        <p class="text-[11px] text-muted truncate">{{ $user->email ?? ($user->phone ?? 'N/A') }}</p>
                                    </div>
                                </div>
                            </td>
                            <td>
                                @if($user->mac_address)
                                    <span class="text-xs font-mono text-ink2 bg-gray-50 ring-1 ring-black/[0.06] px-2 py-0.5 rounded-md">{{ $user->mac_address }}</span>
                                @else
                                    <span class="text-xs text-gray-300">—</span>
                                @endif
                            </td>
                            <td>
                                @php
                                    $uMap = ['connected'=>'bg-emerald-50 text-emerald-700 ring-emerald-200','active'=>'bg-sky-50 text-sky-700 ring-sky-200','temp_bypass'=>'bg-amber-50 text-amber-700 ring-amber-200'];
                                    $uDot = ['connected'=>'bg-emerald-500','active'=>'bg-sky-500','temp_bypass'=>'bg-amber-500'];
                                    $uLabel = ['connected'=>'Conectado','active'=>'Ativo','temp_bypass'=>'Bypass'];
                                @endphp
                                <span class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2 py-0.5 rounded-full ring-1 whitespace-nowrap {{ $uMap[$user->status] ?? 'bg-gray-100 text-muted ring-gray-200' }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $uDot[$user->status] ?? 'bg-gray-400' }}"></span>
                                    {{ $uLabel[$user->status] ?? ucfirst($user->status) }}
                                </span>
                            </td>
                            <td class="text-xs text-ink2 whitespace-nowrap">{{ $user->connected_at ? $user->connected_at->format('d/m/Y H:i') : '—' }}</td>
                            <td class="text-xs text-ink2 whitespace-nowrap">{{ $user->expires_at ? $user->expires_at->format('d/m/Y H:i') : '—' }}</td>
                            <td class="text-xs text-muted whitespace-nowrap">{{ $user->created_at->format('d/m/Y H:i') }}</td>
                        </tr>
                        @empty
                        <tr>
                            <td colspan="7" class="py-14 text-center">
                                <div class="w-14 h-14 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
                                    <svg class="w-7 h-7 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                </div>
                                <p class="text-sm font-medium text-muted">Nenhum usuário encontrado no período.</p>
                            </td>
                        </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($users->hasPages())
            <div class="px-5 py-4 border-t border-black/[0.06] flex flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-muted">Mostrando <span class="font-bold text-ink2">{{ $users->firstItem() }}–{{ $users->lastItem() }}</span> de <span class="font-bold text-ink2">{{ $users->total() }}</span></p>
                <div class="flex items-center gap-1.5">
                    @if($users->onFirstPage())
                        <span class="px-3.5 py-2 rounded-xl bg-gray-100 text-gray-400 text-xs font-bold">← Anterior</span>
                    @else
                        <a href="{{ $users->previousPageUrl() }}" class="px-3.5 py-2 rounded-xl bg-white ring-1 ring-black/10 text-ink2 hover:bg-gray-50 text-xs font-bold transition-colors">← Anterior</a>
                    @endif
                    <span class="px-3.5 py-2 rounded-xl bg-emerald-600 text-white text-xs font-bold">
                        {{ $users->currentPage() }} / {{ $users->lastPage() }}
                    </span>
                    @if($users->hasMorePages())
                        <a href="{{ $users->nextPageUrl() }}" class="px-3.5 py-2 rounded-xl bg-white ring-1 ring-black/10 text-ink2 hover:bg-gray-50 text-xs font-bold transition-colors">Próxima →</a>
                    @else
                        <span class="px-3.5 py-2 rounded-xl bg-gray-100 text-gray-400 text-xs font-bold">Próxima →</span>
                    @endif
                </div>
            </div>
            @endif
        </div>
        @endif
    </section>
</div>

<div class="ui-modern">
    @if(auth()->user()?->role === 'admin')
    <!-- Modal Editar Pagamento -->
    <div id="payment-edit-modal" class="fixed inset-0 z-[10000] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-black/[0.06]">
                <div>
                    <h3 class="text-base font-bold text-ink">Editar pagamento</h3>
                    <p class="text-xs text-muted">Pagamento #<span id="payment-edit-id-label"></span></p>
                </div>
                <button type="button" onclick="closePaymentEditModal()" class="w-9 h-9 rounded-xl hover:bg-gray-100 text-muted text-lg">×</button>
            </div>
            <form id="payment-edit-form" method="POST" class="px-6 py-5 space-y-4">
                @csrf
                @method('PATCH')
                <div class="rounded-xl bg-gray-50 px-4 py-3">
                    <p class="text-[11px] text-muted font-semibold mb-0.5">Veículo atual</p>
                    <p id="payment-edit-current-vehicle" class="text-sm font-bold text-ink">—</p>
                </div>
                <div>
                    <label for="payment-edit-amount" class="{{ $labelClass }}">Valor pago</label>
                    <div class="flex items-center rounded-xl bg-gray-50 ring-1 ring-black/[0.08] focus-within:ring-2 focus-within:ring-emerald-500 focus-within:bg-white">
                        <span class="pl-3 text-sm font-bold text-muted">R$</span>
                        <input id="payment-edit-amount" type="number" name="amount" required
                               min="0.01" max="99999999.99" step="0.01" inputmode="decimal"
                               class="w-full px-2 py-2.5 text-sm font-bold text-ink bg-transparent border-0 focus:outline-none focus:ring-0"
                               placeholder="0,00">
                    </div>
                    <p class="text-[11px] text-muted mt-1.5">O novo valor será usado no Líquido, Ticket médio e gráficos.</p>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Transferir para veículo</label>
                    <select id="payment-edit-vehicle" name="mikrotik_serial" class="{{ $fieldClass }}">
                        <option value="">Selecione o veículo</option>
                        @foreach($busList as $bus)
                            <option value="{{ $bus->mikrotik_serial }}">{{ $bus->name }} — {{ $bus->mikrotik_serial }}</option>
                        @endforeach
                    </select>
                </div>
                <p class="text-xs text-muted leading-relaxed">
                    Você pode corrigir o valor pago e, se necessário, transferir o registro para outro veículo. O status e as datas do pagamento não serão alterados.
                </p>
                <div class="flex gap-2 pt-1">
                    <button type="button" onclick="closePaymentEditModal()"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl ring-1 ring-black/10 text-ink2 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">
                        Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Estorno -->
    <div id="refund-modal" class="fixed inset-0 z-[10000] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden">
            <div class="flex items-center justify-between px-6 py-4 border-b border-black/[0.06]">
                <div>
                    <h3 class="text-base font-bold text-ink">Registrar estorno</h3>
                    <p class="text-xs text-muted">Pagamento #<span id="refund-payment-id-label"></span> · R$ <span id="refund-amount-label"></span></p>
                </div>
                <button type="button" onclick="closeRefundModal()" class="w-9 h-9 rounded-xl hover:bg-gray-100 text-muted text-lg">×</button>
            </div>
            <form id="refund-form" method="POST" enctype="multipart/form-data" class="px-6 py-5 space-y-4">
                @csrf
                <p class="text-sm text-ink2 leading-relaxed rounded-xl bg-red-50 px-4 py-3">
                    O valor será <strong class="text-red-700">abatido da receita líquida</strong>. O gestor verá o status Estorno e o comprovante (se anexado).
                </p>
                <div>
                    <label class="{{ $labelClass }}">Comprovante (opcional)</label>
                    <input type="file" name="refund_receipt" accept=".jpg,.jpeg,.png,.webp,.pdf,image/*,application/pdf"
                           class="w-full text-xs text-ink file:mr-3 file:py-2 file:px-3 file:rounded-lg file:border-0 file:bg-red-50 file:text-red-700 file:font-bold file:text-xs">
                    <p class="text-[11px] text-muted mt-1.5">JPG, PNG, WEBP ou PDF · máx. 5 MB</p>
                </div>
                <div>
                    <label class="{{ $labelClass }}">Observação (opcional)</label>
                    <input type="text" name="refund_note" maxlength="255" placeholder="Ex: PIX devolvido ao cliente"
                           class="w-full px-3 py-2.5 text-sm text-ink bg-gray-50 ring-1 ring-black/[0.08] border-0 rounded-xl focus:outline-none focus:ring-2 focus:ring-red-400 focus:bg-white">
                </div>
                <div class="flex gap-2 pt-1">
                    <button type="button" onclick="closeRefundModal()"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl ring-1 ring-black/10 text-ink2 hover:bg-gray-50">
                        Cancelar
                    </button>
                    <button type="submit"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl bg-red-600 text-white hover:bg-red-700">
                        Confirmar estorno
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal Ajustar receita do ônibus -->
    @php
        $periodoInicio = $startDate;
        $periodoFim = $endDate;
    @endphp
    <div id="bus-adjust-modal" class="fixed inset-0 z-[10000] hidden items-center justify-center bg-black/50 backdrop-blur-sm p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md max-h-[92vh] overflow-y-auto">
            <div class="flex items-center justify-between px-6 py-4 border-b border-black/[0.06]">
                <div class="min-w-0">
                    <h3 class="text-base font-bold text-ink truncate">Ajustar receita · <span id="bus-adjust-name"></span></h3>
                    <p class="text-xs text-muted">Só no período {{ $periodLabel }}</p>
                </div>
                <button type="button" onclick="closeBusAdjustModal()" class="w-9 h-9 rounded-xl hover:bg-gray-100 text-muted text-lg">×</button>
            </div>

            <div class="px-6 pt-4">
                <div class="grid grid-cols-2 gap-1 p-1 rounded-xl bg-gray-100">
                    <button type="button" id="bus-adjust-tab-add" onclick="showBusAdjustTab('add')"
                            class="px-3 py-2 text-sm font-bold rounded-lg">Acrescentar</button>
                    <button type="button" id="bus-adjust-tab-remove" onclick="showBusAdjustTab('remove')"
                            class="px-3 py-2 text-sm font-bold rounded-lg">Retirar</button>
                </div>
            </div>

            <!-- Acrescentar -->
            <form id="bus-adjust-add-form" method="POST" class="px-6 py-5 space-y-4">
                @csrf
                <input type="hidden" name="start_date" value="{{ $periodoInicio }}">
                <input type="hidden" name="end_date" value="{{ $periodoFim }}">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label for="bus-adjust-qty" class="{{ $labelClass }}">Quantidade</label>
                        <input id="bus-adjust-qty" type="number" name="quantity" required min="1" max="1000" step="1"
                               inputmode="numeric" placeholder="10" oninput="updateBusAdjustTotal()" class="{{ $fieldClass }}">
                    </div>
                    <div>
                        <label for="bus-adjust-amount" class="{{ $labelClass }}">Valor de cada</label>
                        <div class="flex items-center rounded-xl bg-gray-50 ring-1 ring-black/[0.08] focus-within:ring-2 focus-within:ring-emerald-500 focus-within:bg-white">
                            <span class="pl-3 text-sm font-bold text-muted">R$</span>
                            <input id="bus-adjust-amount" type="number" name="unit_amount" required min="0.01" max="999.99" step="0.01"
                                   inputmode="decimal" placeholder="5,99" oninput="updateBusAdjustTotal()"
                                   class="w-full px-2 py-2.5 text-sm font-bold text-ink bg-transparent border-0 focus:outline-none focus:ring-0">
                        </div>
                    </div>
                </div>
                <div>
                    <label for="bus-adjust-date" class="{{ $labelClass }}">Data em que entram</label>
                    <input id="bus-adjust-date" type="datetime-local" name="reference_at" required
                           min="{{ $periodoInicio }}" max="{{ $periodoFim }}" value="{{ $periodoInicio }}" class="{{ $fieldClass }}">
                    <p class="text-[11px] text-muted mt-1.5">Precisa estar dentro do período. É por esta data que o acréscimo conta no mês (inclusive no prêmio do motorista).</p>
                </div>
                <div>
                    <label for="bus-adjust-note" class="{{ $labelClass }}">Observação (opcional)</label>
                    <input id="bus-adjust-note" type="text" name="note" maxlength="255" placeholder="Ex: pagamentos em dinheiro" class="{{ $fieldClass }}">
                </div>
                <p class="text-sm text-ink2 rounded-xl bg-emerald-50 px-4 py-3">
                    Total a somar: <strong class="text-emerald-700">R$ <span id="bus-adjust-total">0,00</span></strong><br>
                    <span class="text-[11px] text-muted">Soma só na receita. Não cria pagamento de passageiro nem libera internet.</span>
                </p>
                <div class="flex gap-2 pt-1">
                    <button type="button" onclick="closeBusAdjustModal()"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl ring-1 ring-black/10 text-ink2 hover:bg-gray-50">Cancelar</button>
                    <button type="submit"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl bg-emerald-600 text-white hover:bg-emerald-700">Acrescentar</button>
                </div>
            </form>

            <!-- Retirar -->
            <form id="bus-adjust-remove-form" method="POST" class="hidden px-6 py-5 space-y-4"
                  onsubmit="return confirmBusRemove()">
                @csrf
                <input type="hidden" name="start_date" value="{{ $periodoInicio }}">
                <input type="hidden" name="end_date" value="{{ $periodoFim }}">
                <div id="bus-adjust-remove-info" class="text-sm text-ink2 rounded-xl bg-gray-50 px-4 py-3">Carregando…</div>
                <div>
                    <label for="bus-adjust-remove-qty" class="{{ $labelClass }}">Quantos pagamentos excluir</label>
                    <input id="bus-adjust-remove-qty" type="number" name="quantity" required min="1" step="1"
                           inputmode="numeric" class="{{ $fieldClass }}">
                </div>
                <p class="text-xs text-red-700 rounded-xl bg-red-50 px-4 py-3 leading-relaxed">
                    <strong>Exclusão definitiva.</strong> Apaga pagamentos que existem neste carro, dentro do período, começando pelos mais antigos. Pagamentos de fora do período não são tocados.
                </p>
                <div class="flex gap-2 pt-1">
                    <button type="button" onclick="closeBusAdjustModal()"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl ring-1 ring-black/10 text-ink2 hover:bg-gray-50">Cancelar</button>
                    <button type="submit" id="bus-adjust-remove-submit"
                            class="flex-1 px-4 py-2.5 text-sm font-bold rounded-xl bg-red-600 text-white hover:bg-red-700 disabled:opacity-40 disabled:cursor-not-allowed">Excluir</button>
                </div>
            </form>

            <!-- Acréscimos já lançados no período -->
            <div id="bus-adjust-list-box" class="hidden px-6 pb-5">
                <p class="text-[11px] font-bold text-muted uppercase tracking-wide mb-2">Acréscimos manuais neste período</p>
                <ul id="bus-adjust-list" class="space-y-2"></ul>
            </div>
        </div>
    </div>
    <form id="bus-adjust-undo-form" method="POST" class="hidden">
        @csrf
        @method('DELETE')
    </form>
    @endif
</div>

<style>
    .rep-card {
        background: #fff;
        box-shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 0 0 1px rgba(16, 24, 40, 0.05);
    }
    .rep-hero {
        background:
            radial-gradient(120% 100% at 0% 0%, #0f5132 0%, transparent 60%),
            linear-gradient(135deg, #0C1A13 0%, #0f3d25 55%, #007A28 100%);
        box-shadow: 0 16px 32px -18px rgba(0, 80, 40, 0.6);
    }
    .rep-table thead th {
        background: #F8FAF9;
        padding: 0.7rem 1rem;
        text-align: left;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.06em;
        text-transform: uppercase;
        color: #6B7280;
        border-top: 1px solid rgba(0,0,0,0.05);
        border-bottom: 1px solid rgba(0,0,0,0.05);
        white-space: nowrap;
    }
    .rep-table tbody td { padding: 0.8rem 1rem; border-bottom: 1px solid rgba(0,0,0,0.045); vertical-align: middle; }
    .rep-table tbody tr { transition: background .15s; }
    .rep-table tbody tr:hover { background: #F7FBF8; }
    .rep-table tbody tr:last-child td { border-bottom: 0; }
</style>

    <!-- Scripts específicos da página -->
    <script>
        const refundRouteTemplate = @json(url('/admin/reports/payments/__ID__/refund'));
        const paymentEditRouteTemplate = @json(url('/admin/reports/payments/__ID__'));

        function closePaymentActionMenus(exceptId = null) {
            document.querySelectorAll('.payment-actions-menu').forEach(menu => {
                if (menu.id !== exceptId) {
                    menu.classList.add('hidden');
                }
            });
        }

        function togglePaymentActions(menuId) {
            const menu = document.getElementById(menuId);
            if (!menu) return;
            const willOpen = menu.classList.contains('hidden');
            closePaymentActionMenus(menuId);
            menu.classList.toggle('hidden', !willOpen);
        }

        function openPaymentEditModal(paymentId, currentSerial, currentLabel, currentAmount) {
            closePaymentActionMenus();
            const modal = document.getElementById('payment-edit-modal');
            const form = document.getElementById('payment-edit-form');
            const select = document.getElementById('payment-edit-vehicle');
            const amount = document.getElementById('payment-edit-amount');
            if (!modal || !form || !select || !amount) return;

            form.action = paymentEditRouteTemplate.replace('__ID__', paymentId);
            document.getElementById('payment-edit-id-label').textContent = paymentId;
            document.getElementById('payment-edit-current-vehicle').textContent = currentLabel || 'Sem veículo';
            select.value = currentSerial || '';
            amount.value = Number(currentAmount).toFixed(2);
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            window.setTimeout(() => amount.focus(), 50);
        }

        function closePaymentEditModal() {
            const modal = document.getElementById('payment-edit-modal');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            const form = document.getElementById('payment-edit-form');
            if (form) form.reset();
        }

        function openRefundModal(paymentId, amount) {
            closePaymentActionMenus();
            const modal = document.getElementById('refund-modal');
            const form = document.getElementById('refund-form');
            if (!modal || !form) return;
            form.action = refundRouteTemplate.replace('__ID__', paymentId);
            document.getElementById('refund-payment-id-label').textContent = paymentId;
            document.getElementById('refund-amount-label').textContent = amount;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeRefundModal() {
            const modal = document.getElementById('refund-modal');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            const form = document.getElementById('refund-form');
            if (form) form.reset();
        }

        // ---------- Ajustar receita do ônibus ----------
        const busAdjustRoutes = {
            show: @json(url('/admin/reports/buses/__SERIAL__/ajustes')),
            add: @json(url('/admin/reports/buses/__SERIAL__/acrescentar')),
            remove: @json(url('/admin/reports/buses/__SERIAL__/retirar')),
            undo: @json(url('/admin/reports/ajustes/__ID__')),
            start: @json($startDate),
            end: @json($endDate),
        };
        let busAdjustName = '';
        const brl = (v) => Number(v || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

        function showBusAdjustTab(tab) {
            const ativo = 'bg-white shadow text-ink';
            const inativo = 'text-muted hover:text-ink';
            ['add', 'remove'].forEach(t => {
                const btn = document.getElementById('bus-adjust-tab-' + t);
                btn.className = 'px-3 py-2 text-sm font-bold rounded-lg ' + (t === tab ? ativo : inativo);
            });
            document.getElementById('bus-adjust-add-form').classList.toggle('hidden', tab !== 'add');
            document.getElementById('bus-adjust-remove-form').classList.toggle('hidden', tab !== 'remove');
        }

        function updateBusAdjustTotal() {
            const qtd = parseInt(document.getElementById('bus-adjust-qty').value, 10) || 0;
            const valor = parseFloat(document.getElementById('bus-adjust-amount').value) || 0;
            document.getElementById('bus-adjust-total').textContent = brl(qtd * valor);
        }

        async function openBusAdjustModal(serial, name) {
            const modal = document.getElementById('bus-adjust-modal');
            if (!modal) return;
            busAdjustName = name || serial;
            const enc = encodeURIComponent(serial);
            document.getElementById('bus-adjust-name').textContent = busAdjustName;
            document.getElementById('bus-adjust-add-form').action = busAdjustRoutes.add.replace('__SERIAL__', enc);
            document.getElementById('bus-adjust-remove-form').action = busAdjustRoutes.remove.replace('__SERIAL__', enc);
            document.getElementById('bus-adjust-add-form').reset();
            document.getElementById('bus-adjust-remove-form').reset();
            updateBusAdjustTotal();
            showBusAdjustTab('add');

            const info = document.getElementById('bus-adjust-remove-info');
            const qtd = document.getElementById('bus-adjust-remove-qty');
            const enviar = document.getElementById('bus-adjust-remove-submit');
            info.textContent = 'Carregando…';
            qtd.removeAttribute('max');
            enviar.disabled = true;
            document.getElementById('bus-adjust-list-box').classList.add('hidden');

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            window.setTimeout(() => document.getElementById('bus-adjust-qty').focus(), 50);

            try {
                const url = busAdjustRoutes.show.replace('__SERIAL__', enc)
                    + '?start_date=' + encodeURIComponent(busAdjustRoutes.start)
                    + '&end_date=' + encodeURIComponent(busAdjustRoutes.end);
                const resp = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                const data = await resp.json();
                if (!resp.ok) throw new Error(data.message || 'Erro ao carregar');

                let texto = `<strong>${data.paid}</strong> pagamento(s) deste carro no período. `
                    + `Podem ser excluídos: <strong>${data.removable}</strong>.`;
                if (data.protected > 0) {
                    texto += `<br><span class="text-[11px] text-muted">${data.protected} protegido(s): pagos nos últimos 7 dias (o passageiro pode estar navegando ou recuperar o acesso), plano em andamento ou conta administrativa.</span>`;
                }
                info.innerHTML = texto;
                qtd.max = data.removable;
                enviar.disabled = data.removable < 1;

                renderBusAdjustList(data.adjustments || []);
            } catch (e) {
                info.textContent = 'Não foi possível carregar os dados deste carro. Feche e tente de novo.';
            }
        }

        function renderBusAdjustList(itens) {
            const box = document.getElementById('bus-adjust-list-box');
            const lista = document.getElementById('bus-adjust-list');
            lista.innerHTML = '';
            box.classList.toggle('hidden', itens.length === 0);
            itens.forEach(a => {
                const li = document.createElement('li');
                li.className = 'flex items-center justify-between gap-3 rounded-xl bg-amber-50 px-3 py-2';
                const txt = document.createElement('div');
                txt.className = 'min-w-0 text-xs text-ink2';
                txt.innerHTML = `<strong>+${a.quantity} × R$ ${brl(a.unit_amount)}</strong> = R$ ${brl(a.total)}`
                    + `<br><span class="text-[11px] text-muted"></span>`;
                txt.querySelector('span').textContent = [a.reference_at, a.author, a.note].filter(Boolean).join(' · ');
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'flex-shrink-0 px-2.5 py-1 text-[11px] font-bold rounded-lg ring-1 ring-black/10 text-red-700 hover:bg-white';
                btn.textContent = 'Desfazer';
                btn.onclick = () => undoBusAdjustment(a);
                li.append(txt, btn);
                lista.appendChild(li);
            });
        }

        function undoBusAdjustment(a) {
            if (!confirm(`Desfazer o acréscimo de ${a.quantity} pagamento(s) (R$ ${brl(a.total)}) em ${busAdjustName}?`)) return;
            const form = document.getElementById('bus-adjust-undo-form');
            form.action = busAdjustRoutes.undo.replace('__ID__', a.id);
            form.submit();
        }

        function confirmBusRemove() {
            const qtd = parseInt(document.getElementById('bus-adjust-remove-qty').value, 10) || 0;
            return confirm(`Excluir definitivamente ${qtd} pagamento(s) de ${busAdjustName} neste período?\n\nIsso não pode ser desfeito.`);
        }

        function closeBusAdjustModal() {
            const modal = document.getElementById('bus-adjust-modal');
            if (!modal) return;
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        document.getElementById('bus-adjust-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeBusAdjustModal();
        });

        document.getElementById('payment-edit-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closePaymentEditModal();
        });

        document.getElementById('refund-modal')?.addEventListener('click', function(e) {
            if (e.target === this) closeRefundModal();
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('.payment-actions-menu') && !e.target.closest('button[onclick^="togglePaymentActions"]')) {
                closePaymentActionMenus();
            }
        });
        // Toggle filtros avançados
        (function() {
            const btn = document.getElementById('toggleAdvancedFilters');
            const panel = document.getElementById('advancedFiltersPanel');
            const chevron = document.getElementById('filterChevron');
            const hasFilters = true; // sempre aberto
            if (hasFilters && panel) { panel.classList.remove('hidden'); chevron.classList.add('rotate-180'); }
            btn?.addEventListener('click', () => {
                panel.classList.toggle('hidden');
                chevron.classList.toggle('rotate-180');
            });
        })();

        // Função para mostrar/esconder abas
        function showTab(tabName) {
            // Esconder todos os conteúdos das abas
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.add('hidden');
            });
            
            // Remover classe ativa de todos os botões
            document.querySelectorAll('.tab-button').forEach(button => {
                button.classList.remove('text-green', 'border-green', 'bg-green-pale');
                button.classList.add('text-muted', 'border-transparent');
            });
            
            // Mostrar conteúdo da aba selecionada
            document.getElementById('content-' + tabName).classList.remove('hidden');
            
            // Ativar botão da aba selecionada
            const activeButton = document.getElementById('tab-' + tabName);
            activeButton.classList.remove('text-muted', 'border-transparent');
            activeButton.classList.add('text-green', 'border-green', 'bg-green-pale');
        }

        function updateBulkDeleteState() {
            const checkboxes = Array.from(document.querySelectorAll('.payment-checkbox'));
            const selected = checkboxes.filter(cb => cb.checked);
            const button = document.getElementById('bulk-delete-button');
            const form = document.getElementById('bulk-delete-form');

            if (!button || !form) {
                return;
            }

            form.querySelectorAll('input[name="payment_ids[]"]').forEach(input => input.remove());

            selected.forEach(cb => {
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'payment_ids[]';
                hidden.value = cb.value;
                form.appendChild(hidden);
            });

            button.textContent = `Excluir selecionados (${selected.length})`;
            button.disabled = selected.length === 0;

            if (button.disabled) {
                button.classList.add('opacity-50', 'cursor-not-allowed');
                button.classList.remove('hover:bg-red-700');
            } else {
                button.classList.remove('opacity-50', 'cursor-not-allowed');
                button.classList.add('hover:bg-red-700');
            }
        }

        function confirmBulkDelete() {
            const selectedCount = document.querySelectorAll('.payment-checkbox:checked').length;
            if (selectedCount === 0) {
                return false;
            }

            return confirm(`Tem certeza que deseja excluir ${selectedCount} registro(s)? Esta ação remove também os usuários vinculados e pagamentos relacionados.`);
        }

        // Função para inicializar gráficos
        function initializeCharts() {
            // Verificar se os elementos existem antes de criar os gráficos
            const revenueCanvas = document.getElementById('revenueChart');
            const statusCanvas = document.getElementById('paymentsStatusChart');

            if (!revenueCanvas || !statusCanvas) {
                console.log('Canvas elements not found, retrying...');
                setTimeout(initializeCharts, 100);
                return;
            }

            try {
                // Gráfico de Receita por Dia
                const revenueCtx = revenueCanvas.getContext('2d');
                
                // Destruir gráfico existente se houver
                if (window.revenueChart instanceof Chart) {
                    window.revenueChart.destroy();
                }

                window.revenueChart = new Chart(revenueCtx, {
                    type: 'line',
                    data: {
                        labels: {!! json_encode($charts['revenue_by_day']->pluck('date')->map(function($date) { return \Carbon\Carbon::parse($date)->format('d/m'); })) !!},
                        datasets: [{
                            label: 'Receita (R$)',
                            data: {!! json_encode($charts['revenue_by_day']->pluck('total')) !!},
                            borderColor: '#00A335',
                            backgroundColor: 'rgba(0, 163, 53, 0.10)',
                            borderWidth: 2.5,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#00A335',
                            pointBorderColor: '#ffffff',
                            pointBorderWidth: 2,
                            pointRadius: 4,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        interaction: {
                            intersect: false,
                            mode: 'index'
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        return 'Receita: R$ ' + context.parsed.y.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                    }
                                }
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return 'R$ ' + value.toLocaleString('pt-BR', {minimumFractionDigits: 2});
                                    }
                                },
                                grid: {
                                    color: 'rgba(0, 0, 0, 0.05)'
                                },
                                border: { display: false }
                            },
                            x: {
                                grid: {
                                    display: false
                                }
                            }
                        },
                        animation: {
                            duration: 1000,
                            easing: 'easeInOutQuart'
                        }
                    }
                });

                // Gráfico de Pagamentos por Status
                const statusCtx = statusCanvas.getContext('2d');
                
                // Destruir gráfico existente se houver
                if (window.statusChart instanceof Chart) {
                    window.statusChart.destroy();
                }

                window.statusChart = new Chart(statusCtx, {
                    type: 'doughnut',
                    data: {
                        labels: {!! json_encode($charts['payments_by_status']->pluck('status')->map(function($status) { 
                            return match($status) {
                                'completed' => 'Pago',
                                'pending' => 'Pendente',
                                'failed' => 'Falhou',
                                'refunded' => 'Estorno',
                                'cancelled' => 'Cancelado',
                                default => ucfirst((string) $status),
                            };
                        })) !!},
                        datasets: [{
                            data: {!! json_encode($charts['payments_by_status']->pluck('count')) !!},
                            backgroundColor: [
                                '#00A335', // green - completed
                                '#E6A817', // gold - pending  
                                '#D32F2F', // red - refunded/failed
                                '#888888', // muted - cancelled
                                '#6B7280'
                            ],
                            borderWidth: 3,
                            borderColor: '#ffffff',
                            hoverBorderWidth: 4,
                            hoverBorderColor: '#ffffff'
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        cutout: '60%',
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    padding: 20,
                                    usePointStyle: true,
                                    pointStyle: 'circle'
                                }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = ((context.parsed / total) * 100).toFixed(1);
                                        return context.label + ': ' + context.parsed + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        },
                        animation: {
                            animateRotate: true,
                            duration: 1000
                        }
                    }
                });

                console.log('Charts initialized successfully');
            } catch (error) {
                console.error('Error initializing charts:', error);
            }
        }

        // Inicializar primeira aba como ativa e gráficos
        document.addEventListener('DOMContentLoaded', function() {
            showTab('payments');
            // Aguardar um pouco para garantir que o DOM está completamente carregado
            setTimeout(initializeCharts, 500);

            const selectAll = document.getElementById('select-all-payments');
            const checkboxes = Array.from(document.querySelectorAll('.payment-checkbox'));

            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(cb => cb.checked = selectAll.checked);
                    updateBulkDeleteState();
                });
            }

            checkboxes.forEach(cb => {
                cb.addEventListener('change', function() {
                    if (selectAll) {
                        selectAll.checked = checkboxes.length > 0 && checkboxes.every(item => item.checked);
                    }
                    updateBulkDeleteState();
                });
            });

            updateBulkDeleteState();
        });

        // Reinicializar gráficos se a janela for redimensionada
        window.addEventListener('resize', function() {
            clearTimeout(window.resizeTimeout);
            window.resizeTimeout = setTimeout(function() {
                if (window.revenueChart instanceof Chart) {
                    window.revenueChart.resize();
                }
                if (window.statusChart instanceof Chart) {
                    window.statusChart.resize();
                }
            }, 100);
        });
    </script>
@endsection
