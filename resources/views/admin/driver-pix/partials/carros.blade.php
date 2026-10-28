@php
    use App\Services\DriverPrizeService;
    $totais = DriverPrizeService::totais($carros);
@endphp

<div class="space-y-4">

    {{-- Seletor de competência --}}
    <div class="bg-white rounded-2xl border border-border shadow-card p-4">
        <form method="GET" class="flex flex-wrap gap-3 items-end">
            <input type="hidden" name="tab" value="carros">
            <div class="min-w-[220px]">
                <label class="block text-[10px] font-bold uppercase text-muted tracking-wider mb-1">Competência (mês)</label>
                <select name="month" onchange="this.form.submit()"
                        class="w-full px-3 py-2.5 border border-border rounded-xl text-sm font-semibold bg-white focus:border-green">
                    @foreach($monthOptions as $value => $label)
                    <option value="{{ $value }}" @selected($month === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <p class="text-xs text-muted pb-3">Abre sempre no mês atual. Troque para conferir meses anteriores.</p>
        </form>
    </div>

    {{-- Resumo do mês --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Receita · {{ $monthLabel }}</p>
            <p class="text-2xl font-bold text-ink mt-1">R$ {{ number_format($totais['receita'], 2, ',', '.') }}</p>
            <p class="text-[10px] text-muted mt-0.5">{{ $totais['pagamentos'] }} pagamentos</p>
        </div>
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Prêmios a pagar</p>
            <p class="text-2xl font-bold text-green mt-1">R$ {{ number_format($totais['premios'], 2, ',', '.') }}</p>
            <p class="text-[10px] text-muted mt-0.5">{{ $totais['carros_premiados'] }} carro(s) premiado(s)</p>
        </div>
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Carros cadastrados</p>
            <p class="text-2xl font-bold text-ink mt-1">{{ $carros->count() }}</p>
            <p class="text-[10px] text-muted mt-0.5">vindos do cadastro de ônibus</p>
        </div>
        <div class="bg-white rounded-2xl border border-border p-4 shadow-card">
            <p class="text-[10px] font-bold uppercase text-muted tracking-wider">Faixas de prêmio</p>
            <p class="text-[11px] text-ink mt-1 leading-snug">
                R$ 600–799 → <strong>R$ 50</strong><br>
                R$ 800–999 → <strong>R$ 80</strong><br>
                R$ 1.000+ → <strong>R$ 100</strong>
            </p>
        </div>
    </div>

    {{-- Avisos acionáveis --}}
    @php $semMotorista = $carros->where('premio', '>', 0)->where('motoristas', 0); @endphp
    @if($semMotorista->count())
    <div class="bg-amber-50 border border-amber-300 rounded-2xl p-4">
        <p class="text-sm font-bold text-amber-900">
            {{ $semMotorista->count() }} carro(s) ganharam prêmio mas não têm motorista cadastrado
        </p>
        <p class="text-xs text-amber-800 mt-1">
            {{ $semMotorista->pluck('carro')->join(', ') }} — mande o link de cadastro antes de pagar,
            senão não há para quem repassar.
        </p>
    </div>
    @endif

    {{-- Tabela por carro --}}
    <div class="bg-white rounded-2xl border border-border shadow-card overflow-hidden">
        <div class="px-5 py-4 border-b border-border flex items-center justify-between flex-wrap gap-2">
            <div>
                <h3 class="font-bold text-ink">Quanto cada carro fez em {{ $monthLabel }}</h3>
                <p class="text-xs text-muted mt-0.5">O prêmio é do carro e dividido entre os motoristas dele.</p>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-surface">
                    <tr class="text-[10px] font-bold uppercase tracking-wider text-muted">
                        <th class="text-left px-5 py-3">Carro</th>
                        <th class="text-right px-3 py-3">Pagtos</th>
                        <th class="text-right px-3 py-3">Receita</th>
                        <th class="text-right px-3 py-3">Prêmio do carro</th>
                        <th class="text-center px-3 py-3">Motoristas</th>
                        <th class="text-right px-5 py-3">Cada um recebe</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse($carros as $c)
                    <tr class="{{ $c['premio'] > 0 ? 'bg-green-pale/30' : '' }}">
                        <td class="px-5 py-3">
                            <p class="font-bold text-ink">{{ $c['carro'] }}</p>
                            <p class="text-[10px] text-muted font-mono">{{ $c['serial'] }}</p>
                            @unless($c['ativo'])
                            <span class="text-[10px] text-muted">inativo</span>
                            @endunless
                        </td>
                        <td class="px-3 py-3 text-right text-muted">{{ $c['pagamentos'] }}</td>
                        <td class="px-3 py-3 text-right font-semibold text-ink">
                            R$ {{ number_format($c['receita'], 2, ',', '.') }}
                        </td>
                        <td class="px-3 py-3 text-right">
                            @if($c['premio'] > 0)
                            <span class="inline-block px-2.5 py-1 rounded-lg bg-green text-white font-bold text-xs">
                                R$ {{ number_format($c['premio'], 2, ',', '.') }}
                            </span>
                            @else
                            <span class="text-muted text-xs">—</span>
                            @if($c['proxima_faixa'])
                            <p class="text-[10px] text-muted mt-1">
                                faltam R$ {{ number_format($c['proxima_faixa']['falta'], 2, ',', '.') }}
                                para R$ {{ number_format($c['proxima_faixa']['premio'], 0, ',', '.') }}
                            </p>
                            @endif
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            @if($c['motoristas'] > 0)
                                <span class="font-semibold text-ink">{{ $c['motoristas'] }}</span>
                            @else
                                <span class="text-amber-700 text-xs font-semibold">nenhum</span>
                            @endif
                        </td>
                        <td class="px-5 py-3 text-right">
                            @if($c['premio'] > 0 && $c['motoristas'] > 0)
                            <span class="font-bold text-green-dark">
                                R$ {{ number_format($c['premio_por_motorista'], 2, ',', '.') }}
                            </span>
                            @else
                            <span class="text-muted text-xs">—</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="px-5 py-10 text-center text-muted text-sm">
                        Nenhum ônibus cadastrado. Cadastre em
                        <a href="{{ route('admin.mikrotik.remote') }}" class="text-green underline">MikroTik &rsaquo; Ônibus</a>.
                    </td></tr>
                    @endforelse
                </tbody>
                @if($carros->count())
                <tfoot class="bg-surface font-bold text-ink">
                    <tr>
                        <td class="px-5 py-3">Total</td>
                        <td class="px-3 py-3 text-right">{{ $totais['pagamentos'] }}</td>
                        <td class="px-3 py-3 text-right">R$ {{ number_format($totais['receita'], 2, ',', '.') }}</td>
                        <td class="px-3 py-3 text-right text-green-dark">R$ {{ number_format($totais['premios'], 2, ',', '.') }}</td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>

        <div class="px-5 py-3 border-t border-border bg-surface">
            <p class="text-[11px] text-muted leading-relaxed">
                <strong>Como a receita é atribuída:</strong> o pagamento não guarda em qual ônibus foi feito.
                Usamos o último ônibus em que o passageiro se conectou. Para quem viaja uma vez é exato;
                quem andou em carros diferentes no mesmo mês fica contado no último.
            </p>
        </div>
    </div>
</div>
