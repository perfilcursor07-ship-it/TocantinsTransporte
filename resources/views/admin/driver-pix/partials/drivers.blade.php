@php
    // A página responde três perguntas, nesta ordem:
    //   1. Quanto eu pago agora, e para quem?
    //   2. O que já foi pago neste mês?
    //   3. O que está travado esperando alguma ação minha?
    $aPagar   = $cards->where('state', 'ready')
                      ->filter(fn ($c) => $c['paid_total'] <= 0 && $c['valor_sugerido'] > 0)
                      ->sortBy(fn ($c) => $c['bus'])
                      ->groupBy('bus');

    $semPremio = $cards->where('state', 'ready')
                       ->filter(fn ($c) => $c['paid_total'] <= 0 && $c['valor_sugerido'] <= 0);

    $jaPagos  = $cards->filter(fn ($c) => $c['paid_total'] > 0)
                      ->sortBy(fn ($c) => $c['profile']->full_name);

    $pendencias = $newProfiles->count() + $updates->count() + $missing->count() + $rejectedCards->count();
@endphp

<div class="space-y-4">

  {{-- ====== Filtros (enxutos) ====== --}}
  <div class="bg-white rounded-2xl border border-border shadow-card p-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
      <input type="hidden" name="tab" value="drivers">

      <div class="min-w-[180px]">
        <label class="block text-[10px] font-bold uppercase text-muted tracking-wider mb-1">Competência</label>
        <select name="month" onchange="this.form.submit()"
                class="w-full px-3 py-2.5 border border-border rounded-xl text-sm font-semibold bg-white focus:border-green">
          @foreach($monthOptions as $value => $label)
          <option value="{{ $value }}" @selected($month === $value)>{{ $label }}</option>
          @endforeach
        </select>
      </div>

      <div class="flex-1 min-w-[180px]">
        <label class="block text-[10px] font-bold uppercase text-muted tracking-wider mb-1">Buscar motorista</label>
        <input type="text" name="q" value="{{ request('q') }}" placeholder="Nome, CPF ou telefone"
               class="w-full px-3 py-2.5 border border-border rounded-xl text-sm bg-white focus:border-green">
      </div>

      <div class="w-28">
        <label class="block text-[10px] font-bold uppercase text-muted tracking-wider mb-1">Ônibus</label>
        <input type="text" name="bus" value="{{ request('bus') }}" placeholder="5013"
               class="w-full px-3 py-2.5 border border-border rounded-xl text-sm bg-white focus:border-green">
      </div>

      <button type="submit" class="px-5 py-2.5 bg-green text-white rounded-xl text-sm font-bold hover:bg-green-dark">Filtrar</button>

      @if(request('q') || request('bus') || request('status'))
      <a href="{{ route('admin.driver-pix.index', ['tab' => 'drivers', 'month' => $month]) }}"
         class="px-4 py-2.5 text-sm font-semibold text-muted hover:text-ink">Limpar</a>
      @endif
    </form>
  </div>

  {{-- ====== 1. A PAGAR ====== --}}
  <div class="bg-white rounded-2xl border-2 border-green/30 shadow-card overflow-hidden">
    <div class="px-5 py-4 bg-green-pale/50 border-b border-green/20 flex items-center justify-between gap-4 flex-wrap">
      <div>
        <h3 class="text-base font-bold text-green-dark">1 · A pagar em {{ $monthLabel }}</h3>
        <p class="text-xs text-muted mt-0.5">Motoristas que confirmaram o mês e o carro ganhou prêmio.</p>
      </div>
      <div class="text-right">
        <p class="text-2xl font-bold text-green-dark">R$ {{ number_format($monthTotals['a_pagar'], 2, ',', '.') }}</p>
        <p class="text-[11px] text-muted">{{ $monthTotals['a_pagar_qtd'] }} motorista(s)</p>
      </div>
    </div>

    @if($aPagar->count())
      @foreach($aPagar as $busNumber => $busCards)
      @php $primeiro = $busCards->first(); @endphp
      <div class="border-b border-border last:border-b-0">
        <div class="px-5 py-2.5 bg-surface/80 border-b border-border flex items-center justify-between gap-3 flex-wrap">
          <div class="flex items-center gap-2.5">
            <span class="w-9 h-9 rounded-xl bg-green text-white flex items-center justify-center text-xs font-bold">
              {{ $busNumber }}
            </span>
            <div>
              <p class="text-sm font-bold text-ink">Ônibus {{ $busNumber }}</p>
              <p class="text-[11px] text-muted">
                Receita R$ {{ number_format($primeiro['receita_carro'], 2, ',', '.') }}
                · Prêmio do carro R$ {{ number_format($primeiro['premio_carro'], 2, ',', '.') }}
                ÷ {{ $primeiro['motoristas_no_carro'] }} motorista(s)
              </p>
            </div>
          </div>
          <p class="text-sm font-bold text-green-dark">
            R$ {{ number_format($busCards->sum('valor_sugerido'), 2, ',', '.') }} a pagar
          </p>
        </div>
        <div class="divide-y divide-border/60">
          @foreach($busCards as $card)
            @include('admin.driver-pix.partials.driver-row', ['card' => $card, 'compact' => true])
          @endforeach
        </div>
      </div>
      @endforeach
    @else
      <div class="px-5 py-8 text-center">
        <p class="text-sm text-muted">Nada a pagar em {{ $monthLabel }}.</p>
        @if($monthTotals['missing'] > 0)
        <p class="text-xs text-muted mt-1">
          {{ $monthTotals['missing'] }} motorista(s) ainda não confirmaram o mês — veja em Pendências abaixo.
        </p>
        @endif
      </div>
    @endif

    @if($semPremio->count())
    <div class="px-5 py-3 bg-slate-50 border-t border-border">
      <p class="text-xs text-slate-600">
        <strong>{{ $semPremio->count() }} motorista(s) confirmaram, mas o carro não bateu a faixa de prêmio</strong>
        — {{ $semPremio->pluck('bus')->unique()->sort()->join(', ') }}.
        Veja quanto falta na aba
        <a href="{{ route('admin.driver-pix.index', ['tab' => 'carros', 'month' => $month]) }}" class="text-green underline font-semibold">Carros e prêmios</a>.
      </p>
    </div>
    @endif
  </div>

  {{-- ====== 2. JÁ PAGO ====== --}}
  <details class="bg-white rounded-2xl border border-border shadow-card overflow-hidden" @if($jaPagos->count() && !$aPagar->count()) open @endif>
    <summary class="px-5 py-4 cursor-pointer flex items-center justify-between gap-4 hover:bg-surface/50">
      <div>
        <h3 class="text-base font-bold text-ink">2 · Já pago em {{ $monthLabel }}</h3>
        <p class="text-xs text-muted mt-0.5">{{ $monthTotals['pagos_qtd'] }} motorista(s) com pagamento registrado.</p>
      </div>
      <p class="text-xl font-bold text-green">R$ {{ number_format($monthTotals['paid'], 2, ',', '.') }}</p>
    </summary>

    @if($jaPagos->count())
    <div class="divide-y divide-border border-t border-border">
      @foreach($jaPagos as $card)
        @include('admin.driver-pix.partials.driver-row', ['card' => $card, 'compact' => true])
      @endforeach
    </div>
    @else
    <div class="px-5 py-8 text-center border-t border-border">
      <p class="text-sm text-muted">Nenhum pagamento registrado em {{ $monthLabel }}.</p>
    </div>
    @endif
  </details>

  {{-- ====== 3. PENDÊNCIAS ====== --}}
  <details class="bg-white rounded-2xl border border-border shadow-card overflow-hidden" @if(!$aPagar->count() && $pendencias) open @endif>
    <summary class="px-5 py-4 cursor-pointer flex items-center justify-between gap-4 hover:bg-surface/50">
      <div>
        <h3 class="text-base font-bold text-ink">3 · Pendências</h3>
        <p class="text-xs text-muted mt-0.5">O que precisa de ação sua antes de poder pagar.</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap justify-end">
        @if($newProfiles->count())
        <span class="px-2 py-1 rounded-lg bg-amber-100 text-amber-800 text-[11px] font-bold">{{ $newProfiles->count() }} novo(s)</span>
        @endif
        @if($updates->count())
        <span class="px-2 py-1 rounded-lg bg-blue-pale text-blue text-[11px] font-bold">{{ $updates->count() }} alteração(ões)</span>
        @endif
        @if($missing->count())
        <span class="px-2 py-1 rounded-lg bg-slate-100 text-slate-700 text-[11px] font-bold">{{ $missing->count() }} não confirmou</span>
        @endif
        @if($rejectedCards->count())
        <span class="px-2 py-1 rounded-lg bg-red-pale text-red text-[11px] font-bold">{{ $rejectedCards->count() }} rejeitado(s)</span>
        @endif
        @unless($pendencias)
        <span class="px-2 py-1 rounded-lg bg-green-pale text-green-dark text-[11px] font-bold">tudo em dia</span>
        @endunless
      </div>
    </summary>

    <div class="border-t border-border">
      @if($newProfiles->count())
      <div class="px-5 py-2.5 bg-amber-50 border-b border-amber-100 flex items-center justify-between gap-2">
        <h4 class="text-sm font-bold text-amber-900">Novos cadastros — aprovar antes de pagar ({{ $newProfiles->count() }})</h4>
      </div>
      <div class="divide-y divide-border">
        @foreach($newProfiles as $card)
          @include('admin.driver-pix.partials.driver-row', ['card' => $card])
        @endforeach
      </div>
      @endif

      @if($updates->count())
      <div class="px-5 py-2.5 bg-blue-pale border-y border-blue/10">
        <h4 class="text-sm font-bold text-blue">Alterações enviadas — conferir ({{ $updates->count() }})</h4>
      </div>
      <div class="divide-y divide-border">
        @foreach($updates as $card)
          @include('admin.driver-pix.partials.driver-row', ['card' => $card])
        @endforeach
      </div>
      @endif

      @if($missing->count())
      <div class="px-5 py-2.5 bg-slate-100 border-y border-border flex items-center justify-between gap-3 flex-wrap">
        <h4 class="text-sm font-bold text-slate-700">Não confirmaram {{ $monthLabel }} ({{ $missing->count() }})</h4>
        <a href="{{ route('admin.driver-pix.index', ['tab' => 'links', 'month' => $month]) }}"
           class="text-[11px] font-bold text-green hover:underline">Gerar link de {{ $monthLabel }} →</a>
      </div>
      <div class="divide-y divide-border">
        @foreach($missing as $card)
          @include('admin.driver-pix.partials.driver-row', ['card' => $card])
        @endforeach
      </div>
      @endif

      @if($rejectedCards->count())
      <div class="px-5 py-2.5 bg-red-pale/50 border-y border-red/10">
        <h4 class="text-sm font-bold text-red">Rejeitados ({{ $rejectedCards->count() }})</h4>
      </div>
      <div class="divide-y divide-border">
        @foreach($rejectedCards as $card)
          @include('admin.driver-pix.partials.driver-row', ['card' => $card])
        @endforeach
      </div>
      @endif

      @unless($pendencias)
      <div class="px-5 py-8 text-center">
        <p class="text-sm text-muted">Nenhuma pendência em {{ $monthLabel }}.</p>
      </div>
      @endunless
    </div>
  </details>

  @unless($hasDrivers)
  <div class="bg-white rounded-2xl border border-border shadow-card p-12 text-center">
    <p class="text-muted text-sm">Nenhum motorista cadastrado ainda.</p>
    <a href="{{ route('admin.driver-pix.index', ['tab' => 'links']) }}" class="inline-block mt-3 text-sm font-semibold text-green hover:underline">Gerar link de cadastro →</a>
  </div>
  @endunless
</div>

@push('modals')
{{-- Modal de pagamento --}}
<div id="paymentModal" class="fixed inset-0 z-[200] hidden" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/55 backdrop-blur-[2px]" onclick="closePaymentModal()"></div>
  <div class="fixed inset-0 flex items-center justify-center p-4 pointer-events-none">
    <div class="pointer-events-auto bg-white rounded-2xl shadow-modal w-full max-w-sm max-h-[90vh] overflow-y-auto relative">
      <div class="sticky top-0 bg-white z-10 px-5 py-4 border-b border-border rounded-t-2xl">
        <button type="button" onclick="closePaymentModal()" class="absolute top-3 right-3 text-muted hover:text-ink p-1 rounded-lg hover:bg-surface">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <h3 class="text-base font-bold text-ink pr-8" id="modalTitle">Registrar pagamento</h3>
        <p class="text-xs text-muted mt-0.5 truncate" id="modalDriverName"></p>
      </div>

      <div class="p-5 space-y-4">
        <div class="bg-surface rounded-xl p-3 space-y-1.5 text-xs">
          <div class="flex justify-between gap-2"><span class="text-muted">Competência</span><span class="font-bold" id="modalMonth">{{ $monthLabel }}</span></div>
          <div class="flex justify-between gap-2"><span class="text-muted">Ônibus</span><span class="font-bold" id="modalBus"></span></div>
          <div class="flex justify-between gap-2"><span class="text-muted">Telefone</span><span class="font-semibold" id="modalPhone"></span></div>
          <div class="flex justify-between items-start gap-2">
            <span class="text-muted flex-shrink-0">Chave PIX</span>
            <span class="font-mono text-[10px] text-right break-all" id="modalPix"></span>
          </div>
        </div>

        <div id="modalQrSection" class="hidden">
          <div class="bg-white border-2 border-green/20 rounded-xl p-3 text-center">
            <p class="text-[10px] font-bold uppercase text-green tracking-wider mb-2">Escaneie para pagar</p>
            <img id="modalQrImg" src="" alt="QR Code PIX" class="w-44 h-44 mx-auto rounded-lg border border-border bg-white object-contain">
            <p class="text-lg font-bold text-ink mt-2" id="modalQrAmount"></p>
            <button type="button" onclick="copyModalEmv()" class="mt-2 w-full py-2 text-[11px] font-bold text-blue bg-blue-pale rounded-lg hover:bg-blue/10">Copiar PIX copia e cola</button>
          </div>
        </div>
        <div id="modalQrLoading" class="hidden text-center py-4 text-xs text-muted">Gerando QR Code...</div>
        <div id="modalQrHint" class="text-[11px] text-muted text-center">Informe o valor abaixo para gerar o QR Code PIX</div>

        {{-- Criar pagamento --}}
        <form id="paymentModalForm" method="POST" class="space-y-3">
          @csrf
          @include('admin.driver-pix.partials.context-inputs')
          <input type="hidden" name="reference_month" value="{{ $month }}">
          <input type="hidden" name="month_entry_id" id="modalEntryId" value="">
          <input type="hidden" name="bus_number" id="modalBusInput" value="">
          <div>
            <label class="block text-xs font-semibold text-ink mb-1">Valor (R$) <span class="text-red">*</span></label>
            <input type="number" name="amount" id="modalAmount" step="0.01" min="0.01" required placeholder="0,00"
                   class="w-full px-3 py-2.5 border border-border rounded-xl text-sm focus:ring-2 focus:ring-green/30 focus:border-green">
          </div>
          <div>
            <label class="block text-xs font-semibold text-ink mb-1">Descrição <span class="text-muted font-normal">(opcional)</span></label>
            <input type="text" name="description" id="modalDescription" placeholder="Ex: Pagamento {{ $monthLabel }}"
                   class="w-full px-3 py-2.5 border border-border rounded-xl text-sm focus:ring-2 focus:ring-green/30 focus:border-green">
          </div>
          <div class="flex gap-2 pt-1">
            <button type="button" onclick="closePaymentModal()" class="flex-1 py-2.5 border border-border rounded-xl text-xs font-semibold text-ink2 hover:bg-surface">Cancelar</button>
            <button type="submit" id="modalSubmitBtn" class="flex-1 py-2.5 bg-green text-white rounded-xl text-xs font-bold hover:bg-green-dark">Criar pagamento</button>
          </div>
        </form>

        {{-- Pagar pendente --}}
        <div id="modalPaySection" class="hidden space-y-3">
          <div class="bg-amber-50 border border-amber-200 rounded-xl p-3 text-xs text-amber-900">
            <p class="font-bold">Pagamento pendente</p>
            <p id="modalPendingDesc" class="mt-0.5 text-amber-800"></p>
          </div>
          <form id="modalPayForm" method="POST">
            @csrf @method('PATCH')
            @include('admin.driver-pix.partials.context-inputs')
            <button type="submit" class="w-full py-3 bg-green text-white rounded-xl text-sm font-bold hover:bg-green-dark">
              ✓ Marcar como pago
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

{{-- Modal de edição de dados do motorista --}}
<div id="editDriverModal" class="fixed inset-0 z-[200] hidden" role="dialog" aria-modal="true">
  <div class="fixed inset-0 bg-black/55 backdrop-blur-[2px]" onclick="closeEditModal()"></div>
  <div class="fixed inset-0 flex items-center justify-center p-4 pointer-events-none">
    <div class="pointer-events-auto bg-white rounded-2xl shadow-modal w-full max-w-sm max-h-[90vh] overflow-y-auto relative">
      <div class="sticky top-0 bg-white z-10 px-5 py-4 border-b border-border rounded-t-2xl">
        <button type="button" onclick="closeEditModal()" class="absolute top-3 right-3 text-muted hover:text-ink p-1 rounded-lg hover:bg-surface">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
        <h3 class="text-base font-bold text-ink pr-8">Editar dados do motorista</h3>
        <p class="text-xs text-muted mt-0.5">Use quando o motorista informar os dados por telefone</p>
      </div>
      <form id="editDriverForm" method="POST" class="p-5 space-y-3">
        @csrf @method('PUT')
        @include('admin.driver-pix.partials.context-inputs')
        <div>
          <label class="block text-xs font-semibold text-ink mb-1">Nome completo <span class="text-red">*</span></label>
          <input type="text" name="full_name" id="editName" required maxlength="120"
                 class="w-full px-3 py-2.5 border border-border rounded-xl text-sm uppercase focus:border-green">
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink mb-1">CPF</label>
          <input type="text" name="cpf" id="editCpf" maxlength="14" placeholder="000.000.000-00"
                 class="w-full px-3 py-2.5 border border-border rounded-xl text-sm focus:border-green">
          <p class="text-[10px] text-muted mt-1">Com CPF o motorista consegue localizar o cadastro sozinho.</p>
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink mb-1">Telefone <span class="text-red">*</span></label>
          <input type="text" name="phone" id="editPhone" required maxlength="20"
                 class="w-full px-3 py-2.5 border border-border rounded-xl text-sm focus:border-green">
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink mb-1">Ônibus atual <span class="text-red">*</span></label>
          <input type="text" name="bus_number" id="editBus" required maxlength="20"
                 class="w-full px-3 py-2.5 border border-border rounded-xl text-sm uppercase focus:border-green">
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink mb-1">Nova chave PIX <span class="text-muted font-normal">(opcional)</span></label>
          <input type="text" name="pix_key" id="editPix" maxlength="120" placeholder="Deixe vazio para manter a atual"
                 class="w-full px-3 py-2.5 border border-border rounded-xl text-sm focus:border-green">
        </div>
        <div>
          <label class="block text-xs font-semibold text-ink mb-1">Anotações internas</label>
          <textarea name="admin_notes" id="editNotes" rows="2" maxlength="500"
                    class="w-full px-3 py-2.5 border border-border rounded-xl text-sm focus:border-green"></textarea>
        </div>
        <div class="flex gap-2 pt-1">
          <button type="button" onclick="closeEditModal()" class="flex-1 py-2.5 border border-border rounded-xl text-xs font-semibold text-ink2 hover:bg-surface">Cancelar</button>
          <button type="submit" class="flex-1 py-2.5 bg-green text-white rounded-xl text-xs font-bold hover:bg-green-dark">Salvar</button>
        </div>
      </form>
    </div>
  </div>
</div>
@endpush

@push('scripts')
<script>
(function() {
    let modalPixKey = '';
    let modalProfileId = null;
    let qrTimer = null;
    const pixQrBase = @json(url('admin/pagamentos-motoristas'));
    const monthLabel = @json($monthLabel);

    function bindButtons(selector, handler) {
        document.querySelectorAll(selector).forEach(btn => btn.addEventListener('click', handler));
    }

    // Abre já com o valor do prêmio preenchido (prêmio do carro ÷ motoristas).
    bindButtons('.btn-register-payment', (e) => {
        const d = e.currentTarget.dataset;
        openCreateModal(d, d.sugerido || undefined, d.sugerido ? ('Prêmio ' + (d.month || '')) : undefined);
    });
    bindButtons('.btn-pay-pending', (e) => openPayModal(e.currentTarget.dataset));
    bindButtons('.btn-edit-driver', (e) => openEditModal(e.currentTarget.dataset));

    window.openCreateModal = function(data, defaultAmount, defaultDescription) {
        modalProfileId = data.id;
        modalPixKey = data.pix;
        document.getElementById('modalTitle').textContent = 'Registrar pagamento';
        document.getElementById('modalDriverName').textContent = data.name;
        document.getElementById('modalPhone').textContent = data.phone || '—';
        document.getElementById('modalBus').textContent = data.bus || '—';
        document.getElementById('modalMonth').textContent = data.month || monthLabel;
        document.getElementById('modalPix').textContent = data.pix;
        document.getElementById('modalEntryId').value = data.entry || '';
        document.getElementById('modalBusInput').value = data.bus || '';
        const form = document.getElementById('paymentModalForm');
        form.action = pixQrBase + '/' + data.id + '/pagamentos';
        form.classList.remove('hidden');
        document.getElementById('modalPaySection').classList.add('hidden');
        document.getElementById('modalAmount').value = defaultAmount ?? '';
        document.getElementById('modalDescription').value = defaultDescription ?? '';
        resetQr();
        showModal('paymentModal');
        if (defaultAmount && parseFloat(defaultAmount) > 0) {
            updateQr(parseFloat(defaultAmount), data.bus);
        }
    };

    window.openPayModal = function(data) {
        modalProfileId = data.id;
        modalPixKey = data.pix;
        const amount = parseFloat(data.amount);
        document.getElementById('modalTitle').textContent = 'Pagar motorista';
        document.getElementById('modalDriverName').textContent = data.name;
        document.getElementById('modalPhone').textContent = data.phone || '—';
        document.getElementById('modalBus').textContent = data.bus || '—';
        document.getElementById('modalMonth').textContent = data.month || monthLabel;
        document.getElementById('modalPix').textContent = data.pix;
        document.getElementById('paymentModalForm').classList.add('hidden');
        document.getElementById('modalPaySection').classList.remove('hidden');
        document.getElementById('modalPendingDesc').textContent =
            (data.description || 'Pagamento motorista') + ' — R$ ' + amount.toFixed(2).replace('.', ',');
        document.getElementById('modalPayForm').action = pixQrBase + '/pagamentos/' + data.paymentId + '/pagar';
        document.getElementById('modalQrHint').classList.add('hidden');
        updateQr(amount, data.bus);
        showModal('paymentModal');
    };

    window.openEditModal = function(data) {
        document.getElementById('editDriverForm').action = pixQrBase + '/' + data.id;
        document.getElementById('editName').value = data.name || '';
        document.getElementById('editCpf').value = data.cpf || '';
        document.getElementById('editPhone').value = data.phone || '';
        document.getElementById('editBus').value = data.bus || '';
        document.getElementById('editPix').value = '';
        document.getElementById('editNotes').value = data.notes || '';
        showModal('editDriverModal');
    };

    function showModal(id) {
        document.getElementById(id).classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    window.closePaymentModal = function() {
        document.getElementById('paymentModal').classList.add('hidden');
        document.body.style.overflow = '';
        clearTimeout(qrTimer);
    };

    window.closeEditModal = function() {
        document.getElementById('editDriverModal').classList.add('hidden');
        document.body.style.overflow = '';
    };

    function resetQr() {
        document.getElementById('modalQrSection').classList.add('hidden');
        document.getElementById('modalQrLoading').classList.add('hidden');
        document.getElementById('modalQrHint').classList.remove('hidden');
        document.getElementById('modalQrImg').src = '';
    }

    async function updateQr(amount, bus) {
        if (!modalProfileId || !amount || amount <= 0) {
            resetQr();
            return;
        }
        document.getElementById('modalQrHint').classList.add('hidden');
        document.getElementById('modalQrSection').classList.add('hidden');
        document.getElementById('modalQrLoading').classList.remove('hidden');

        try {
            const url = pixQrBase + '/' + modalProfileId + '/pix-qr?amount=' + amount +
                (bus ? '&bus=' + encodeURIComponent(bus) : '');
            const res = await fetch(url, {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.error || 'Erro ao gerar QR');

            document.getElementById('modalQrLoading').classList.add('hidden');
            document.getElementById('modalQrSection').classList.remove('hidden');
            document.getElementById('modalQrImg').src = data.qr_url;
            document.getElementById('modalQrImg').dataset.emv = data.emv;
            document.getElementById('modalQrAmount').textContent = 'R$ ' + amount.toFixed(2).replace('.', ',');
        } catch (err) {
            document.getElementById('modalQrLoading').classList.add('hidden');
            document.getElementById('modalQrHint').textContent = 'Não foi possível gerar o QR Code.';
            document.getElementById('modalQrHint').classList.remove('hidden');
        }
    }

    document.getElementById('modalAmount')?.addEventListener('input', function() {
        clearTimeout(qrTimer);
        const val = parseFloat(this.value);
        const bus = document.getElementById('modalBusInput').value;
        qrTimer = setTimeout(() => updateQr(val, bus), 400);
    });

    window.copyModalEmv = function() {
        const emv = document.getElementById('modalQrImg')?.dataset.emv;
        if (emv) navigator.clipboard.writeText(emv);
    };

    window.copyPixKey = function(key) {
        navigator.clipboard.writeText(key);
    };

    @if(session('open_register_profile'))
    document.addEventListener('DOMContentLoaded', function() {
        const entryId = @json(session('open_register_entry'));
        const profileId = @json(session('open_register_profile'));
        const amount = @json(session('open_register_amount'));
        const description = @json(session('open_register_description'));
        const btn = (entryId ? document.querySelector('.btn-register-payment[data-entry="' + entryId + '"]') : null)
            || document.querySelector('.btn-register-payment[data-id="' + profileId + '"]');
        if (btn) {
            setTimeout(() => openCreateModal(btn.dataset, amount, description), 300);
        }
    });
    @endif
})();
</script>
@endpush
