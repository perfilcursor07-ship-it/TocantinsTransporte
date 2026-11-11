@php $showingAll = request('payments_month') === 'all'; @endphp

<div class="bg-white rounded-2xl border border-border shadow-card overflow-hidden">
  <div class="p-5 border-b border-border bg-surface/50 flex flex-wrap gap-3 justify-between items-end">
    <div>
      <h3 class="text-base font-bold text-ink">Pagamentos {{ $showingAll ? '— todos os meses' : '— ganhos de ' . $ganhosLabel }}</h3>
      <p class="text-xs text-muted mt-0.5">Envie o PIX e marque como pago quando concluir</p>
    </div>

    <form method="GET" class="flex flex-wrap gap-2 items-end">
      <input type="hidden" name="tab" value="payments">
      <div class="min-w-[180px]">
        <label class="block text-[10px] font-bold uppercase text-muted tracking-wider mb-1">Mês dos ganhos</label>
        <select name="ganhos" class="w-full px-3 py-2 border border-border rounded-xl text-sm bg-white">
          @foreach($monthOptions as $value => $label)
          <option value="{{ $value }}" @selected($ganhosMes === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>
      <label class="flex items-center gap-2 text-xs font-semibold text-ink2 px-2 py-2.5">
        <input type="checkbox" name="payments_month" value="all" @checked($showingAll)
               class="w-4 h-4 rounded border-border text-green focus:ring-green">
        Todos os meses
      </label>
      <button type="submit" class="px-4 py-2 bg-green text-white rounded-xl text-sm font-semibold hover:bg-green-dark">Aplicar</button>
    </form>
  </div>

  @if($payments->count())
  <div class="divide-y divide-border">
    @foreach($payments as $payment)
    <div class="p-5 flex flex-col sm:flex-row sm:items-center gap-4 hover:bg-surface/20 transition-colors">
      <div class="flex-1 min-w-0">
        <div class="flex flex-wrap items-center gap-2 mb-1">
          <span class="font-bold text-ink">{{ $payment->profile?->full_name ?? '—' }}</span>
          <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">
            Ônibus {{ $payment->bus_number ?: ($payment->profile?->bus_number ?? '—') }}
          </span>
          @if($payment->reference_month)
          <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-blue-pale text-blue">Ganhos de {{ $payment->ganhosLabel() }}</span>
          @endif
          @php
            $pbadge = match($payment->status) {
              'pending' => 'bg-amber-100 text-amber-800',
              'paid' => 'bg-green-pale text-green-dark',
              'cancelled' => 'bg-red-pale text-red',
              default => 'bg-slate-100 text-slate-600',
            };
            $plabel = match($payment->status) {
              'pending' => 'Pendente',
              'paid' => 'Pago',
              'cancelled' => 'Cancelado',
              default => ucfirst((string) $payment->status),
            };
          @endphp
          <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $pbadge }}">{{ $plabel }}</span>
        </div>
        @if($payment->profile?->phone)
        <p class="text-xs text-muted flex items-center gap-1 mt-0.5">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
          {{ $payment->profile->formattedPhone() }}
        </p>
        @endif
        <p class="text-xs text-muted">{{ $payment->description ?: 'Sem descrição' }}</p>
        <p class="text-[10px] text-muted mt-1">Registrado {{ $payment->created_at->format('d/m/Y H:i') }}
          @if($payment->paid_at) · Pago em {{ $payment->paid_at->format('d/m/Y H:i') }}@endif
        </p>
      </div>

      <div class="text-right flex-shrink-0">
        <p class="text-xl font-bold text-green">R$ {{ number_format($payment->amount, 2, ',', '.') }}</p>
      </div>

      <div class="flex flex-wrap gap-2 sm:flex-col sm:w-36 flex-shrink-0">
      @if($payment->status === 'pending')
        @if($payment->profile)
        <button type="button" onclick="navigator.clipboard.writeText(@json($payment->profile->pix_key))"
                class="px-4 py-2 bg-blue-pale text-blue rounded-xl text-xs font-bold hover:bg-blue/10">
          Copiar PIX
        </button>
        @endif
        <form action="{{ route('admin.driver-pix.payments.paid', $payment) }}" method="POST">
          @csrf @method('PATCH')
          @include('admin.driver-pix.partials.context-inputs', ['contextTab' => 'payments'])
          <button type="submit" class="w-full px-4 py-2 bg-green text-white rounded-xl text-xs font-bold hover:bg-green-dark">
            Marcar como pago
          </button>
        </form>
        <form action="{{ route('admin.driver-pix.payments.cancel', $payment) }}" method="POST">
          @csrf @method('PATCH')
          @include('admin.driver-pix.partials.context-inputs', ['contextTab' => 'payments'])
          <button type="submit" class="w-full px-4 py-2 text-red text-xs font-semibold hover:underline">Cancelar</button>
        </form>
      @endif
        <form action="{{ route('admin.driver-pix.payments.destroy', $payment) }}" method="POST"
              onsubmit="return confirm('Excluir este pagamento de R$ {{ number_format($payment->amount, 2, ',', '.') }} permanentemente?')">
          @csrf @method('DELETE')
          @include('admin.driver-pix.partials.context-inputs', ['contextTab' => 'payments'])
          <button type="submit" class="w-full px-4 py-2 text-red text-xs font-semibold hover:underline">Excluir</button>
        </form>
      </div>
    </div>
    @endforeach
  </div>
  <div class="p-4 border-t border-border">
    {{ $payments->appends(array_filter(['tab' => 'payments', 'ganhos' => $ganhosMes, 'payments_month' => request('payments_month')]))->links() }}
  </div>
  @else
  <div class="p-12 text-center text-sm text-muted">
    Nenhum pagamento {{ $showingAll ? 'registrado ainda' : 'dos ganhos de ' . $ganhosLabel }}.
  </div>
  @endif
</div>
