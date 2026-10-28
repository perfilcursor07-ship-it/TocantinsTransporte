<div class="space-y-6">
    <div class="bg-white rounded-2xl border border-border shadow-card p-6">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-xl bg-green-pale flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-green" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
            </div>
            <div class="flex-1">
                <h2 class="text-base font-bold text-ink">Gerar link de cadastro</h2>
                <p class="text-sm text-muted mt-1">Envie o link ao motorista para ele cadastrar nome, telefone, chave PIX e ônibus.</p>
                <form action="{{ route('admin.driver-pix.links.store') }}" method="POST" class="mt-4 flex flex-wrap gap-3 items-end">
                    @csrf
                    <div class="flex-1 min-w-[180px]">
                        <label class="block text-xs font-semibold text-muted mb-1">Identificação (opcional)</label>
                        <input type="text" name="label" placeholder="Ex: Ônibus 5013 — março" class="w-full px-3 py-2.5 border border-border rounded-xl text-sm bg-surface focus:bg-white focus:border-green">
                    </div>
                    <div class="min-w-[200px]">
                        <label class="block text-xs font-semibold text-muted mb-1">Competência (mês que o link coleta)</label>
                        <select name="reference_month" class="w-full px-3 py-2.5 border border-border rounded-xl text-sm font-semibold bg-surface focus:bg-white focus:border-green">
                            @foreach($monthOptions as $value => $label)
                            <option value="{{ $value }}" @selected($month === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="text-[11px] text-muted mt-1">Para pagar mês atrasado, escolha o mês passado.</p>
                    </div>
                    <div class="min-w-[200px]">
                        <label class="block text-xs font-semibold text-muted mb-1">Expira em (opcional)</label>
                        <input type="datetime-local" name="expires_at" class="w-full px-3 py-2.5 border border-border rounded-xl text-sm bg-surface focus:bg-white focus:border-green">
                    </div>
                    <button type="submit" class="px-6 py-2.5 bg-green text-white rounded-xl text-sm font-bold hover:bg-green-dark shadow-card">Gerar link</button>
                </form>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-border shadow-card overflow-hidden">
        <div class="p-5 border-b border-border flex justify-between items-center">
            <h3 class="text-base font-bold text-ink">Links ativos</h3>
            <span class="text-xs text-muted bg-surface px-2 py-1 rounded-lg">{{ $links->total() }} link(s)</span>
        </div>
        @if($links->count())
        <div class="divide-y divide-border">
            @foreach($links as $link)
            <div class="p-5 flex flex-col md:flex-row md:items-center gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="font-semibold text-ink text-sm">{{ $link->label ?: 'Link sem nome' }}</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold {{ $link->ehMesPassado() ? 'bg-amber-100 text-amber-800' : 'bg-blue-pale text-blue' }}">
                            {{ $link->competenciaLabel() }}@unless($link->reference_month) (mês corrente)@endunless
                        </span>
                        @if($link->isUsable())
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-green-pale text-green">Ativo</span>
                        @else
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-pale text-red">Inativo</span>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <input type="text" id="link-{{ $link->id }}" value="{{ $link->publicUrl() }}" readonly
                               class="flex-1 px-3 py-2 text-[11px] font-mono bg-surface border border-border rounded-xl text-ink2 truncate">
                        <button type="button" onclick="copyText('link-{{ $link->id }}')"
                                class="px-4 py-2 bg-blue text-white rounded-xl text-xs font-bold hover:bg-blue-light flex-shrink-0">Copiar</button>
                    </div>
                    <p class="text-[10px] text-muted mt-2">{{ $link->uses_count }} cadastro(s) · Criado {{ $link->created_at->format('d/m/Y H:i') }}</p>
                </div>
                <form action="{{ route('admin.driver-pix.links.toggle', $link) }}" method="POST" class="flex-shrink-0">@csrf @method('PATCH')
                    <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold border {{ $link->is_active ? 'border-red/30 text-red hover:bg-red-pale' : 'border-green/30 text-green hover:bg-green-pale' }}">
                        {{ $link->is_active ? 'Desativar' : 'Ativar' }}
                    </button>
                </form>
                <form action="{{ route('admin.driver-pix.links.destroy', $link) }}" method="POST" class="flex-shrink-0"
                      onsubmit="return confirm('Excluir este link permanentemente?\n\nOs cadastros já feitos por ele permanecem no sistema.')">
                    @csrf @method('DELETE')
                    <button type="submit" class="px-4 py-2 rounded-xl text-xs font-bold border border-red/30 text-red hover:bg-red-pale">
                        Excluir
                    </button>
                </form>
            </div>
            @endforeach
        </div>
        <div class="p-4 border-t border-border">{{ $links->appends(['tab' => 'links'])->links() }}</div>
        @else
        <p class="p-10 text-center text-sm text-muted">Nenhum link gerado. Crie o primeiro acima.</p>
        @endif
    </div>
</div>
