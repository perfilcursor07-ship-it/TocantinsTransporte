@extends('layouts.admin')

@section('title', 'Descontos da proposta')

@section('content')
@php
    $rotulos = [
        'ativo' => ['Ativo', 'bg-emerald-100 text-emerald-800'],
        'usado' => ['Usado', 'bg-blue-100 text-blue-800'],
        'vencido' => ['Venceu sem uso', 'bg-gray-200 text-gray-700'],
        'sem_desconto' => ['Não liberado', 'bg-amber-100 text-amber-800'],
    ];
    $mascaraCpf = fn ($cpf) => strlen((string) $cpf) === 11
        ? substr($cpf, 0, 3) . '.***.***-' . substr($cpf, -2)
        : '—';
    $mascaraFone = function ($fone) {
        $d = preg_replace('/\D/', '', (string) $fone);
        return strlen($d) >= 10 ? '(' . substr($d, 0, 2) . ') ' . substr($d, 2, -4) . '-' . substr($d, -4) : ($fone ?: '—');
    };
    $filtro = fn (array $troca) => route('admin.discounts.index', array_filter(array_merge(
        ['dias' => $dias, 'situacao' => $situacao, 'busca' => $busca], $troca
    ), fn ($v) => $v !== null && $v !== ''));
@endphp

<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto space-y-6">

        <div>
            <h1 class="text-3xl font-bold text-gray-800 mb-1">Descontos da proposta</h1>
            <p class="text-gray-600 text-sm">
                Quem informou telefone e CPF, viu a proposta do parceiro e o que aconteceu com o desconto.
            </p>
        </div>

        @if(session('success'))
            <div class="bg-green-50 border-l-4 border-green-500 p-4 rounded-lg text-green-800 font-medium text-sm">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="bg-red-50 border-l-4 border-red-500 p-4 rounded-lg text-red-800 text-sm">{{ $errors->first() }}</div>
        @endif

        {{-- Regras --}}
        <form method="POST" action="{{ route('admin.discounts.update') }}" class="bg-white rounded-2xl shadow border border-gray-100 p-6">
            @csrf
            @method('PUT')
            <h2 class="text-lg font-bold text-gray-800 mb-1">Regras do desconto</h2>
            <p class="text-xs text-gray-500 mb-5">
                Hoje o desconto é de <strong>R$ {{ number_format($settings['discount_amount'], 2, ',', '.') }}</strong>
                e exige <strong>{{ $settings['min_seconds'] }} segundos</strong> na proposta
                (esses dois valores ficam em <a class="underline" href="{{ route('admin.settings.index') }}">Configurações</a>).
            </p>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div>
                    <label for="discount_validity_minutes" class="block text-sm font-bold text-gray-700 mb-2">Validade do desconto</label>
                    <div class="relative">
                        <input type="number" name="discount_validity_minutes" id="discount_validity_minutes" min="5" max="1440" required
                            value="{{ old('discount_validity_minutes', $settings['discount_validity_minutes']) }}"
                            class="w-full pl-4 pr-16 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-bold">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-sm">min</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        Depois de ver a proposta, o passageiro tem esse tempo para pagar com desconto.
                        O desconto vale <strong>uma vez</strong>: assim que ele paga, ou quando o prazo acaba, o preço volta ao normal.
                    </p>
                </div>
                <div>
                    <label for="discount_repeat_days" class="block text-sm font-bold text-gray-700 mb-2">Intervalo para ganhar de novo</label>
                    <div class="relative">
                        <input type="number" name="discount_repeat_days" id="discount_repeat_days" min="0" max="365" required
                            value="{{ old('discount_repeat_days', $settings['discount_repeat_days']) }}"
                            class="w-full pl-4 pr-16 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-bold">
                        <span class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-500 font-bold text-sm">dias</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        Dias que o mesmo passageiro (aparelho, telefone ou CPF) espera para ter outro desconto.
                        Nesse intervalo a oferta nem aparece para ele. <strong>0</strong> = pode ganhar de novo em toda viagem, desde que veja a proposta outra vez.
                    </p>
                </div>
            </div>

            <div class="mt-5 text-right">
                <button type="submit" class="bg-green-600 hover:bg-green-700 text-white font-bold px-6 py-3 rounded-xl shadow text-sm">Salvar regras</button>
            </div>
        </form>

        {{-- A proposta abre em cada ônibus? --}}
        <div class="bg-white rounded-2xl shadow border border-gray-100 p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-1">A proposta abre em cada ônibus?</h2>
            <p class="text-xs text-gray-500 mb-4">
                O portal testa, no aparelho de cada passageiro, se a página do parceiro
                @if($partnerOrigin)(<code class="bg-gray-100 px-1 rounded">{{ $partnerOrigin }}</code>)@endif
                carrega antes de ele pagar. Onde não abre, a oferta de desconto nem aparece e o passageiro paga o preço normal.
            </p>

            @if(count($alcance))
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-left text-xs text-gray-600">
                            <tr>
                                <th class="px-4 py-2 font-semibold">Ônibus</th>
                                <th class="px-4 py-2 font-semibold">Situação</th>
                                <th class="px-4 py-2 font-semibold">Último teste que abriu</th>
                                <th class="px-4 py-2 font-semibold">Último teste que não abriu</th>
                                <th class="px-4 py-2 font-semibold">Testes</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($alcance as $linha)
                            <tr>
                                <td class="px-4 py-2">
                                    <div class="font-medium text-gray-800">{{ $linha['nome'] }}</div>
                                    <div class="text-[11px] text-gray-400">{{ $linha['placa'] ? $linha['placa'] . ' · ' : '' }}{{ $linha['serial'] }}</div>
                                </td>
                                <td class="px-4 py-2">
                                    @if($linha['abre'])
                                        <span class="px-2 py-1 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800">Abre</span>
                                    @else
                                        <span class="px-2 py-1 rounded-full text-xs font-bold bg-red-100 text-red-800">Não abre</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $linha['ultimo_ok']?->format('d/m H:i') ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-700 whitespace-nowrap">{{ $linha['ultima_falha']?->format('d/m H:i') ?? '—' }}</td>
                                <td class="px-4 py-2 text-gray-500 text-xs whitespace-nowrap">{{ $linha['ok'] }} abriram · {{ $linha['falhas'] }} não</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500">Ainda não há testes registrados. Eles aparecem aqui conforme os passageiros abrem o portal nos ônibus.</p>
            @endif

            <details class="mt-4 text-xs text-gray-600">
                <summary class="cursor-pointer font-bold text-gray-700">Um ônibus aparece como "Não abre". O que fazer?</summary>
                <p class="mt-2">Falta liberar o parceiro no MikroTik daquele ônibus. No terminal do roteador:</p>
                <pre class="mt-2 bg-gray-900 text-gray-100 rounded-lg p-3 overflow-x-auto">/ip hotspot walled-garden add dst-host=cashcash.com.br comment="Parceiro: Cash Cash"
/ip hotspot walled-garden add dst-host=*.cashcash.com.br comment="Parceiro: Cash Cash *"</pre>
                <p class="mt-2">Depois disso o próximo passageiro que abrir o portal naquele ônibus já atualiza a situação para "Abre".</p>
            </details>
        </div>

        {{-- Resumo --}}
        <div class="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs text-gray-500">Propostas enviadas</p>
                <p class="text-2xl font-bold text-gray-800">{{ $resumo['propostas'] }}</p>
                <p class="text-xs text-gray-400">últimos {{ $dias }} {{ $dias === 1 ? 'dia' : 'dias' }}</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs text-gray-500">Descontos liberados</p>
                <p class="text-2xl font-bold text-gray-800">{{ $resumo['liberados'] }}</p>
                <p class="text-xs text-gray-400">viram a proposta até o fim</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs text-gray-500">Usados em pagamento</p>
                <p class="text-2xl font-bold text-blue-700">{{ $resumo['usados'] }}</p>
                <p class="text-xs text-gray-400">R$ {{ number_format($resumo['valor_usado'], 2, ',', '.') }} em desconto</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs text-gray-500">Ativos agora</p>
                <p class="text-2xl font-bold text-emerald-700">{{ $resumo['ativos'] }}</p>
                <p class="text-xs text-gray-400">dentro do prazo, sem pagar</p>
            </div>
            <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4">
                <p class="text-xs text-gray-500">Venceram sem uso</p>
                <p class="text-2xl font-bold text-gray-600">{{ $resumo['vencidos'] }}</p>
                <p class="text-xs text-gray-400">preço voltou ao normal</p>
            </div>
        </div>

        {{-- Lista --}}
        <div class="bg-white rounded-2xl shadow border border-gray-100 overflow-hidden">
            <div class="p-4 border-b border-gray-100 flex flex-wrap items-center gap-2">
                @foreach([1 => 'Hoje', 7 => '7 dias', 30 => '30 dias', 90 => '90 dias'] as $valor => $nome)
                    <a href="{{ $filtro(['dias' => $valor]) }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-bold {{ $dias === $valor ? 'bg-green-600 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $nome }}</a>
                @endforeach
                <span class="mx-1 h-5 w-px bg-gray-200"></span>
                <a href="{{ $filtro(['situacao' => null]) }}"
                   class="px-3 py-1.5 rounded-lg text-xs font-bold {{ !$situacao ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">Todas</a>
                @foreach($rotulos as $chave => [$nome])
                    <a href="{{ $filtro(['situacao' => $chave]) }}"
                       class="px-3 py-1.5 rounded-lg text-xs font-bold {{ $situacao === $chave ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">{{ $nome }}</a>
                @endforeach

                <form method="GET" action="{{ route('admin.discounts.index') }}" class="ml-auto flex items-center gap-2">
                    <input type="hidden" name="dias" value="{{ $dias }}">
                    @if($situacao)<input type="hidden" name="situacao" value="{{ $situacao }}">@endif
                    <input type="text" name="busca" value="{{ $busca }}" placeholder="Telefone ou CPF"
                        class="px-3 py-1.5 border border-gray-300 rounded-lg text-sm w-44 focus:outline-none focus:border-green-500">
                    <button class="px-3 py-1.5 rounded-lg text-xs font-bold bg-gray-100 text-gray-700 hover:bg-gray-200">Buscar</button>
                </form>
            </div>

            @if($leads->count())
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs text-gray-600">
                        <tr>
                            <th class="px-4 py-3 font-semibold">Passageiro</th>
                            <th class="px-4 py-3 font-semibold">Proposta aberta</th>
                            <th class="px-4 py-3 font-semibold">Tempo na proposta</th>
                            <th class="px-4 py-3 font-semibold">Desconto</th>
                            <th class="px-4 py-3 font-semibold">Vale até</th>
                            <th class="px-4 py-3 font-semibold">Situação</th>
                            <th class="px-4 py-3 font-semibold">Pagamento</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($leads as $lead)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ $mascaraFone($lead->phone) }}</div>
                                <div class="text-xs text-gray-500">CPF {{ $mascaraCpf($lead->cpf) }}</div>
                                <div class="text-[11px] text-gray-400 font-mono">{{ $lead->mac_address ?: 'sem aparelho' }}</div>
                            </td>
                            <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $lead->link_opened_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td class="px-4 py-3 text-gray-700">
                                @if($lead->dwell_seconds !== null)
                                    {{ $lead->dwell_seconds >= 120 ? intdiv($lead->dwell_seconds, 60) . ' min' : $lead->dwell_seconds . ' s' }}
                                @else
                                    <span class="text-gray-400">não voltou</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($lead->discount_granted_at)
                                    <div class="font-bold text-gray-800">R$ {{ number_format((float) $lead->discount_amount, 2, ',', '.') }}</div>
                                    <div class="text-xs text-gray-500">{{ $lead->discount_granted_at->format('d/m H:i') }}</div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-700 whitespace-nowrap">{{ $lead->discountExpiresAt()?->format('d/m H:i') ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 rounded-full text-xs font-bold {{ $rotulos[$lead->situacao][1] }}">{{ $rotulos[$lead->situacao][0] }}</span>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap">
                                @if($lead->pagamento)
                                    <div class="font-medium text-gray-800">R$ {{ number_format((float) $lead->pagamento->amount, 2, ',', '.') }}</div>
                                    <div class="text-xs text-gray-500">#{{ $lead->pagamento->id }} · {{ $lead->pagamento->paid_at?->format('d/m H:i') }}</div>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="p-4 border-t border-gray-100">{{ $leads->links() }}</div>
            @else
            <div class="text-center py-12 text-gray-500 text-sm">Nenhuma proposta neste período{{ $situacao || $busca ? ' com esse filtro' : '' }}.</div>
            @endif
        </div>

        <p class="text-xs text-gray-400">
            "Usado" é o primeiro pagamento concluído do passageiro depois de o desconto ser liberado, dentro do prazo.
            O prazo mostrado em "Vale até" usa a validade configurada hoje.
        </p>
    </div>
</div>
@endsection
