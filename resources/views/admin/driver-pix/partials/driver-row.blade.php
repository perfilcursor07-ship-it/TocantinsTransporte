@php
  /** @var array $card */
  $profile = $card['profile'];
  $entry = $card['entry'];
  $state = $card['state'];
  $bus = $card['bus'];
  $pending = $card['pending_payment'];
  $paid = $card['paid_payment'];
  $compact = $compact ?? false;

  $fieldLabels = [
    'full_name' => 'nome',
    'phone' => 'telefone',
    'bus_number' => 'ônibus',
    'pix_key' => 'chave PIX',
  ];

  $changed = collect($entry?->changed_fields ?? [])
    ->keys()
    ->map(fn ($key) => $fieldLabels[$key] ?? $key)
    ->all();

  $stateBadge = match($state) {
    'new' => ['bg-amber-100 text-amber-800', 'Novo cadastro'],
    'update' => ['bg-blue-pale text-blue', 'Alteração para aprovar'],
    'ready' => ['bg-green-pale text-green-dark', 'Confirmado no mês'],
    'missing' => ['bg-slate-100 text-slate-600', 'Não confirmou o mês'],
    'rejected' => ['bg-red-pale text-red', 'Rejeitado'],
    default => ['bg-slate-100 text-slate-600', ucfirst($state)],
  };
@endphp

<div class="p-4 hover:bg-surface/30 transition-colors {{ $compact ? 'pl-6' : '' }}">
  <div class="flex flex-col lg:flex-row lg:items-center gap-4">
    {{-- Identificação --}}
    <div class="flex-1 min-w-0">
      <div class="flex flex-wrap items-center gap-2 mb-1">
        <h4 class="font-bold text-ink {{ $compact ? 'text-sm' : 'text-base' }}">{{ $profile->full_name }}</h4>
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold {{ $stateBadge[0] }}">{{ $stateBadge[1] }}</span>

        @if(! $compact && $bus)
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-700">Ônibus {{ $bus }}</span>
        @endif

        @if($pending)
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800">
          ⏳ Pendente R$ {{ number_format($pending->amount, 2, ',', '.') }}
        </span>
        @elseif($paid)
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-green-pale text-green-dark">
          ✓ Pago {{ $paid->paid_at?->format('d/m/Y') }}
        </span>
        @elseif($state === 'ready')
        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-500">Sem pagamento no mês</span>
        @endif
      </div>

      <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted mt-1">
        @if($profile->phone)
        <span class="flex items-center gap-1">
          <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
          {{ $profile->formattedPhone() }}
        </span>
        @endif
        @if($profile->cpf)
        <span>CPF {{ $profile->formattedCpf() }}</span>
        @else
        <span class="text-amber-600">Sem CPF cadastrado</span>
        @endif
        @if($entry?->submitted_at)
        <span>Enviado {{ $entry->submitted_at->format('d/m/Y H:i') }}</span>
        @else
        <span>Cadastro {{ $profile->created_at->format('d/m/Y') }}</span>
        @endif
      </div>

      @if($changed)
      <p class="text-[11px] text-blue mt-1.5">Motorista alterou: <strong>{{ implode(', ', $changed) }}</strong></p>
      @endif

      @if($state === 'rejected' && ($entry?->rejected_reason || $profile->rejected_reason))
      <p class="text-xs text-red mt-1.5">Motivo: {{ $entry?->rejected_reason ?: $profile->rejected_reason }}</p>
      @endif
    </div>

    {{-- Chave PIX --}}
    <div class="lg:w-48 flex-shrink-0 bg-surface rounded-xl p-3 border border-border">
      <p class="text-[10px] font-bold uppercase text-muted tracking-wider mb-1">Chave PIX</p>
      @if($card['pix_changed'])
        <p class="font-mono text-[11px] text-muted line-through break-all">{{ $profile->maskedPixKey() }}</p>
        <p class="font-mono text-xs text-blue break-all font-bold">{{ $entry->pix_key }}</p>
        <p class="text-[10px] text-blue mt-1">Nova chave — aprove para passar a valer</p>
      @else
        <p class="font-mono text-xs text-ink break-all">{{ $profile->maskedPixKey() }}</p>
        @if($profile->isApproved())
        <button type="button" onclick="copyPixKey(@json($profile->pix_key))" class="mt-2 text-[11px] font-bold text-blue hover:underline">Copiar chave</button>
        @endif
      @endif
    </div>

    {{-- Valores --}}
    <div class="lg:w-28 flex-shrink-0 text-center lg:text-right">
      <p class="text-[10px] font-bold uppercase text-muted">No mês</p>
      <p class="text-base font-bold text-green">R$ {{ number_format($card['paid_total'], 2, ',', '.') }}</p>
      <p class="text-[10px] text-muted mt-0.5">Total R$ {{ number_format($profile->total_paid ?? 0, 2, ',', '.') }}</p>
    </div>

    {{-- Ações --}}
    <div class="flex flex-wrap gap-2 lg:flex-col lg:w-44 flex-shrink-0">
      @if($state === 'new')
        <form action="{{ route('admin.driver-pix.approve', $profile) }}" method="POST" class="flex-1 lg:flex-none">
          @csrf @method('PATCH')
          @include('admin.driver-pix.partials.context-inputs')
          <button type="submit" class="w-full px-4 py-2 bg-green text-white rounded-xl text-sm font-semibold hover:bg-green-dark">Aprovar cadastro</button>
        </form>
        <form action="{{ route('admin.driver-pix.reject', $profile) }}" method="POST" class="flex-1 lg:flex-none">
          @csrf @method('PATCH')
          @include('admin.driver-pix.partials.context-inputs')
          <input type="hidden" name="rejected_reason" value="Dados incorretos ou incompletos">
          <button type="submit" class="w-full px-4 py-2 bg-white border border-red/30 text-red rounded-xl text-sm font-semibold hover:bg-red-pale">Rejeitar</button>
        </form>

      @elseif($state === 'update' && $entry)
        <form action="{{ route('admin.driver-pix.months.approve', $entry) }}" method="POST" class="flex-1 lg:flex-none">
          @csrf @method('PATCH')
          @include('admin.driver-pix.partials.context-inputs')
          <button type="submit" class="w-full px-4 py-2 bg-green text-white rounded-xl text-sm font-semibold hover:bg-green-dark">Aprovar alteração</button>
        </form>
        <form action="{{ route('admin.driver-pix.months.reject', $entry) }}" method="POST" class="flex-1 lg:flex-none">
          @csrf @method('PATCH')
          @include('admin.driver-pix.partials.context-inputs')
          <input type="hidden" name="rejected_reason" value="Dados do mês não conferem">
          <button type="submit" class="w-full px-4 py-2 bg-white border border-red/30 text-red rounded-xl text-sm font-semibold hover:bg-red-pale">Rejeitar</button>
        </form>

      @elseif($state === 'ready')
        @if($pending)
        <button type="button"
                class="btn-pay-pending w-full px-4 py-2.5 bg-amber-500 text-white rounded-xl text-sm font-semibold hover:bg-amber-600"
                data-id="{{ $profile->id }}"
                data-entry="{{ $entry?->id }}"
                data-name="{{ $profile->full_name }}"
                data-phone="{{ $profile->formattedPhone() }}"
                data-bus="{{ $bus }}"
                data-month="{{ $monthLabel ?? '' }}"
                data-pix="{{ $profile->pix_key }}"
                data-payment-id="{{ $pending->id }}"
                data-amount="{{ $pending->amount }}"
                data-description="{{ $pending->description }}">
          Pagar R$ {{ number_format($pending->amount, 2, ',', '.') }}
        </button>
        @else
        <button type="button"
                class="btn-register-payment w-full px-4 py-2.5 bg-blue text-white rounded-xl text-sm font-semibold hover:bg-blue-light"
                data-id="{{ $profile->id }}"
                data-entry="{{ $entry?->id }}"
                data-name="{{ $profile->full_name }}"
                data-phone="{{ $profile->formattedPhone() }}"
                data-bus="{{ $bus }}"
                data-month="{{ $monthLabel ?? '' }}"
                data-pix="{{ $profile->pix_key }}"
                data-sugerido="{{ ($card['valor_sugerido'] ?? 0) > 0 ? number_format($card['valor_sugerido'], 2, '.', '') : '' }}">
          @if(($card['valor_sugerido'] ?? 0) > 0)
          Pagar R$ {{ number_format($card['valor_sugerido'], 2, ',', '.') }}
          @else
          Registrar pagamento
          @endif
        </button>
        @endif

      @elseif($state === 'missing')
        <form action="{{ route('admin.driver-pix.months.store', $profile) }}" method="POST" class="flex-1 lg:flex-none space-y-1.5">
          @csrf
          @include('admin.driver-pix.partials.context-inputs')
          <input type="hidden" name="reference_month" value="{{ $month }}">
          <input type="text" name="bus_number" value="{{ $profile->bus_number }}" required maxlength="20"
                 class="w-full px-3 py-1.5 border border-border rounded-lg text-xs text-center font-semibold uppercase"
                 aria-label="Ônibus do mês">
          <button type="submit" class="w-full px-3 py-2 bg-white border border-green/40 text-green rounded-xl text-xs font-bold hover:bg-green-pale">
            Confirmar este mês
          </button>
        </form>
      @endif

      <button type="button" class="btn-edit-driver w-full px-4 py-2 text-ink2 text-xs font-semibold border border-border rounded-xl hover:bg-surface"
              data-id="{{ $profile->id }}"
              data-name="{{ $profile->full_name }}"
              data-cpf="{{ $profile->cpf ? $profile->formattedCpf() : '' }}"
              data-phone="{{ $profile->formattedPhone() !== '—' ? $profile->formattedPhone() : '' }}"
              data-bus="{{ $profile->bus_number }}"
              data-notes="{{ $profile->admin_notes }}">
        Editar dados
      </button>

      @if(in_array($state, ['new', 'rejected', 'missing'], true))
      <form action="{{ route('admin.driver-pix.destroy', $profile) }}" method="POST"
            onsubmit="return confirm('Excluir permanentemente o cadastro de {{ addslashes($profile->full_name) }}?\n\nTodos os pagamentos e meses vinculados também serão removidos.')">
        @csrf @method('DELETE')
        @include('admin.driver-pix.partials.context-inputs')
        <button type="submit" class="w-full px-4 py-2 text-red text-[11px] font-semibold hover:underline">Excluir cadastro</button>
      </form>
      @endif
    </div>
  </div>
</div>
