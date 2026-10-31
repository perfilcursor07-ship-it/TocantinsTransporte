@extends('layouts.admin')

@section('title', 'Pagamentos Motoristas')

@section('breadcrumb')
    <span class="mx-2">/</span>
    <span class="text-green font-medium">Pagamentos Motoristas</span>
@endsection

@section('page-title', 'Pagamentos Motoristas')

@section('content')
<div class="space-y-6">
    @if(session('success'))
    <div class="bg-green-pale border border-green/30 text-green-dark px-4 py-3 rounded-2xl text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-pale border border-red/30 text-red px-4 py-3 rounded-2xl text-sm">{{ session('error') }}</div>
    @endif
    @if($errors->any())
    <div class="bg-red-pale border border-red/30 text-red px-4 py-3 rounded-2xl text-sm space-y-1">
        @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
    </div>
    @endif
    @if(session('new_link_url'))
    <div class="bg-blue-pale border border-blue/30 rounded-2xl p-4">
        <p class="text-sm font-semibold text-blue mb-2">Novo link gerado — copie e envie ao motorista:</p>
        <div class="flex gap-2">
            <input type="text" id="newLinkUrl" value="{{ session('new_link_url') }}" readonly class="flex-1 px-3 py-2 bg-white border border-border rounded-xl text-xs font-mono">
            <button type="button" onclick="copyLink()" class="px-4 py-2 bg-blue text-white rounded-xl text-xs font-semibold hover:bg-blue-light">Copiar</button>
        </div>
    </div>
    @endif

    {{-- Indicadores: as mesmas três perguntas que a aba Motoristas responde --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border-2 border-green/30 p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-green-dark tracking-wider">A pagar · {{ $monthLabel }}</p>
            <p class="text-[10px] text-muted">ganhos de {{ $ganhosLabel }}</p>
            <p class="text-2xl font-bold text-green-dark mt-1">R$ {{ number_format($monthTotals['a_pagar'] ?? 0, 2, ',', '.') }}</p>
            <p class="text-[10px] text-muted mt-0.5">{{ $monthTotals['a_pagar_qtd'] ?? 0 }} motorista(s) confirmado(s)</p>
        </div>
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Já pago · {{ $monthLabel }}</p>
            <p class="text-2xl font-bold text-ink mt-1">R$ {{ number_format($stats['month_paid'], 2, ',', '.') }}</p>
            <p class="text-[10px] text-muted mt-0.5">R$ {{ number_format($stats['total_paid'], 2, ',', '.') }} acumulado</p>
        </div>
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Pendências</p>
            <p class="text-2xl font-bold {{ ($monthTotals['pendencias'] ?? 0) > 0 ? 'text-amber-600' : 'text-ink' }} mt-1">{{ $monthTotals['pendencias'] ?? 0 }}</p>
            <p class="text-[10px] text-muted mt-0.5">{{ $stats['month_missing'] }} sem confirmar · {{ $stats['pending_profiles'] + $stats['month_updates'] }} p/ analisar</p>
        </div>
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Confirmaram o mês</p>
            <p class="text-2xl font-bold text-ink mt-1">{{ $stats['month_confirmed'] }}<span class="text-base text-muted font-semibold">/{{ $stats['approved_profiles'] }}</span></p>
            <p class="text-[10px] text-muted mt-0.5">cadastros aprovados</p>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="flex gap-1 p-1 bg-surface rounded-2xl border border-border w-fit">
        @foreach(['drivers' => 'Motoristas', 'carros' => 'Carros e prêmios', 'links' => 'Links', 'payments' => 'Pagamentos'] as $key => $label)
        <a href="{{ route('admin.driver-pix.index', ['tab' => $key, 'month' => $month]) }}"
           class="px-5 py-2.5 text-sm font-semibold rounded-xl transition-all {{ $tab === $key ? 'bg-white text-green shadow-card border border-border' : 'text-muted hover:text-ink' }}">
            {{ $label }}
            @if($key === 'drivers' && ($stats['pending_profiles'] + $stats['month_updates']) > 0)
            <span class="ml-1 px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-800 text-[10px]">{{ $stats['pending_profiles'] + $stats['month_updates'] }}</span>
            @endif
            @if($key === 'payments' && $stats['pending_payments'] > 0)
            <span class="ml-1 px-1.5 py-0.5 rounded-full bg-blue-pale text-blue text-[10px]">{{ $stats['pending_payments'] }}</span>
            @endif
        </a>
        @endforeach
    </div>

    @if($tab === 'carros')
    @include('admin.driver-pix.partials.carros', ['carros' => $carros])
    @elseif($tab === 'links')
    @include('admin.driver-pix.partials.links', ['links' => $links])
    @elseif($tab === 'payments')
    @include('admin.driver-pix.partials.payments', ['payments' => $payments])
    @else
    @include('admin.driver-pix.partials.drivers')
    @endif
</div>

<script>
function copyLink() {
    const el = document.getElementById('newLinkUrl');
    if (!el) return;
    navigator.clipboard.writeText(el.value);
}
function copyText(id) {
    const el = document.getElementById(id);
    if (!el) return;
    navigator.clipboard.writeText(el.value);
    alert('Link copiado!');
}
</script>
@endsection
