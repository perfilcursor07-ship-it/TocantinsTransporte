@extends('layouts.admin')

@section('title', 'Turismo')

@section('breadcrumb')
    <span class="text-muted">›</span>
    <span class="text-green font-semibold">Turismo</span>
@endsection

@section('page-title', 'Turismo')

@php
    $isAdmin = auth()->user()->role === 'admin';
    $statusStyles = [
        'pending' => 'bg-gold-pale text-gold border border-gold/20',
        'configured' => 'bg-green/10 text-green',
        'cancelled' => 'bg-surface text-muted border border-border',
    ];
    $tabs = ['' => 'Todas'] + \App\Models\TourismTrip::STATUSES;
@endphp

@section('content')
<div class="admin-page ui-modern">

    <!-- Hero Banner -->
    <div class="page-hero px-5 py-4 mb-5 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3">
        <div>
            <p class="text-[10px] font-bold uppercase tracking-widest text-emerald-200/80 mb-0.5">Viagens de turismo · WiFi</p>
            <h1 class="text-xl font-bold text-white">Turismo</h1>
            <p class="text-xs text-white/70 mt-0.5">Cadastre a viagem, o ônibus e como será o pagamento. O administrador configura o carro depois.</p>
        </div>
        <a href="{{ route('admin.tourism.create') }}" class="inline-flex items-center gap-1.5 px-4 py-2 bg-white text-green font-bold text-xs rounded-lg hover:bg-green-pale transition-colors shadow-card">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
            Nova Viagem
        </a>
    </div>

    @if(session('success'))
        <div class="mb-4 flex items-center gap-2 rounded-xl border border-green/20 bg-green-pale px-4 py-3 text-sm text-green font-medium">
            <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            {{ session('success') }}
        </div>
    @endif

    <!-- Filtro por status -->
    <div class="flex flex-wrap gap-2 mb-4">
        @foreach($tabs as $key => $label)
            @php
                $active = (string) $status === (string) $key;
                $total = $key === '' ? $counts->sum() : ($counts[$key] ?? 0);
            @endphp
            <a href="{{ route('admin.tourism.index', $key === '' ? [] : ['status' => $key]) }}"
               class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold border transition-colors {{ $active ? 'bg-green text-white border-green' : 'bg-white text-ink2 border-border hover:border-green/40' }}">
                {{ $label }}
                <span class="text-[10px] font-bold px-1.5 rounded {{ $active ? 'bg-white/20' : 'bg-surface text-muted' }}">{{ $total }}</span>
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-xl border border-border shadow-card overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="border-b border-border bg-surface">
                        <th class="px-5 py-2.5 text-left text-[10px] font-bold text-muted uppercase tracking-wider">Viagem</th>
                        <th class="px-5 py-2.5 text-left text-[10px] font-bold text-muted uppercase tracking-wider">Ônibus</th>
                        <th class="px-5 py-2.5 text-left text-[10px] font-bold text-muted uppercase tracking-wider">Período</th>
                        <th class="px-5 py-2.5 text-left text-[10px] font-bold text-muted uppercase tracking-wider">Pagamento</th>
                        <th class="px-5 py-2.5 text-left text-[10px] font-bold text-muted uppercase tracking-wider">Internet</th>
                        <th class="px-5 py-2.5 text-center text-[10px] font-bold text-muted uppercase tracking-wider">Status</th>
                        <th class="px-5 py-2.5 text-center text-[10px] font-bold text-muted uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($trips as $trip)
                    <tr class="hover:bg-surface/60 transition-colors {{ $trip->ends_at->isPast() ? 'opacity-60' : '' }}">
                        <td class="px-5 py-3.5">
                            @if($trip->contract_number)<p class="text-[10px] font-bold text-green uppercase tracking-wider">Contrato {{ $trip->contract_number }}</p>@endif
                            <p class="text-sm font-bold text-ink">{{ $trip->title }}</p>
                            <p class="text-[11px] text-muted">
                                {{ $trip->passengers_count ? $trip->passengers_count.' passageiros · ' : '' }}por {{ $trip->creator->name ?? '—' }}
                            </p>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="text-sm font-semibold text-ink2">{{ $trip->bus->name ?? '—' }}</p>
                            @if($trip->bus?->plate)<p class="text-[11px] text-muted">{{ $trip->bus->plate }}</p>@endif
                        </td>
                        <td class="px-5 py-3.5 whitespace-nowrap">
                            <p class="text-sm text-ink2">{{ $trip->starts_at->format('d/m/Y H:i') }}</p>
                            <p class="text-sm text-ink2">até {{ $trip->ends_at->format('d/m/Y H:i') }}</p>
                            <p class="text-[11px] text-muted">
                                {{ $trip->durationLabel() }}
                                @if($trip->starts_at->isPast() && $trip->ends_at->isFuture()) · <span class="text-green font-semibold">em andamento</span>@endif
                            </p>
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="text-sm text-ink2">{{ $trip->paymentModeLabel() }}</p>
                            @if($trip->payment_mode === 'group')
                                <p class="text-[11px] text-muted">
                                    {{ $trip->responsible_name }} · {{ $trip->responsible_phone }}
                                    @if($trip->agreed_amount) · R$ {{ number_format($trip->agreed_amount, 2, ',', '.') }}@endif
                                </p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <p class="text-sm text-ink2">{{ $trip->planLabel() }}</p>
                            @if($trip->coverage)
                                @php $estimate = $trip->estimate($pricing); @endphp
                                <p class="text-[11px] text-muted">{{ collect($estimate['lines'])->pluck('plan')->implode(' + ') }} · R$ {{ number_format($estimate['per_passenger'], 2, ',', '.') }}/passageiro</p>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-center">
                            <span class="inline-block text-[9px] font-bold uppercase tracking-wider px-2 py-0.5 rounded {{ $statusStyles[$trip->status] ?? '' }}">{{ $trip->statusLabel() }}</span>
                        </td>
                        <td class="px-5 py-3.5">
                            <div class="flex items-center justify-center gap-1">
                                @if($trip->canBeChangedBy(auth()->user()))
                                    <a href="{{ route('admin.tourism.edit', $trip) }}" class="p-1.5 rounded-lg text-blue hover:bg-blue-pale transition-colors" title="{{ $isAdmin ? 'Ver e configurar' : 'Editar' }}">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </a>
                                    <form action="{{ route('admin.tourism.destroy', $trip) }}" method="POST" class="inline" onsubmit="return confirm('Excluir a viagem {{ addslashes($trip->title) }}?');">
                                        @csrf @method('DELETE')
                                        <button type="submit" class="p-1.5 rounded-lg text-red hover:bg-red-pale transition-colors" title="Excluir">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </form>
                                @else
                                    <span class="text-[10px] text-muted" title="Já tratada pelo administrador">—</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @if($trip->plan_details || $trip->notes || $trip->admin_notes)
                    <tr class="{{ $trip->ends_at->isPast() ? 'opacity-60' : '' }}">
                        <td colspan="7" class="px-5 pb-3 pt-0 text-[11px] text-muted">
                            @if($trip->plan_details)<p class="whitespace-pre-line"><span class="font-semibold text-ink2">Como será o plano:</span> {{ $trip->plan_details }}</p>@endif
                            @if($trip->notes)<p class="mt-0.5"><span class="font-semibold text-ink2">Observações:</span> {{ $trip->notes }}</p>@endif
                            @if($trip->admin_notes)<p class="mt-0.5"><span class="font-semibold text-green">Administrador:</span> {{ $trip->admin_notes }}</p>@endif
                        </td>
                    </tr>
                    @endif
                    @empty
                    <tr>
                        <td colspan="7" class="py-12 text-center">
                            <p class="text-sm font-medium text-ink2 mb-1">{{ $status ? 'Nenhuma viagem com este status' : 'Nenhuma viagem cadastrada' }}</p>
                            @unless($status)
                                <a href="{{ route('admin.tourism.create') }}" class="inline-flex items-center gap-1.5 bg-green text-white font-semibold text-xs px-3 py-1.5 rounded-lg hover:bg-green-light transition-colors mt-2">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 4v16m8-8H4"/></svg>
                                    Cadastrar primeira viagem
                                </a>
                            @endunless
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($trips->hasPages())
            <div class="px-5 py-3 border-t border-border">{{ $trips->links() }}</div>
        @endif
    </div>
</div>
@endsection
