@extends('layouts.admin')

@section('title', 'Parceiros do desconto')

@section('content')
@php
    $rotulos = ['video' => '🎬 Vídeo', 'iframe' => '🖼️ Página embutida', 'api' => '🔌 API'];
    $ativo = $sponsors->firstWhere('is_active', true);
@endphp

<div class="container mx-auto px-4 py-8">
    <div class="max-w-6xl mx-auto">

        <div class="flex items-start justify-between mb-6 gap-4 flex-wrap">
            <div>
                <h1 class="text-3xl font-bold text-gray-800 mb-1">🤝 Parceiros do desconto</h1>
                <p class="text-gray-600 text-sm">Quem ocupa o espaço publicitário antes do pagamento. Só um por vez.</p>
            </div>
            <a href="{{ route('admin.sponsors.create') }}"
               class="bg-green-600 hover:bg-green-700 text-white font-bold px-5 py-3 rounded-xl shadow text-sm">
                + Novo parceiro
            </a>
        </div>

        @if(session('success'))
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-4 rounded-lg text-green-800 font-medium text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if(session('warning'))
            <div class="bg-amber-50 border-l-4 border-amber-500 p-4 mb-4 rounded-lg text-amber-900 font-medium text-sm">
                {{ session('warning') }}
            </div>
        @endif

        <div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-5 mb-6">
            <p class="text-sm text-gray-700">
                <strong>Espaço atual:</strong>
                @if($ativo)
                    <span class="text-green-700 font-bold">{{ $ativo->name }}</span>
                    <span class="text-gray-500">({{ $rotulos[$ativo->type] ?? $ativo->type }})</span>
                @else
                    <span class="text-gray-500">vazio — nenhum parceiro aparece no portal</span>
                @endif
            </p>
            <p class="text-xs text-gray-500 mt-1">
                O espaço só aparece se o desconto estiver habilitado em
                <a href="{{ route('admin.settings.index') }}" class="text-green-700 underline font-semibold">Configurações</a>.
                Para integrar um parceiro novo, entregue a ele o arquivo
                <code class="bg-gray-100 px-1 rounded">docs/INTEGRACAO-PARCEIROS.md</code>.
            </p>
        </div>

        @if($sponsors->isEmpty())
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-10 text-center">
                <p class="text-gray-500 mb-4">Nenhum parceiro cadastrado ainda.</p>
                <a href="{{ route('admin.sponsors.create') }}" class="text-green-700 font-bold underline">Cadastrar o primeiro</a>
            </div>
        @else
            <div class="space-y-3">
                @foreach($sponsors as $s)
                    <div class="bg-white rounded-2xl shadow border-2 {{ $s->is_active ? 'border-green-400' : 'border-gray-200' }} p-5">
                        <div class="flex items-start justify-between gap-4 flex-wrap">
                            <div class="flex-1 min-w-[260px]">
                                <div class="flex items-center gap-2 flex-wrap mb-1">
                                    <span class="font-bold text-gray-900">{{ $s->name }}</span>
                                    <span class="text-[11px] bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full font-semibold">
                                        {{ $rotulos[$s->type] ?? $s->type }}
                                    </span>
                                    @if($s->is_active)
                                        <span class="text-[11px] bg-green-100 text-green-800 px-2 py-0.5 rounded-full font-bold">NO AR</span>
                                    @endif
                                    @if($s->collects_cpf)
                                        <span class="text-[11px] bg-purple-100 text-purple-800 px-2 py-0.5 rounded-full font-semibold">pede CPF</span>
                                    @endif
                                </div>

                                <p class="text-xs text-gray-600">
                                    {{ $s->headline ?: 'texto padrão' }} — {{ $s->subheadline ?: '—' }}
                                </p>

                                <p class="text-xs text-gray-500 mt-1">
                                    Desconto: <strong>{{ $s->discount_amount ? 'R$ '.number_format($s->discount_amount, 2, ',', '.') : 'padrão' }}</strong>
                                    · Tempo mínimo: <strong>{{ $s->min_seconds ? $s->min_seconds.'s' : 'padrão' }}</strong>
                                    · Exibições: <strong>{{ $s->leads_count }}</strong>
                                    · Descontos dados: <strong>{{ $s->leads_com_desconto_count }}</strong>
                                </p>
                            </div>

                            <div class="flex items-center gap-2 flex-wrap">
                                <a href="{{ route('admin.sponsors.edit', $s) }}"
                                   class="text-xs font-bold text-gray-600 hover:text-gray-900 border border-gray-300 px-3 py-2 rounded-lg">
                                    Editar
                                </a>

                                @if($s->is_active)
                                    <form method="POST" action="{{ route('admin.sponsors.deactivate', $s) }}">
                                        @csrf
                                        <button class="text-xs font-bold text-amber-700 hover:text-amber-900 border border-amber-300 px-3 py-2 rounded-lg">
                                            Tirar do ar
                                        </button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.sponsors.activate', $s) }}">
                                        @csrf
                                        <button class="text-xs font-bold text-white bg-green-600 hover:bg-green-700 px-3 py-2 rounded-lg">
                                            Colocar no ar
                                        </button>
                                    </form>
                                @endif

                                <form method="POST" action="{{ route('admin.sponsors.destroy', $s) }}"
                                      onsubmit="return confirm('Remover {{ $s->name }}? As exibições já registradas são mantidas.')">
                                    @csrf @method('DELETE')
                                    <button class="text-xs font-bold text-red-600 hover:text-red-800 border border-red-200 px-3 py-2 rounded-lg">
                                        Remover
                                    </button>
                                </form>
                            </div>
                        </div>

                        @if($s->notes)
                            <p class="text-[11px] text-gray-400 mt-3 border-t pt-2">{{ $s->notes }}</p>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
