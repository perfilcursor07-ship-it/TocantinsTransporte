@extends('layouts.admin')

@section('title', 'Chat com ' . $conversation->visitor_name)
@section('page-title', '💬 Atendimento')

@section('breadcrumb')
    <span class="mx-2">/</span>
    <a href="{{ route('admin.chat.index') }}" class="hover:text-emerald-600">Chat</a>
    <span class="mx-2">/</span>
    <span class="text-emerald-600 font-medium">{{ $conversation->visitor_name }}</span>
@endsection

@section('content')
    @if(session('success'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</div>
    @endif
<div class="flex flex-col lg:flex-row gap-4 lg:gap-6 h-auto lg:h-[calc(100vh-160px)]">
    <!-- Área Principal do Chat -->
    <div class="flex-1 flex flex-col bg-white rounded-2xl shadow-xl overflow-hidden min-h-[60vh] lg:min-h-0">
        <!-- Header do Chat -->
        <div class="bg-gradient-to-r from-emerald-500 via-green-500 to-teal-600 p-3 lg:p-4 text-white relative overflow-hidden">
            <div class="absolute top-0 right-0 w-40 h-40 bg-white/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
            <div class="relative flex items-center justify-between">
                <div class="flex items-center space-x-2 lg:space-x-4">
                    <a href="{{ route('admin.chat.index') }}" class="w-8 h-8 lg:w-10 lg:h-10 bg-white/20 hover:bg-white/30 rounded-xl flex items-center justify-center transition-colors flex-shrink-0">
                        <svg class="w-4 h-4 lg:w-5 lg:h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                        </svg>
                    </a>
                    <div class="w-10 h-10 lg:w-14 lg:h-14 rounded-2xl bg-white/20 backdrop-blur-sm flex items-center justify-center text-xl lg:text-2xl font-bold shadow-lg flex-shrink-0">
                        {{ strtoupper(substr($conversation->visitor_name, 0, 1)) }}
                    </div>
                    <div class="min-w-0">
                        <h3 class="font-bold text-sm lg:text-lg truncate">{{ $conversation->visitor_name }}</h3>
                        <div class="flex items-center space-x-2 text-xs lg:text-sm text-emerald-100">
                            <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $conversation->status === 'active' ? 'bg-green-300 animate-pulse' : ($conversation->status === 'pending' ? 'bg-yellow-300' : 'bg-gray-300') }}"></span>
                            <span>{{ $conversation->status === 'active' ? 'Online' : ($conversation->status === 'pending' ? 'Aguardando' : 'Offline') }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center space-x-1 lg:space-x-2 flex-shrink-0">
                    <!-- Botão Info Mobile -->
                    <button type="button" id="toggle-sidebar-btn" class="lg:hidden bg-white/20 hover:bg-white/30 p-2 rounded-xl transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </button>
                    @if($conversation->status !== 'closed')
                    <form action="{{ route('admin.chat.close', $conversation->id) }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="bg-white/20 hover:bg-white/30 px-2 lg:px-4 py-2 rounded-xl text-xs lg:text-sm font-medium transition-colors flex items-center space-x-1 lg:space-x-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                            <span class="hidden sm:inline">Encerrar</span>
                        </button>
                    </form>
                    @endif
                    <form action="{{ route('admin.chat.destroy', $conversation->id) }}" method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir esta conversa?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="bg-red-500/80 hover:bg-red-500 px-2 lg:px-3 py-2 rounded-xl text-sm transition-colors">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Área de Mensagens -->
        <div id="messages-container" class="flex-1 overflow-y-auto p-3 lg:p-6 space-y-3 lg:space-y-4 bg-gradient-to-b from-gray-50 to-white">
            <!-- Data inicial -->
            <div class="flex justify-center">
                <span class="text-xs text-gray-400 bg-white px-4 py-1.5 rounded-full shadow-sm border">
                    {{ $conversation->created_at->format('d/m/Y') }}
                </span>
            </div>

            @foreach($conversation->messages as $message)
            @php
                $msgType = $message->type ?? 'text';
                $isAI = ($message->sender_type === 'admin') && is_null($message->admin_id) && !empty($message->metadata['ai']);
                $aiName = $message->metadata['ai_name'] ?? 'Ana';
                $senderLabel = $isAI ? "{$aiName} (IA)" : ($message->admin->name ?? 'admin');
            @endphp

            {{-- 📡 Cartão: admin solicitou teste de conexão --}}
            @if($msgType === 'probe_request')
                <div class="flex justify-center chat-message-enter">
                    <div class="max-w-md w-full bg-gradient-to-br from-blue-50 to-indigo-50 border border-blue-200 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-8 h-8 rounded-lg bg-blue-500 flex items-center justify-center text-white">
                                📡
                            </div>
                            <div class="flex-1">
                                <p class="text-xs font-bold text-blue-700 uppercase tracking-wider">Teste de conexão solicitado</p>
                                <p class="text-[10px] text-gray-500">por {{ $senderLabel }} · {{ $message->created_at->format('H:i') }}</p>
                            </div>
                        </div>
                        <p class="text-sm text-gray-700 mb-3">{{ $message->message }}</p>
                        @php
                            $probeUrl = $message->metadata['probe_url'] ?? null;
                            $probeExpires = isset($message->metadata['expires_at']) ? \Carbon\Carbon::parse($message->metadata['expires_at']) : null;
                            $expired = $probeExpires && $probeExpires->isPast();
                            $probe = \App\Models\ConnectivityProbe::find($message->metadata['probe_id'] ?? 0);
                            $completed = $probe && $probe->isCompleted();
                        @endphp
                        @if($completed)
                            <div class="text-xs text-emerald-700 bg-emerald-100 rounded-lg p-2 text-center font-semibold">
                                ✅ Teste concluído — ver resultado abaixo
                            </div>
                        @elseif($expired)
                            <div class="text-xs text-gray-600 bg-gray-100 rounded-lg p-2 text-center">
                                ⏰ Link expirou (30 min sem resposta)
                            </div>
                        @elseif($probeUrl)
                            <a href="{{ $probeUrl }}" target="_blank"
                               class="block w-full bg-blue-600 hover:bg-blue-700 text-white text-center py-2.5 rounded-xl font-semibold text-sm transition">
                                ▶ Abrir teste (visão do cliente)
                            </a>
                            <div class="mt-2 flex items-center gap-1.5">
                                <input type="text" readonly value="{{ $probeUrl }}"
                                       class="flex-1 text-[11px] font-mono bg-white border rounded px-2 py-1 text-gray-500"
                                       onclick="this.select()">
                                <button onclick="navigator.clipboard.writeText('{{ $probeUrl }}'); this.textContent='✓';"
                                        class="text-[11px] px-2 py-1 bg-white border rounded hover:bg-gray-50 font-semibold">
                                    copiar
                                </button>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-1 text-center">
                                Expira {{ $probeExpires ? $probeExpires->diffForHumans() : 'em 30min' }}
                            </p>
                        @endif
                    </div>
                </div>

            {{-- ✅/❌ Cartão: resultado do teste --}}
            @elseif($msgType === 'probe_result')
                @php
                    $verdict = $message->metadata['verdict'] ?? 'failed';
                    $results = $message->metadata['results'] ?? [];
                    $verdictMap = [
                        'excellent' => ['bg' => 'from-emerald-50 to-green-50', 'border' => 'border-emerald-300', 'icon' => '🚀', 'label' => 'Conexão excelente', 'text' => 'text-emerald-700'],
                        'good'      => ['bg' => 'from-emerald-50 to-teal-50', 'border' => 'border-emerald-200', 'icon' => '✅', 'label' => 'Conexão OK', 'text' => 'text-emerald-700'],
                        'poor'      => ['bg' => 'from-amber-50 to-yellow-50', 'border' => 'border-amber-300', 'icon' => '⚠️', 'label' => 'Conexão ruim', 'text' => 'text-amber-700'],
                        'failed'    => ['bg' => 'from-red-50 to-rose-50', 'border' => 'border-red-300', 'icon' => '❌', 'label' => 'Sem internet', 'text' => 'text-red-700'],
                    ];
                    $v = $verdictMap[$verdict] ?? $verdictMap['failed'];
                @endphp
                <div class="flex justify-center chat-message-enter">
                    <div class="max-w-md w-full bg-gradient-to-br {{ $v['bg'] }} border-2 {{ $v['border'] }} rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="text-3xl">{{ $v['icon'] }}</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold {{ $v['text'] }} uppercase tracking-wider">Resultado do teste</p>
                                <p class="text-base font-bold {{ $v['text'] }}">{{ $v['label'] }}</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        <div class="bg-white/70 rounded-xl p-3 space-y-1.5 text-sm">
                            <div class="flex justify-between">
                                <span class="text-gray-600">DNS resolve:</span>
                                <span class="font-mono {{ ($results['dns_ok'] ?? false) ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ ($results['dns_ok'] ?? false) ? '✅ OK' : '❌ falhou' }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Google HTTPS:</span>
                                <span class="font-mono {{ ($results['google_ok'] ?? false) ? 'text-emerald-600' : 'text-red-600' }}">
                                    {{ ($results['google_ok'] ?? false) ? '✅ OK' : '❌ falhou' }}
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Download:</span>
                                <span class="font-mono font-bold text-gray-800">
                                    @if(isset($results['download_mbps']) && $results['download_mbps'] > 0)
                                        {{ number_format($results['download_mbps'], 1) }} Mbps
                                    @else
                                        <span class="text-red-600">❌ falhou</span>
                                    @endif
                                </span>
                            </div>
                            <div class="flex justify-between">
                                <span class="text-gray-600">Latência (ping):</span>
                                <span class="font-mono font-bold text-gray-800">
                                    @if(isset($results['latency_ms']) && $results['latency_ms'] !== null)
                                        {{ round($results['latency_ms']) }} ms
                                    @else
                                        <span class="text-red-600">❌ falhou</span>
                                    @endif
                                </span>
                            </div>
                        </div>
                        @if(isset($message->metadata['client_ip']) || isset($message->metadata['user_agent']))
                        <details class="mt-3 text-[10px] text-gray-500">
                            <summary class="cursor-pointer hover:text-gray-700 font-semibold">Detalhes técnicos</summary>
                            <div class="mt-1 space-y-0.5 font-mono">
                                @if(isset($message->metadata['client_ip']))
                                    <div>IP cliente: {{ $message->metadata['client_ip'] }}</div>
                                @endif
                                @if(isset($results['connection_type']))
                                    <div>Tipo conexão: {{ $results['connection_type'] }}</div>
                                @endif
                                @if(isset($results['screen']))
                                    <div>Tela: {{ $results['screen'] }}</div>
                                @endif
                                @if(isset($message->metadata['user_agent']))
                                    <div class="truncate">UA: {{ $message->metadata['user_agent'] }}</div>
                                @endif
                            </div>
                        </details>
                        @endif
                    </div>
                </div>

            {{-- 🎁 Cartão: voucher de cortesia gerado no chat --}}
            @elseif($msgType === 'voucher_offer')
                @php
                    $voucherCode = $message->metadata['voucher_code'] ?? '---';
                    $voucherHours = $message->metadata['voucher_hours'] ?? 12;
                    $activateUrl = $message->metadata['activate_url'] ?? url('/voucher/ativar');
                    // Corrigir URLs localhost que foram salvas em dev
                    if (str_contains($activateUrl, 'localhost')) {
                        $activateUrl = rtrim(config('app.url', 'https://www.tocantinstransportewifi.com.br'), '/') . '/voucher/ativar';
                    }
                    $voucherExpires = isset($message->metadata['expires_at']) ? \Carbon\Carbon::parse($message->metadata['expires_at']) : null;
                @endphp
                <div class="flex justify-center chat-message-enter">
                    <div class="max-w-md w-full bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-3">
                            <div class="w-9 h-9 rounded-lg bg-gradient-to-br from-emerald-500 to-teal-600 flex items-center justify-center text-white text-xl shadow">🎁</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider">Voucher de cortesia</p>
                                <p class="text-sm font-bold text-emerald-800">{{ $voucherHours }} horas de internet</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        <div class="bg-white rounded-xl p-3 text-center shadow-inner border border-emerald-100">
                            <p class="text-[10px] text-gray-500 uppercase tracking-wider font-bold">Código</p>
                            <p class="text-xl lg:text-2xl font-mono font-bold text-emerald-700 tracking-widest my-1 select-all">{{ $voucherCode }}</p>
                            <button onclick="navigator.clipboard.writeText('{{ $voucherCode }}'); this.textContent='✓ copiado';"
                                    class="text-[11px] px-2 py-1 bg-emerald-50 border border-emerald-200 rounded hover:bg-emerald-100 font-semibold text-emerald-700">
                                copiar código
                            </button>
                        </div>
                        <div class="mt-3 text-[11px] text-gray-600 space-y-1">
                            <p>👤 <strong>{{ $conversation->visitor_name ?: 'Cliente' }}</strong>{{ $conversation->visitor_phone ? ' · ' . $conversation->visitor_phone : '' }}</p>
                            <p>🔗 Ativar em: <a href="{{ $activateUrl }}" target="_blank" class="text-emerald-700 underline font-mono">{{ $activateUrl }}</a></p>
                            @if($voucherExpires)
                                <p>⏰ Expira em {{ $voucherExpires->format('d/m H:i') }} ({{ $voucherExpires->diffForHumans() }})</p>
                            @endif
                        </div>
                        <p class="text-[10px] text-gray-400 mt-2 italic">Enviado por {{ $senderLabel }}</p>
                    </div>
                </div>

            {{-- 📎 Ana pediu comprovante PIX --}}
            @elseif($msgType === 'receipt_request')
                <div class="flex justify-center chat-message-enter">
                    <div class="max-w-md w-full bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-9 h-9 rounded-lg bg-amber-500 flex items-center justify-center text-white text-xl shadow">📎</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-amber-800 uppercase tracking-wider">Pedido de comprovante</p>
                                <p class="text-sm font-bold text-amber-900">Ana pediu o comprovante do PIX</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        <p class="text-sm text-gray-700">{{ $message->message }}</p>
                        <p class="text-[10px] text-gray-400 mt-2 italic">Aguardando o cliente enviar a foto/print</p>
                    </div>
                </div>

            {{-- 📎 Cliente enviou comprovante --}}
            @elseif($msgType === 'receipt_upload')
                @php
                    $receiptUrl = $message->metadata['receipt_url'] ?? null;
                    $receiptMime = $message->metadata['receipt_mime'] ?? '';
                    $isPdf = str_contains($receiptMime, 'pdf') || str_ends_with(strtolower($receiptUrl ?? ''), '.pdf');
                @endphp
                <div class="flex justify-start chat-message-enter">
                    <div class="max-w-md w-full bg-white border-2 border-emerald-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-9 h-9 rounded-lg bg-emerald-600 flex items-center justify-center text-white text-xl shadow">🧾</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-emerald-700 uppercase tracking-wider">Comprovante PIX</p>
                                <p class="text-sm font-bold text-emerald-900">{{ $conversation->visitor_name }} enviou</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        @if($receiptUrl)
                            @if($isPdf)
                                <a href="{{ $receiptUrl }}" target="_blank" class="block text-center text-sm font-semibold text-emerald-700 underline py-4 bg-emerald-50 rounded-xl">📄 Abrir PDF do comprovante</a>
                            @else
                                <a href="{{ $receiptUrl }}" target="_blank" class="block">
                                    <img src="{{ $receiptUrl }}" alt="Comprovante" class="max-h-72 mx-auto rounded-xl border border-emerald-200 object-contain bg-gray-50">
                                </a>
                            @endif
                        @else
                            <p class="text-sm text-gray-500">Arquivo indisponível</p>
                        @endif
                        <p class="text-[10px] text-amber-700 mt-2 font-semibold">⚠ Conferir e liberar manualmente se o PIX for válido</p>
                    </div>
                </div>

            {{-- Ana mandou o botão de recuperação por telefone --}}
            @elseif($msgType === 'recovery_request')
                <div class="flex justify-center chat-message-enter">
                    <div class="max-w-md w-full bg-gradient-to-br from-amber-50 to-orange-50 border-2 border-amber-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-9 h-9 rounded-lg bg-amber-500 flex items-center justify-center text-white text-xl shadow">🔄</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-amber-800 uppercase tracking-wider">Recuperação de acesso</p>
                                <p class="text-sm font-bold text-amber-900">Ana enviou o botão "Recuperar acesso"</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        <p class="text-sm text-gray-700 whitespace-pre-line">{{ $message->message }}</p>
                        <p class="text-[10px] text-gray-400 mt-2 italic">O passageiro informa o telefone e o aparelho atual é religado — inclusive com MAC novo</p>
                    </div>
                </div>

            {{-- Ana pediu MAC --}}
            @elseif($msgType === 'mac_request')
                <div class="flex justify-center chat-message-enter">
                    <div class="max-w-md w-full bg-gradient-to-br from-sky-50 to-indigo-50 border-2 border-sky-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-9 h-9 rounded-lg bg-sky-600 flex items-center justify-center text-white text-xl shadow">📶</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-sky-800 uppercase tracking-wider">Pedido de MAC</p>
                                <p class="text-sm font-bold text-sky-900">Ana pediu o MAC da rede do celular</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        <p class="text-sm text-gray-700 whitespace-pre-line">{{ $message->message }}</p>
                        <p class="text-[10px] text-gray-400 mt-2 italic">Aguardando foto da tela ou o código AA:BB:CC:DD:EE:FF</p>
                    </div>
                </div>

            {{-- Cliente enviou foto do MAC --}}
            @elseif($msgType === 'mac_upload')
                @php
                    $macUrl = $message->metadata['mac_url'] ?? null;
                @endphp
                <div class="flex justify-start chat-message-enter">
                    <div class="max-w-md w-full bg-white border-2 border-sky-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-9 h-9 rounded-lg bg-sky-600 flex items-center justify-center text-white text-xl shadow">📶</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-sky-700 uppercase tracking-wider">Foto do MAC</p>
                                <p class="text-sm font-bold text-sky-900">{{ $conversation->visitor_name }} enviou</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        @if($macUrl)
                            <a href="{{ $macUrl }}" target="_blank" class="block">
                                <img src="{{ $macUrl }}" alt="MAC da rede" class="max-h-72 mx-auto rounded-xl border border-sky-200 object-contain bg-gray-50">
                            </a>
                        @else
                            <p class="text-sm text-gray-500">Arquivo indisponível</p>
                        @endif
                        @if(!empty($message->metadata['mac_address']))
                            <p class="text-[10px] font-bold text-sky-700 uppercase tracking-wider mt-3 text-center">MAC lido pela IA</p>
                            <p class="text-lg font-mono font-bold text-sky-800 tracking-wider text-center select-all">{{ $message->metadata['mac_address'] }}</p>
                        @endif
                        <p class="text-[10px] text-amber-700 mt-2 font-semibold">⚠ Conferir o MAC na foto e liberar esse aparelho no MikroTik</p>
                    </div>
                </div>

            {{-- Cliente escreveu o MAC --}}
            @elseif($msgType === 'mac_text')
                @php
                    $typedMac = $message->metadata['mac_address'] ?? $message->message;
                @endphp
                <div class="flex justify-start chat-message-enter">
                    <div class="max-w-md w-full bg-white border-2 border-sky-300 rounded-2xl p-4 shadow-sm">
                        <div class="flex items-center gap-2 mb-2">
                            <div class="w-9 h-9 rounded-lg bg-sky-600 flex items-center justify-center text-white text-xl shadow">📶</div>
                            <div class="flex-1">
                                <p class="text-[10px] font-bold text-sky-700 uppercase tracking-wider">MAC digitado</p>
                                <p class="text-sm font-bold text-sky-900">{{ $conversation->visitor_name }} enviou</p>
                            </div>
                            <p class="text-[10px] text-gray-500">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                        <p class="text-xl font-mono font-bold text-sky-800 tracking-wider text-center py-2 select-all">{{ $typedMac }}</p>
                        <p class="text-[10px] text-amber-700 mt-1 font-semibold">⚠ Liberar este MAC no MikroTik</p>
                    </div>
                </div>

            {{-- Mensagem texto padrão --}}
            @else
                <div class="flex {{ $message->sender_type === 'admin' ? 'justify-end' : 'justify-start' }} chat-message-enter">
                    @if($message->sender_type === 'visitor')
                    <div class="flex items-end space-x-2 lg:space-x-3 max-w-[85%] lg:max-w-[70%]">
                        <div class="w-8 h-8 lg:w-10 lg:h-10 rounded-full bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center text-gray-600 font-bold text-xs lg:text-sm flex-shrink-0">
                            {{ strtoupper(substr($conversation->visitor_name, 0, 1)) }}
                        </div>
                        <div class="bg-white rounded-2xl rounded-bl-md px-3 lg:px-4 py-2 lg:py-3 shadow-md border border-gray-100">
                            <p class="text-sm text-gray-800 leading-relaxed break-words whitespace-pre-line">{{ $message->message }}</p>
                            <p class="text-xs text-gray-400 mt-1 lg:mt-2">{{ $message->created_at->format('H:i') }}</p>
                        </div>
                    </div>
                    @else
                    <div class="max-w-[85%] lg:max-w-[70%]">
                        @if($isAI)
                        <div class="bg-gradient-to-r from-indigo-500 to-purple-600 text-white rounded-2xl rounded-br-md px-3 lg:px-4 py-2 lg:py-3 shadow-md relative">
                            <div class="absolute -top-2 -left-2 w-7 h-7 rounded-full bg-white shadow-md flex items-center justify-center text-sm" title="Resposta automática da IA">🤖</div>
                            <p class="text-sm leading-relaxed break-words whitespace-pre-line">{{ preg_replace(['/\*\*([^*]+)\*\*/u', '/\*([^*\n]+)\*/u', '/\*/'], ['$1', '$1', ''], $message->message) }}</p>
                            <div class="flex items-center justify-end space-x-2 mt-1 lg:mt-2 flex-wrap">
                                <span class="text-xs text-indigo-100 hidden sm:inline font-semibold">Assistente IA</span>
                                @if(!empty($message->metadata['escalated']))
                                    <span class="text-[10px] bg-amber-400 text-amber-900 px-1.5 py-0.5 rounded font-bold">ESCALADO</span>
                                @endif
                                <span class="text-indigo-200 hidden sm:inline">•</span>
                                <span class="text-xs text-indigo-100">{{ $message->created_at->format('H:i') }}</span>
                            </div>
                        </div>
                        @else
                        <div class="bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-2xl rounded-br-md px-3 lg:px-4 py-2 lg:py-3 shadow-md">
                            <p class="text-sm leading-relaxed break-words whitespace-pre-line">{{ $message->message }}</p>
                            <div class="flex items-center justify-end space-x-2 mt-1 lg:mt-2 flex-wrap">
                                @if($message->admin)
                                <span class="text-xs text-emerald-100 hidden sm:inline">{{ $message->admin->name }}</span>
                                <span class="text-emerald-200 hidden sm:inline">•</span>
                                @endif
                                <span class="text-xs text-emerald-100">{{ $message->created_at->format('H:i') }}</span>
                                <svg class="w-4 h-4 text-emerald-200" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                </svg>
                            </div>
                        </div>
                        @endif
                    </div>
                    @endif
                </div>
            @endif
            @endforeach
        </div>

        <!-- Input de Mensagem -->
        @if($conversation->status !== 'closed')
        <div class="px-3 lg:px-4 pt-2 border-t bg-white">
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="request-probe-btn"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 rounded-lg px-3 py-1.5 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                    </svg>
                    <span>📡 Solicitar teste de conexão</span>
                </button>
                <button type="button" id="create-voucher-btn"
                        class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 rounded-lg px-3 py-1.5 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                    </svg>
                    <span>🎁 Criar voucher (12h)</span>
                </button>
            </div>
            <p class="text-[10px] text-gray-400 mt-1">Teste: link pro usuário rodar diagnóstico. Voucher: cortesia de 12h com o nome e telefone dele.</p>
        </div>
        <div class="p-3 lg:p-4 border-t bg-white">
            <form id="reply-form" class="flex items-center space-x-2 lg:space-x-3">
                @csrf
                <div class="flex-1 relative">
                    <input type="text" 
                           id="message-input"
                           name="message" 
                           placeholder="Digite sua mensagem..." 
                           class="w-full bg-gray-100 border-2 border-transparent rounded-xl px-3 lg:px-5 py-3 lg:py-4 text-sm lg:text-base focus:outline-none focus:border-emerald-500 focus:bg-white transition-all"
                           autocomplete="off"
                           required>
                </div>
                <button type="submit" 
                        class="w-11 h-11 lg:w-14 lg:h-14 bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-xl font-medium hover:shadow-lg hover:shadow-emerald-500/30 transform hover:scale-105 transition-all flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 lg:w-6 lg:h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                    </svg>
                </button>
            </form>
        </div>
        @else
        <div class="p-3 lg:p-4 bg-gray-100 border-t">
            <div class="flex items-center justify-center space-x-2 text-gray-500 text-sm">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <span>Esta conversa foi encerrada</span>
            </div>
        </div>
        @endif
    </div>

    <!-- Sidebar com Informações -->
    <div id="sidebar-info" class="hidden lg:block w-full lg:w-80 flex-shrink-0">
        <div class="bg-white rounded-2xl shadow-xl overflow-hidden h-full">
            <!-- Header Info com botão fechar no mobile -->
            <div class="p-4 lg:p-6 border-b bg-gradient-to-br from-gray-50 to-white">
                <div class="flex lg:hidden justify-end mb-2">
                    <button type="button" id="close-sidebar-btn" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
                <div class="text-center">
                    <div class="w-16 h-16 lg:w-20 lg:h-20 rounded-2xl bg-gradient-to-br from-emerald-400 to-teal-500 flex items-center justify-center text-white text-2xl lg:text-3xl font-bold mx-auto shadow-lg">
                        {{ strtoupper(substr($conversation->visitor_name, 0, 1)) }}
                    </div>
                    <h4 class="font-bold text-gray-800 mt-3 lg:mt-4 text-base lg:text-lg">{{ $conversation->visitor_name }}</h4>
                    <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium mt-2
                        {{ $conversation->status === 'active' ? 'bg-green-100 text-green-700' : '' }}
                        {{ $conversation->status === 'pending' ? 'bg-yellow-100 text-yellow-700' : '' }}
                        {{ $conversation->status === 'closed' ? 'bg-gray-100 text-gray-600' : '' }}">
                        <span class="w-1.5 h-1.5 rounded-full mr-1.5 {{ $conversation->status === 'active' ? 'bg-green-500' : ($conversation->status === 'pending' ? 'bg-yellow-500' : 'bg-gray-400') }}"></span>
                        {{ $conversation->status === 'active' ? 'Conversa Ativa' : ($conversation->status === 'pending' ? 'Aguardando Resposta' : 'Conversa Encerrada') }}
                    </span>
                </div>
            </div>

            <!-- Detalhes -->
            <div class="p-4 lg:p-6 space-y-4 lg:space-y-5">
                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">Telefone</label>
                    <div class="flex items-center space-x-2 mt-1">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                        </svg>
                        <a href="tel:{{ $conversation->visitor_phone }}" class="text-gray-800 hover:text-emerald-600 font-medium">
                            {{ $conversation->visitor_phone }}
                        </a>
                    </div>
                </div>

                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">E-mail</label>
                    <div class="flex items-center space-x-2 mt-1">
                        <svg class="w-5 h-5 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <a href="mailto:{{ $conversation->visitor_email }}" class="text-gray-800 hover:text-emerald-600 font-medium text-sm truncate">
                            {{ $conversation->visitor_email }}
                        </a>
                    </div>
                </div>

                @if($conversation->visitor_ip)
                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">Endereço IP</label>
                    <div class="flex items-center space-x-2 mt-1">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                        </svg>
                        <span class="text-gray-600 text-sm font-mono">{{ $conversation->visitor_ip }}</span>
                    </div>
                </div>
                @endif

                @if($conversation->visitor_mac)
                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">MAC Address</label>
                    <div class="flex items-center space-x-2 mt-1">
                        <svg class="w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        <span class="text-gray-600 text-sm font-mono select-all">{{ strtoupper($conversation->visitor_mac) }}</span>
                    </div>
                </div>
                @endif

                {{-- 🔎 Cadastro vinculado (casa por MAC ou telefone) --}}
                @php $linkedUser = $conversation->linked_user; @endphp
                @if($linkedUser)
                    @php
                        $isActive = in_array($linkedUser->status, ['connected', 'active', 'temp_bypass'])
                            && $linkedUser->expires_at && $linkedUser->expires_at->isFuture();
                        if ($isActive) {
                            $boxClass = 'bg-emerald-50 border-emerald-200';
                            $badgeClass = 'bg-emerald-100 text-emerald-700';
                            $dotClass = 'bg-emerald-500 animate-pulse';
                            $expColor = 'text-emerald-600';
                            $statusLabel = 'ACESSO ATIVO';
                        } elseif ($linkedUser->status === 'expired') {
                            $boxClass = 'bg-red-50 border-red-200';
                            $badgeClass = 'bg-red-100 text-red-700';
                            $dotClass = 'bg-red-500';
                            $expColor = 'text-red-600';
                            $statusLabel = 'EXPIRADO';
                        } else {
                            $boxClass = 'bg-gray-50 border-gray-200';
                            $badgeClass = 'bg-gray-100 text-gray-700';
                            $dotClass = 'bg-gray-500';
                            $expColor = 'text-gray-600';
                            $statusLabel = strtoupper($linkedUser->status ?? 'SEM STATUS');
                        }
                    @endphp
                    <div class="pt-4 border-t">
                        <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">Cadastro Vinculado</label>
                        <div class="mt-2 p-3 rounded-xl border {{ $boxClass }}">
                            <div class="flex items-center justify-between mb-2">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold {{ $badgeClass }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $dotClass }} mr-1.5"></span>
                                    {{ $statusLabel }}
                                </span>
                                <a href="{{ route('admin.users.edit', $linkedUser->id) }}" class="text-[10px] text-emerald-600 font-semibold hover:underline">
                                    Ver cadastro →
                                </a>
                            </div>
                            <div class="text-xs text-gray-700 space-y-1">
                                @if($linkedUser->expires_at)
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Expira:</span>
                                        <span class="font-medium">
                                            {{ $linkedUser->expires_at->format('d/m H:i') }}
                                            @if($linkedUser->expires_at->isFuture())
                                                <span class="{{ $expColor }}">({{ $linkedUser->expires_at->diffForHumans() }})</span>
                                            @else
                                                <span class="text-red-600">(já passou)</span>
                                            @endif
                                        </span>
                                    </div>
                                @endif
                                @if($linkedUser->mac_address)
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">MAC cadastro:</span>
                                        <span class="font-mono text-[11px]">{{ strtoupper($linkedUser->mac_address) }}</span>
                                    </div>
                                    @if($conversation->visitor_mac && strtoupper(trim($conversation->visitor_mac)) !== strtoupper(trim($linkedUser->mac_address)))
                                        <div class="px-2 py-1 rounded bg-amber-50 border border-amber-200 text-[11px] text-amber-800 mt-1">
                                            ⚠️ MAC do chat difere do cadastro — possível randomização do dispositivo.
                                        </div>
                                    @endif
                                @endif
                                @if($conversation->linked_bus_name)
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Ônibus:</span>
                                        <span class="font-medium">🚌 {{ $conversation->linked_bus_name }}</span>
                                    </div>
                                @endif
                                @if($linkedUser->connected_at)
                                    <div class="flex justify-between">
                                        <span class="text-gray-500">Último conectado:</span>
                                        <span>{{ $linkedUser->connected_at->diffForHumans() }}</span>
                                    </div>
                                @endif
                            </div>
                            @if(auth()->user()?->role === 'admin')
                            <form action="{{ route('admin.chat.delete-user', $conversation->id) }}" method="POST" class="mt-3"
                                  onsubmit="return confirm('Excluir o cadastro deste usuário em /admin/users?\nEle poderá se cadastrar de novo no portal.');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="w-full text-[11px] font-bold bg-red-50 text-red-700 border border-red-200 hover:bg-red-100 px-3 py-2 rounded-lg transition-colors">
                                    Excluir cadastro do usuário
                                </button>
                            </form>
                            @endif
                        </div>
                    </div>
                @elseif($conversation->visitor_mac || $conversation->visitor_phone)
                    <div class="pt-4 border-t">
                        <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">Cadastro Vinculado</label>
                        <div class="mt-2 p-3 rounded-xl bg-gray-50 border border-gray-200 text-xs text-gray-600">
                            Nenhum cadastro encontrado com esse MAC ou telefone.
                        </div>
                    </div>
                @endif

                <div class="pt-4 border-t">
                    <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">Iniciado em</label>
                    <p class="text-gray-800 font-medium mt-1">{{ $conversation->created_at->format('d/m/Y \à\s H:i') }}</p>
                </div>

                <div>
                    <label class="text-xs font-medium text-gray-400 uppercase tracking-wider">Total de Mensagens</label>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ $conversation->messages->count() }}</p>
                </div>
            </div>

            <!-- Ações Rápidas -->
            <div class="p-4 lg:p-6 border-t bg-gray-50">
                <a href="https://wa.me/55{{ preg_replace('/\D/', '', $conversation->visitor_phone) }}" 
                   target="_blank"
                   class="w-full bg-green-500 hover:bg-green-600 text-white py-3 rounded-xl font-medium transition-colors flex items-center justify-center space-x-2 text-sm lg:text-base">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                    <span>Abrir WhatsApp</span>
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Overlay para mobile -->
<div id="sidebar-overlay" class="fixed inset-0 bg-black/50 z-40 hidden lg:hidden"></div>


<style>
.chat-message-enter {
    animation: messageEnter 0.3s ease-out;
}

@keyframes messageEnter {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

#messages-container::-webkit-scrollbar {
    width: 6px;
}

#messages-container::-webkit-scrollbar-track {
    background: transparent;
}

#messages-container::-webkit-scrollbar-thumb {
    background: #d1d5db;
    border-radius: 10px;
}

#messages-container::-webkit-scrollbar-thumb:hover {
    background: #9ca3af;
}

/* Mobile sidebar styles */
@media (max-width: 1023px) {
    #sidebar-info.sidebar-open {
        display: block;
        position: fixed;
        top: 0;
        right: 0;
        bottom: 0;
        width: 85%;
        max-width: 320px;
        z-index: 50;
        overflow-y: auto;
        animation: slideIn 0.3s ease-out;
    }
    
    #sidebar-overlay.overlay-visible {
        display: block;
    }
}

@keyframes slideIn {
    from {
        transform: translateX(100%);
    }
    to {
        transform: translateX(0);
    }
}
</style>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const messagesContainer = document.getElementById('messages-container');
    const form = document.getElementById('reply-form');
    const input = document.getElementById('message-input');
    
    // Mobile sidebar toggle
    const sidebar = document.getElementById('sidebar-info');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleBtn = document.getElementById('toggle-sidebar-btn');
    const closeBtn = document.getElementById('close-sidebar-btn');
    
    function openSidebar() {
        sidebar.classList.add('sidebar-open');
        overlay.classList.add('overlay-visible');
        document.body.style.overflow = 'hidden';
    }
    
    function closeSidebar() {
        sidebar.classList.remove('sidebar-open');
        overlay.classList.remove('overlay-visible');
        document.body.style.overflow = '';
    }
    
    if (toggleBtn) {
        toggleBtn.addEventListener('click', openSidebar);
    }
    
    if (closeBtn) {
        closeBtn.addEventListener('click', closeSidebar);
    }
    
    if (overlay) {
        overlay.addEventListener('click', closeSidebar);
    }

    // Scroll para o final
    function scrollToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }
    scrollToBottom();

    // Enviar mensagem
    if (form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            
            const message = input.value.trim();
            if (!message) return;

            // Desabilitar input temporariamente
            input.disabled = true;

            fetch('{{ route("admin.chat.reply", $conversation->id) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({ message: message })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Adicionar mensagem na tela
                    const msgDiv = document.createElement('div');
                    msgDiv.className = 'flex justify-end chat-message-enter';
                    msgDiv.innerHTML = `
                        <div class="max-w-[70%]">
                            <div class="bg-gradient-to-r from-emerald-500 to-teal-600 text-white rounded-2xl rounded-br-md px-4 py-3 shadow-md">
                                <p class="text-sm leading-relaxed">${message}</p>
                                <div class="flex items-center justify-end space-x-2 mt-2">
                                    <span class="text-xs text-emerald-100">{{ Auth::user()->name }}</span>
                                    <span class="text-emerald-200">•</span>
                                    <span class="text-xs text-emerald-100">${new Date().toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'})}</span>
                                    <svg class="w-4 h-4 text-emerald-200" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                                    </svg>
                                </div>
                            </div>
                        </div>
                    `;
                    messagesContainer.appendChild(msgDiv);
                    input.value = '';
                    scrollToBottom();
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                alert('Erro ao enviar mensagem');
            })
            .finally(() => {
                input.disabled = false;
                input.focus();
            });
        });
    }

    // 📡 Solicitar teste de conexão
    const probeBtn = document.getElementById('request-probe-btn');
    if (probeBtn) {
        probeBtn.addEventListener('click', function() {
            if (!confirm('Enviar um teste de conexão para o usuário?\n\nO link aparecerá no chat. Ele terá 30 minutos para clicar e rodar os testes.')) return;
            probeBtn.disabled = true;
            const originalHtml = probeBtn.innerHTML;
            probeBtn.innerHTML = '<span class="animate-pulse">Enviando...</span>';

            fetch('{{ route("admin.chat.probe.create", $conversation->id) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    // reload para mostrar o cartão completo renderizado pelo Blade
                    window.location.reload();
                } else {
                    alert('Erro: ' + (data.error || 'não foi possível criar o teste'));
                    probeBtn.disabled = false;
                    probeBtn.innerHTML = originalHtml;
                }
            })
            .catch(e => {
                console.error(e);
                alert('Erro ao solicitar teste');
                probeBtn.disabled = false;
                probeBtn.innerHTML = originalHtml;
            });
        });
    }

    // 🎁 Criar voucher de cortesia (12h)
    const voucherBtn = document.getElementById('create-voucher-btn');
    if (voucherBtn) {
        voucherBtn.addEventListener('click', function() {
            const visitorName = @json($conversation->visitor_name ?: 'Cliente');
            const visitorPhone = @json($conversation->visitor_phone ?: '');
            const confirmMsg = `Criar voucher de cortesia de 12h para:\n\n👤 ${visitorName}\n📞 ${visitorPhone}\n\nO código será enviado no chat automaticamente.`;
            if (!confirm(confirmMsg)) return;
            voucherBtn.disabled = true;
            const originalHtml = voucherBtn.innerHTML;
            voucherBtn.innerHTML = '<span class="animate-pulse">Gerando...</span>';

            fetch('{{ route("admin.chat.voucher.create", $conversation->id) }}', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': '{{ csrf_token() }}',
                    'Accept': 'application/json'
                }
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert('Erro: ' + (data.error || 'não foi possível criar o voucher'));
                    voucherBtn.disabled = false;
                    voucherBtn.innerHTML = originalHtml;
                }
            })
            .catch(e => {
                console.error(e);
                alert('Erro ao criar voucher');
                voucherBtn.disabled = false;
                voucherBtn.innerHTML = originalHtml;
            });
        });
    }

    // Polling para novas mensagens
    let lastMessageId = {{ $conversation->messages->last()->id ?? 0 }};
    
    setInterval(function() {
        fetch('{{ route("admin.chat.messages", $conversation->id) }}')
            .then(response => response.json())
            .then(data => {
                if (data.success && data.messages.length > 0) {
                    const newMessages = data.messages.filter(m => m.id > lastMessageId && m.sender_type === 'visitor');

                    // Se veio resultado de probe, recarrega pra pegar o cartão renderizado
                    if (newMessages.some(m => ['probe_result', 'receipt_upload', 'mac_upload', 'mac_text'].includes(m.type || 'text'))) {
                        window.location.reload();
                        return;
                    }

                    newMessages.forEach(msg => {
                        if ((msg.type || 'text') !== 'text') return;
                        const msgDiv = document.createElement('div');
                        msgDiv.className = 'flex justify-start chat-message-enter';
                        msgDiv.innerHTML = `
                            <div class="flex items-end space-x-3 max-w-[70%]">
                                <div class="w-10 h-10 rounded-full bg-gradient-to-br from-gray-200 to-gray-300 flex items-center justify-center text-gray-600 font-bold text-sm flex-shrink-0">
                                    {{ strtoupper(substr($conversation->visitor_name, 0, 1)) }}
                                </div>
                                <div class="bg-white rounded-2xl rounded-bl-md px-4 py-3 shadow-md border border-gray-100">
                                    <p class="text-sm text-gray-800 leading-relaxed">${msg.message}</p>
                                    <p class="text-xs text-gray-400 mt-2">${new Date(msg.created_at).toLocaleTimeString('pt-BR', {hour: '2-digit', minute:'2-digit'})}</p>
                                </div>
                            </div>
                        `;
                        messagesContainer.appendChild(msgDiv);
                        lastMessageId = msg.id;
                    });
                    
                    if (newMessages.length > 0) {
                        scrollToBottom();
                        // Tocar som de notificação (opcional)
                        // new Audio('/sounds/notification.mp3').play();
                    }
                }
            });
    }, 4000);

    // Focus no input
    if (input) {
        input.focus();
    }
});
</script>
@endpush
@endsection
