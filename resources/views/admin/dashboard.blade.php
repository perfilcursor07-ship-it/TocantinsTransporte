@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div id="dashboard-section" class="section-content ui-modern space-y-4">

    @php
        $busOnline = \App\Models\Bus::where('last_sync_at', '>=', now()->subMinutes(5))->count();
        $busTotal = \App\Models\Bus::count();
        $diff = $stats['daily_revenue'] - $stats['yesterday_revenue'];
        $onlineCount = $buses->filter(fn($b) => $b->last_sync_at && $b->last_sync_at->diffInMinutes(now()) <= 5)->count();
        $offlineCount = $buses->count() - $onlineCount;
        $hour = (int) now()->format('H');
        $greeting = $hour < 12 ? 'Bom dia' : ($hour < 18 ? 'Boa tarde' : 'Boa noite');
    @endphp

    <!-- Hero -->
    <section class="dash-hero relative overflow-hidden rounded-xl px-5 py-4 sm:px-6 text-[#17221c]">
        <div class="pointer-events-none absolute -top-24 -right-16 w-72 h-72 rounded-full bg-emerald-400/20 blur-3xl hidden"></div>
        <div class="pointer-events-none absolute -bottom-28 left-1/3 w-72 h-72 rounded-full bg-lime-300/10 blur-3xl hidden"></div>

        <div class="relative flex flex-col lg:flex-row lg:items-center justify-between gap-4">
            <div>
                <p class="text-[11px] font-semibold uppercase text-[#16804a]">Starlink · Tocantins Transporte</p>
                <p class="mt-1 text-xl font-extrabold leading-tight">{{ $greeting }}, {{ strtok(Auth::user()->name, ' ') }} 👋</p>
                <p class="text-xs text-[#66736a]">Visão geral do sistema WiFi Tocantins</p>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="px-3 py-1">
                    <p class="text-[11px] text-[#7b887f] leading-none">Receita hoje</p>
                    <p class="mt-1 text-base font-extrabold leading-none text-[#1d2c23]">R$ {{ number_format($stats['daily_revenue'], 2, ',', '.') }}</p>
                </div>
                <div class="px-3 py-1">
                    <p class="text-[11px] text-[#7b887f] leading-none">Ônibus online</p>
                    <p class="mt-1 text-base font-extrabold leading-none text-[#1d2c23]">{{ $busOnline }}<span class="text-[#7b887f] text-sm font-bold">/{{ $busTotal }}</span></p>
                </div>
                <div class="px-3 py-1">
                    <p class="text-[11px] text-[#7b887f] leading-none">Atualizado em</p>
                    <p class="mt-1 text-sm font-bold leading-none text-[#1d2c23]" id="current-datetime">{{ now()->format('d/m/Y H:i') }}</p>
                </div>
                <a href="{{ route('admin.mikrotik.remote.index') }}" class="inline-flex items-center gap-2 rounded-lg bg-[#087a3b] text-white px-3.5 py-2.5 text-xs font-bold hover:bg-[#066a33] transition">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/></svg>
                    Painel MikroTik
                </a>
            </div>
        </div>
    </section>

    <!-- Status do Sistema -->
    <section class="dash-card rounded-xl p-2">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-2">
            @php
                $statusItems = [
                    ['key' => 'mikrotik', 'label' => 'MikroTik', 'icon' => 'M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01'],
                    ['key' => 'database', 'label' => 'Database', 'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
                    ['key' => 'pagamentos', 'label' => 'Pagamentos', 'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
                    ['key' => 'api_sync', 'label' => 'API Sync', 'icon' => 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15'],
                ];
            @endphp
            @foreach($statusItems as $item)
                @php
                    $s = $system_status[$item['key']] ?? ['online' => false, 'detail' => 'Desconhecido'];
                    $isWarning = $s['warning'] ?? false;
                    $tone = $s['online'] ? ($isWarning ? 'amber' : 'emerald') : 'red';
                    $toneClasses = [
                        'emerald' => ['bg' => 'bg-emerald-50', 'icon' => 'text-emerald-600', 'dot' => 'bg-emerald-500', 'text' => 'text-emerald-700'],
                        'amber'   => ['bg' => 'bg-amber-50',   'icon' => 'text-amber-600',   'dot' => 'bg-amber-500',   'text' => 'text-amber-700'],
                        'red'     => ['bg' => 'bg-red-50',     'icon' => 'text-red-600',     'dot' => 'bg-red-500',     'text' => 'text-red-700'],
                    ][$tone];
                @endphp
                <div class="flex items-center gap-2.5 rounded-xl px-2.5 py-1.5 hover:bg-gray-50 transition-colors">
                    <div class="w-8 h-8 {{ $toneClasses['bg'] }} rounded-lg flex items-center justify-center flex-shrink-0">
                        <svg class="w-4 h-4 {{ $toneClasses['icon'] }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-[11px] font-semibold text-muted leading-tight">{{ $item['label'] }}</p>
                        <div class="flex items-center gap-1.5 mt-0.5">
                            <span class="relative flex w-2 h-2 flex-shrink-0">
                                @if($s['online'] && !$isWarning)
                                <span class="absolute inline-flex h-full w-full rounded-full {{ $toneClasses['dot'] }} opacity-60 animate-ping"></span>
                                @endif
                                <span class="relative inline-flex w-2 h-2 rounded-full {{ $toneClasses['dot'] }}"></span>
                            </span>
                            <span class="text-xs font-bold {{ $toneClasses['text'] }} truncate">{{ $s['detail'] }}</span>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <!-- KPIs principais -->
    <section class="grid grid-cols-2 xl:grid-cols-4 gap-3">

        <!-- Ônibus online -->
        <div class="dash-card rounded-xl p-4">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-muted">Ônibus online</p>
                <div class="w-8 h-8 bg-emerald-50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                </div>
            </div>
            <p class="mt-2 text-2xl font-extrabold text-ink">{{ $busOnline }}<span class="text-base text-muted font-bold">/{{ $busTotal }}</span></p>
            <div class="mt-3 h-1.5 bg-gray-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full" style="width: {{ $busTotal > 0 ? round($busOnline / $busTotal * 100) : 0 }}%"></div>
            </div>
            <div class="mt-2 flex items-center justify-between text-[11px]">
                <span class="inline-flex items-center gap-1.5 font-semibold text-emerald-700">
                    <span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span>MikroTik
                </span>
                @if($busTotal - $busOnline > 0)
                    <span class="font-semibold text-muted">{{ $busTotal - $busOnline }} offline</span>
                @endif
            </div>
        </div>

        <!-- Receita hoje -->
        <div class="dash-card rounded-xl p-4">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-muted">Receita hoje</p>
                <div class="w-8 h-8 bg-amber-50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="mt-2 text-2xl font-extrabold text-ink">R$ {{ number_format($stats['daily_revenue'], 2, ',', '.') }}</p>
            <div class="mt-3 flex items-center justify-between text-[11px]">
                <span class="text-muted font-medium">{{ $stats['today_payments_count'] ?? 0 }} pagamentos</span>
                <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-bold {{ $diff >= 0 ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-red-700' }}">
                    <span title="comparado a ontem">{{ $diff >= 0 ? '▲' : '▼' }}</span> {{ $diff >= 0 ? '+' : '' }}R$ {{ number_format($diff, 2, ',', '.') }}
                </span>
            </div>
            
        </div>

        <!-- Receita semana -->
        <div class="dash-card rounded-xl p-4">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-muted">Últimos 7 dias</p>
                <div class="w-8 h-8 bg-sky-50 rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 text-sky-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
                </div>
            </div>
            <p class="mt-2 text-2xl font-extrabold text-ink">R$ {{ number_format($stats['week_revenue'], 2, ',', '.') }}</p>
            <div class="mt-3 flex items-center justify-between text-[11px]">
                <span class="text-muted font-medium">Ontem: <span class="font-bold text-ink2">R$ {{ number_format($stats['yesterday_revenue'], 2, ',', '.') }}</span></span>
                <span class="text-muted font-medium">{{ $stats['yesterday_payments_count'] ?? 0 }} pgtos</span>
            </div>
        </div>

        <!-- Pendentes -->
        @php $hasPending = $stats['pending_payments_count'] > 0; @endphp
        <div class="dash-card rounded-xl p-4 {{ $hasPending ? 'ring-1 ring-amber-200' : '' }}">
            <div class="flex items-center justify-between">
                <p class="text-xs font-semibold text-muted">Pendentes hoje</p>
                <div class="w-8 h-8 {{ $hasPending ? 'bg-amber-50' : 'bg-gray-100' }} rounded-lg flex items-center justify-center">
                    <svg class="w-4 h-4 {{ $hasPending ? 'text-amber-600' : 'text-muted' }}" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
            </div>
            <p class="mt-2 text-2xl font-extrabold {{ $hasPending ? 'text-amber-600' : 'text-ink' }}">{{ $stats['pending_payments_count'] }}</p>
            <div class="mt-3 flex items-center justify-between text-[11px]">
                <span class="text-muted font-medium">R$ {{ number_format($stats['pending_payments'], 2, ',', '.') }}</span>
                <span class="text-muted font-medium">aguardando PIX</span>
            </div>
        </div>
    </section>

    <!-- Indicadores gerais -->
    <section class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        @php
            $miniStats = [
                ['label' => 'Total de usuários', 'value' => number_format($stats['total_users'], 0, ',', '.'), 'tone' => 'text-ink', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
                ['label' => 'Dispositivos', 'value' => number_format($stats['total_devices'], 0, ',', '.'), 'tone' => 'text-ink', 'icon' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z'],
                ['label' => 'Vouchers ativos', 'value' => $stats['active_vouchers'], 'tone' => 'text-emerald-600', 'icon' => 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z'],
                ['label' => 'Receita 30 dias', 'value' => 'R$ ' . number_format($stats['month_revenue'], 2, ',', '.'), 'tone' => 'text-ink', 'icon' => 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
            ];
        @endphp
        @foreach($miniStats as $mini)
        <div class="dash-card rounded-xl px-3 py-2.5 flex items-center gap-2.5">
            <div class="w-8 h-8 rounded-lg bg-[#f1f5f2] flex items-center justify-center flex-shrink-0">
                <svg class="w-4 h-4 text-ink2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $mini['icon'] }}"/></svg>
            </div>
            <div class="min-w-0">
                <p class="text-base font-extrabold leading-tight {{ $mini['tone'] }} truncate">{{ $mini['value'] }}</p>
                <p class="text-[11px] text-muted font-medium">{{ $mini['label'] }}</p>
            </div>
        </div>
        @endforeach
    </section>

    <!-- Gráficos -->
    <section class="grid grid-cols-1 xl:grid-cols-5 gap-3">
        <div class="dash-card rounded-xl p-4 xl:col-span-3">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-sm font-bold text-ink">Receita dos últimos 7 dias</h3>
                    <p class="text-[11px] text-muted">Pagamentos confirmados por dia</p>
                </div>
                <span class="rounded-full bg-emerald-50 text-emerald-700 px-3 py-1 text-xs font-bold">R$ {{ number_format(array_sum($revenue_chart['data']), 2, ',', '.') }}</span>
            </div>
            <div style="height: 190px;">
                <canvas id="revenueChart"></canvas>
            </div>
        </div>
        <div class="dash-card rounded-xl p-4 xl:col-span-2">
            <div class="flex items-start justify-between mb-3">
                <div>
                    <h3 class="text-sm font-bold text-ink">Conexões por hora</h3>
                    <p class="text-[11px] text-muted">Últimas 12 horas</p>
                </div>
            </div>
            <div style="height: 190px;">
                <canvas id="connectionsChart"></canvas>
            </div>
        </div>
    </section>

    <!-- Ônibus cadastrados -->
    @php
        $todayFleetTotal = $buses->sum(fn ($b) => $bus_revenue_today[$b->mikrotik_serial]->total ?? 0);
    @endphp
    <section class="dash-card rounded-xl overflow-hidden">
        <div class="flex flex-wrap justify-between items-center gap-3 px-4 py-3 border-b border-black/[0.06]">
            <div>
                <h3 class="text-sm font-bold text-ink">Ônibus cadastrados</h3>
                <div class="mt-1 flex flex-wrap items-center gap-1.5 text-[11px]">
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 text-emerald-700 px-2 py-0.5 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>{{ $onlineCount }} online</span>
                    <span class="inline-flex items-center gap-1 rounded-full bg-gray-100 text-muted px-2 py-0.5 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>{{ $offlineCount }} offline</span>
                    <span class="text-muted font-medium">{{ $buses->count() }} no total · Receita hoje <span class="font-bold text-ink2">R$ {{ number_format($todayFleetTotal, 2, ',', '.') }}</span></span>
                </div>
            </div>
            <a href="{{ route('admin.mikrotik.remote.index') }}" class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-[#eaf5ed] text-[#17633f] font-bold rounded-lg text-xs hover:bg-[#dff0e4] transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2"/></svg>
                Painel MikroTik
            </a>
        </div>
        <div class="p-3">
            @if($buses->count() > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4 gap-2.5">
                @foreach($buses as $bus)
                @php
                    $isOnline = $bus->last_sync_at && $bus->last_sync_at->diffInMinutes(now()) <= 5;
                    $syncAgo = $bus->last_sync_at ? $bus->last_sync_at->diffForHumans(short: true) : 'nunca';
                    $revToday = $bus_revenue_today[$bus->mikrotik_serial] ?? null;
                    $revWeek = $bus_revenue_week[$bus->mikrotik_serial] ?? null;
                    $todayTotal = $revToday->total ?? 0;
                @endphp
                <div class="rounded-lg p-3 ring-1 transition-colors hover:shadow-sm {{ $isOnline ? 'ring-emerald-200 bg-[#f7fbf8]' : 'ring-black/[0.06] bg-white' }}">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex items-center gap-2 min-w-0">
                            <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $isOnline ? 'bg-emerald-500 animate-pulse' : 'bg-gray-300' }}"></span>
                            <p class="text-[13px] font-bold text-ink truncate">{{ $bus->name ?: 'Sem nome' }}</p>
                        </div>
                        <span class="rounded-full px-1.5 py-0.5 text-[9px] font-bold uppercase flex-shrink-0 {{ $isOnline ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-muted' }}">
                            {{ $isOnline ? 'Online' : 'Offline' }}
                        </span>
                    </div>

                    <div class="mt-2 flex items-end justify-between gap-2">
                        <div>
                            <p class="text-[10px] text-muted leading-none">Receita hoje</p>
                            <p class="mt-0.5 text-base font-extrabold leading-tight {{ $todayTotal > 0 ? 'text-emerald-700' : 'text-gray-400' }}">R$ {{ number_format($todayTotal, 2, ',', '.') }}</p>
                        </div>
                        <div class="text-right">
                            <p class="text-[10px] text-muted leading-none">7 dias</p>
                            <p class="mt-0.5 text-xs font-bold text-ink2 leading-tight">R$ {{ number_format($revWeek->total ?? 0, 2, ',', '.') }}</p>
                        </div>
                    </div>
                    <p class="text-[10px] text-muted mt-0.5">{{ $revToday->count ?? 0 }} {{ ($revToday->count ?? 0) === 1 ? 'pagamento' : 'pagamentos' }} hoje</p>

                    <div class="mt-2 pt-2 border-t border-black/[0.05] flex items-center justify-between gap-2 text-[10px] text-muted">
                        <span class="truncate">
                            @if($bus->plate)<span class="font-mono font-bold text-ink2">{{ $bus->plate }}</span> · @endif
                            Sync: <span class="font-semibold text-ink2">{{ $syncAgo }}</span>
                        </span>
                        <span class="font-mono truncate" title="{{ $bus->mikrotik_serial }}">{{ $bus->last_public_ip ?: '-' }}</span>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="text-center py-10">
                <div class="w-12 h-12 bg-gray-100 rounded-2xl flex items-center justify-center mx-auto mb-3">
                    <svg class="w-6 h-6 text-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"/></svg>
                </div>
                <p class="text-muted text-sm font-medium">Nenhum ônibus cadastrado</p>
            </div>
            @endif
        </div>
    </section>
</div>

<style>
    .dash-hero {
        background: #fff;
        border: 1px solid #e1e7e2;
        box-shadow: 0 1px 3px rgba(23, 34, 28, 0.05);
    }
    .dash-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 1px 2px rgba(23, 34, 28, 0.04), 0 0 0 1px #e6ebe7;
        transition: box-shadow .16s ease;
    }
    .dash-card:hover { box-shadow: 0 2px 6px rgba(23, 34, 28, 0.08), 0 0 0 1px #dce4de; }
</style>

<script>
    // Atualizar data/hora
    function updateDateTime() {
        const now = new Date();
        const formatted = now.toLocaleDateString('pt-BR') + ' ' + now.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
        const el = document.getElementById('current-datetime');
        if (el) el.textContent = formatted;
    }
    setInterval(updateDateTime, 60000);

    // Gráfico de Receita
    const revenueCtx = document.getElementById('revenueChart').getContext('2d');
    const revenueGradient = revenueCtx.createLinearGradient(0, 0, 0, 180);
    revenueGradient.addColorStop(0, 'rgba(8, 122, 59, 0.14)');
    revenueGradient.addColorStop(1, 'rgba(8, 122, 59, 0.01)');
    
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: {!! json_encode($revenue_chart['labels']) !!},
            datasets: [{
                label: 'Receita (R$)',
                data: {!! json_encode($revenue_chart['data']) !!},
                borderColor: '#087a3b',
                backgroundColor: revenueGradient,
                borderWidth: 2.5,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#087a3b',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#111111',
                    padding: 10,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(ctx) { return 'R$ ' + ctx.parsed.y.toFixed(2).replace('.', ','); }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0,0,0,0.04)' },
                    ticks: { callback: v => 'R$ ' + v, font: { size: 11 }, color: '#888888' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 11 }, color: '#888888' }
                }
            }
        }
    });

    // Gráfico de Conexões
    const connectionsCtx = document.getElementById('connectionsChart').getContext('2d');
    new Chart(connectionsCtx, {
        type: 'bar',
        data: {
            labels: {!! json_encode($connections_chart['labels']) !!},
            datasets: [{
                label: 'Conexões',
                data: {!! json_encode($connections_chart['data']) !!},
                backgroundColor: 'rgba(8, 122, 59, 0.72)',
                hoverBackgroundColor: '#087a3b',
                borderRadius: 6,
                borderSkipped: false,
                barThickness: 20,
                maxBarThickness: 30
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    cornerRadius: 8,
                    callbacks: {
                        label: function(ctx) { return ctx.parsed.y + ' conexões'; }
                    }
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(0, 0, 0, 0.05)' },
                    ticks: { stepSize: 1, font: { size: 11 }, color: '#6b7280' }
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 10 }, color: '#6b7280' }
                }
            }
        }
    });

    // Desconectar usuário
    function disconnectUser(macAddress) {
        if (!confirm('Deseja realmente desconectar este usuário?')) return;
        
        fetch('/admin/mikrotik/remote/block', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || ''
            },
            body: JSON.stringify({ mac: macAddress })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                showToast(data.message || 'Usuário desconectado!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                showToast('Erro: ' + (data.error || data.message), 'error');
            }
        })
        .catch(err => showToast('Erro de conexão', 'error'));
    }

    function showToast(message, type = 'info') {
        const colors = { success: 'bg-emerald-600', error: 'bg-red-600', info: 'bg-blue-600' };
        const icons = { success: '✅', error: '❌', info: 'ℹ️' };
        const toast = document.createElement('div');
        toast.className = `fixed top-4 right-4 z-[100] ${colors[type]} text-white px-5 py-3 rounded-xl shadow-lg text-sm font-medium transform transition-all duration-300`;
        toast.innerHTML = `<span>${icons[type]}</span> ${message}`;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(100%)';
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    // Auto-refresh a cada 60s
    setTimeout(() => location.reload(), 60000);
</script>
@endsection
