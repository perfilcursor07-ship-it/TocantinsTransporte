@extends('layouts.admin')

@section('title', $sponsor->exists ? 'Editar parceiro' : 'Novo parceiro')

@section('content')
@php
    $cfg = $sponsor->config ?? [];
    $tipo = old('type', $sponsor->type ?? 'video');
@endphp

<div class="container mx-auto px-4 py-8">
    <div class="max-w-3xl mx-auto">

        <div class="mb-6">
            <a href="{{ route('admin.sponsors.index') }}" class="text-sm text-gray-500 hover:text-gray-800">&larr; Parceiros</a>
            <h1 class="text-3xl font-bold text-gray-800 mt-1">
                {{ $sponsor->exists ? 'Editar '.$sponsor->name : 'Novo parceiro' }}
            </h1>
        </div>

        @if($errors->any())
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-lg">
                <p class="text-red-800 font-bold mb-1">Nada foi salvo. Corrija:</p>
                <ul class="list-disc list-inside text-sm text-red-700">
                    @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
              action="{{ $sponsor->exists ? route('admin.sponsors.update', $sponsor) : route('admin.sponsors.store') }}"
              class="space-y-6">
            @csrf
            @if($sponsor->exists) @method('PUT') @endif

            {{-- Tipo --}}
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6">
                <h2 class="font-bold text-gray-800 mb-1">Como o parceiro aparece</h2>
                <p class="text-xs text-gray-500 mb-4">Isso define o que o passageiro vê e o que o parceiro precisa entregar.</p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-3">
                    @foreach([
                        'video'  => ['🎬', 'Vídeo', 'Ele entrega um MP4. Sem integração.'],
                        'iframe' => ['🖼️', 'Página embutida', 'Abre dentro do portal. Ele precisa liberar o embed.'],
                        'api'    => ['🔌', 'API', 'Nosso servidor chama o dele e recebe um link.'],
                    ] as $valor => [$icone, $titulo, $desc])
                        <label class="cursor-pointer">
                            <input type="radio" name="type" value="{{ $valor }}" class="peer sr-only"
                                   {{ $tipo === $valor ? 'checked' : '' }}
                                   onchange="mostrarCampos(this.value)">
                            <div class="border-2 border-gray-200 peer-checked:border-green-500 peer-checked:bg-green-50 rounded-xl p-4 h-full transition-all">
                                <div class="text-2xl mb-1">{{ $icone }}</div>
                                <p class="font-bold text-sm text-gray-800">{{ $titulo }}</p>
                                <p class="text-[11px] text-gray-500 mt-1 leading-snug">{{ $desc }}</p>
                            </div>
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- Identificação e texto --}}
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6 space-y-4">
                <h2 class="font-bold text-gray-800">Identificação e texto do botão</h2>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Nome do parceiro *</label>
                    <input name="name" required maxlength="120" value="{{ old('name', $sponsor->name) }}"
                           class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Título do botão</label>
                        <input name="headline" maxlength="120" value="{{ old('headline', $sponsor->headline) }}"
                               placeholder="Vazio = Tenha agora R$X de desconto"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Linha de apoio</label>
                        <input name="subheadline" maxlength="160" value="{{ old('subheadline', $sponsor->subheadline) }}"
                               placeholder="Ex.: Conheça a conta digital"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Desconto (R$)</label>
                        <input type="number" step="0.01" min="0.01" max="99.99" name="discount_amount"
                               value="{{ old('discount_amount', $sponsor->discount_amount) }}" placeholder="padrão"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                        <p class="text-[11px] text-gray-500 mt-1">Vazio usa o valor global.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Tempo mínimo (seg)</label>
                        <input type="number" min="1" max="300" name="min_seconds"
                               value="{{ old('min_seconds', $sponsor->min_seconds) }}" placeholder="padrão"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                        <p class="text-[11px] text-gray-500 mt-1">Vazio usa o global.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Pede CPF?</label>
                        <select name="collects_cpf" class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                            <option value="0" {{ old('collects_cpf', $sponsor->collects_cpf) ? '' : 'selected' }}>Não</option>
                            <option value="1" {{ old('collects_cpf', $sponsor->collects_cpf) ? 'selected' : '' }}>Sim</option>
                        </select>
                        <p class="text-[11px] text-gray-500 mt-1">Só para parceiro de crédito.</p>
                    </div>
                </div>
            </div>

            {{-- VÍDEO --}}
            <div data-campos="video" class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6 space-y-4 hidden">
                <h2 class="font-bold text-gray-800">🎬 Vídeo</h2>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">URL do vídeo</label>
                    <input name="video_url" value="{{ old('video_url', $cfg['video_url'] ?? '') }}"
                           placeholder="/videos/parceiro.mp4"
                           class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    <p class="text-[11px] text-gray-500 mt-1">MP4 em <code>public/videos/</code>, ou URL externa já liberada no firewall.</p>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Duração (seg)</label>
                    <input type="number" min="1" max="600" name="duration" value="{{ old('duration', $cfg['duration'] ?? '') }}"
                           class="w-full md:w-40 border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    <p class="text-[11px] text-gray-500 mt-1">O desconto sai quando o vídeo termina.</p>
                </div>
            </div>

            {{-- IFRAME --}}
            <div data-campos="iframe" class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6 space-y-4 hidden">
                <h2 class="font-bold text-gray-800">🖼️ Página embutida</h2>

                <div class="bg-amber-50 border border-amber-300 rounded-xl p-3">
                    <p class="text-[12px] text-amber-900 leading-snug">
                        <strong>O parceiro precisa liberar o embed.</strong> O servidor dele deve enviar
                        <code>frame-ancestors {{ config('app.url') }}</code> e <strong>não</strong> enviar
                        <code>X-Frame-Options: DENY</code>. Sem isso o modal abre em branco — o navegador bloqueia.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">URL da página</label>
                    <input type="url" name="page_url" value="{{ old('page_url', $cfg['page_url'] ?? '') }}"
                           placeholder="https://parceiro.com.br/oferta"
                           class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Altura do modal (px)</label>
                        <input type="number" min="200" max="900" name="height" value="{{ old('height', $cfg['height'] ?? 520) }}"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Parâmetro do CPF na URL</label>
                        <input name="cpf_query_param" value="{{ old('cpf_query_param', $cfg['cpf_query_param'] ?? '') }}"
                               placeholder="ex.: documento"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                        <p class="text-[11px] text-gray-500 mt-1">Só se "Pede CPF" estiver em Sim.</p>
                    </div>
                </div>
            </div>

            {{-- API --}}
            <div data-campos="api" class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6 space-y-4 hidden">
                <h2 class="font-bold text-gray-800">🔌 API (handoff)</h2>

                <div class="bg-blue-50 border border-blue-300 rounded-xl p-3">
                    <p class="text-[12px] text-blue-900 leading-snug">
                        Entregue ao parceiro o arquivo <code>docs/INTEGRACAO-PARCEIROS.md</code> (Nível 3).
                        <strong>As credenciais ficam no <code>.env</code></strong> — aqui vai só o nome da variável.
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Base URL da API</label>
                    <input type="url" name="base_url" value="{{ old('base_url', $cfg['base_url'] ?? '') }}"
                           placeholder="https://api.parceiro.com.br"
                           class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Caminho do token</label>
                        <input name="token_path" value="{{ old('token_path', $cfg['token_path'] ?? '/token') }}"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Caminho da sessão</label>
                        <input name="session_path" value="{{ old('session_path', $cfg['session_path'] ?? '/sessions') }}"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Formato do payload</label>
                        <select name="style" class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                            <option value="standard" {{ old('style', $cfg['style'] ?? '') === 'cashcash' ? '' : 'selected' }}>Padrão (nossa spec)</option>
                            <option value="cashcash" {{ old('style', $cfg['style'] ?? '') === 'cashcash' ? 'selected' : '' }}>Cash Cash</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Como abrir</label>
                        <select name="open_in" class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                            <option value="redirect" {{ old('open_in', $cfg['open_in'] ?? '') === 'iframe' ? '' : 'selected' }}>Redirect (sai do portal)</option>
                            <option value="iframe" {{ old('open_in', $cfg['open_in'] ?? '') === 'iframe' ? 'selected' : '' }}>Modal (exige liberar embed)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Exige localização?</label>
                        <select name="requires_location" class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 focus:border-green-500 focus:outline-none">
                            <option value="0" {{ ($cfg['requires_location'] ?? false) ? '' : 'selected' }}>Não</option>
                            <option value="1" {{ ($cfg['requires_location'] ?? false) ? 'selected' : '' }}>Sim</option>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Variável do client_id</label>
                        <input name="client_id_env" value="{{ old('client_id_env', $cfg['client_id_env'] ?? '') }}"
                               placeholder="PARCEIRO_CLIENT_ID"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 font-mono text-sm focus:border-green-500 focus:outline-none">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Variável do client_secret</label>
                        <input name="client_secret_env" value="{{ old('client_secret_env', $cfg['client_secret_env'] ?? '') }}"
                               placeholder="PARCEIRO_CLIENT_SECRET"
                               class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 font-mono text-sm focus:border-green-500 focus:outline-none">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 p-6">
                <label class="block text-xs font-bold text-gray-700 mb-1">Observações internas</label>
                <textarea name="notes" rows="3" maxlength="1000"
                          class="w-full border-2 border-gray-300 rounded-xl px-4 py-3 text-sm focus:border-green-500 focus:outline-none">{{ old('notes', $sponsor->notes) }}</textarea>
            </div>

            <div class="flex gap-3">
                <button class="flex-1 bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-xl shadow">
                    {{ $sponsor->exists ? 'Salvar alterações' : 'Cadastrar parceiro' }}
                </button>
                <a href="{{ route('admin.sponsors.index') }}"
                   class="px-6 py-4 rounded-xl border-2 border-gray-300 text-gray-600 font-bold text-center">Cancelar</a>
            </div>
        </form>
    </div>
</div>

<script>
function mostrarCampos(tipo) {
    document.querySelectorAll('[data-campos]').forEach(function (bloco) {
        bloco.classList.toggle('hidden', bloco.dataset.campos !== tipo);
    });
}
mostrarCampos(@json($tipo));
</script>
@endsection
