@extends('layouts.admin')

@section('title', 'WhatsApp')

@section('breadcrumb')
    <span class="mx-2">/</span>
    <span class="text-tocantins-green font-medium">WhatsApp</span>
@endsection

@section('page-title', 'Módulo WhatsApp')

@section('content')
    @if(session('success'))
        <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm font-medium text-red-800">{{ session('error') }}</div>
    @endif
<div class="space-y-6">
    
    <!-- Status da Conexão -->
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="p-6">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="text-2xl">📱</span>
                    Status da Conexão WhatsApp
                </h2>
                <div id="connection-badge" class="px-4 py-2 rounded-full text-sm font-medium transition-all duration-300">
                    <span class="flex items-center gap-2">
                        <span id="status-dot" class="w-3 h-3 rounded-full animate-pulse"></span>
                        <span id="status-text">Verificando...</span>
                    </span>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- QR Code / Status -->
                <div id="qr-container" class="bg-gradient-to-br from-gray-50 to-gray-100 rounded-xl p-6 text-center min-h-[350px] flex flex-col items-center justify-center">
                    <!-- Conteúdo dinâmico via JS -->
                    <div id="qr-content">
                        <div class="animate-spin w-12 h-12 border-4 border-tocantins-green border-t-transparent rounded-full mx-auto mb-4"></div>
                        <p class="text-gray-600">Verificando conexão...</p>
                    </div>
                </div>

                <!-- Informações e Ações -->
                <div class="space-y-4">
                    <!-- Telefone Conectado -->
                    <div id="phone-info" class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-4 border border-green-200 hidden">
                        <div class="flex items-center gap-3">
                            <div class="w-12 h-12 bg-green-500 rounded-full flex items-center justify-center">
                                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                                </svg>
                            </div>
                            <div>
                                <p class="text-sm text-green-600 font-medium">Telefone Conectado</p>
                                <p id="connected-phone" class="text-lg font-bold text-green-800">-</p>
                            </div>
                        </div>
                    </div>

                    <!-- Estatísticas Rápidas -->
                    <div class="grid grid-cols-2 gap-3">
                        <div class="bg-blue-50 rounded-xl p-4 text-center">
                            <p class="text-2xl font-bold text-blue-600">{{ $stats['sent_messages'] }}</p>
                            <p class="text-xs text-blue-500">Enviadas</p>
                        </div>
                        <div class="bg-red-50 rounded-xl p-4 text-center">
                            <p class="text-2xl font-bold text-red-600">{{ $stats['failed_messages'] }}</p>
                            <p class="text-xs text-red-500">Falhas</p>
                        </div>
                        <div class="bg-yellow-50 rounded-xl p-4 text-center">
                            <p class="text-2xl font-bold text-yellow-600">{{ $stats['pending_messages'] }}</p>
                            <p class="text-xs text-yellow-500">Pendentes</p>
                        </div>
                        <div class="bg-green-50 rounded-xl p-4 text-center">
                            <p class="text-2xl font-bold text-green-600">{{ $stats['today_messages'] }}</p>
                            <p class="text-xs text-green-500">Hoje</p>
                        </div>
                    </div>

                    <!-- Botões de Ação -->
                    <div class="space-y-3">
                        <button id="btn-connect" onclick="connectWhatsApp()" class="w-full bg-gradient-to-r from-green-500 to-emerald-600 text-white py-3 px-4 rounded-xl font-medium hover:from-green-600 hover:to-emerald-700 transition-all duration-300 flex items-center justify-center gap-2 shadow-lg">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                            </svg>
                            Conectar WhatsApp
                        </button>
                        
                        <button id="btn-disconnect" onclick="disconnectWhatsApp()" class="hidden w-full bg-gradient-to-r from-red-500 to-red-600 text-white py-3 px-4 rounded-xl font-medium hover:from-red-600 hover:to-red-700 transition-all duration-300 flex items-center justify-center gap-2">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                            </svg>
                            Desconectar
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Resultado dos lembretes -->
    @if($insights['ok'])
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="text-2xl">📈</span>
                    Resultado dos lembretes
                    <span class="text-sm font-normal text-gray-500">últimos 30 dias</span>
                </h2>
            </div>

            @if(!$insights['feature_enabled'])
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <strong>Lembrete automático desligado</strong> nas configurações do sistema. Nenhum PIX abandonado está sendo lembrado.
                </div>
            @elseif(!$settings['auto_send_enabled'])
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <strong>Envio automático desativado.</strong> Ative em <a class="underline" href="{{ route('admin.whatsapp.settings') }}">Configurações</a> para lembrar quem gerou PIX e não pagou.
                </div>
            @elseif($insights['run_is_stale'])
                <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <strong>O lembrete automático não está rodando.</strong>
                    @if($insights['last_run_at'])
                        Última execução {{ $insights['last_run_at']->diffForHumans() }} ({{ $insights['last_run_at']->format('d/m H:i') }}).
                    @else
                        Ainda não há registro de execução.
                    @endif
                    Ele deveria rodar a cada 5 minutos. Confira no servidor se o agendador do Laravel está ativo
                    (<code class="bg-red-100 px-1 rounded">* * * * * php artisan schedule:run</code> no cron).
                </div>
            @else
                <div class="mb-4 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                    Lembrete automático rodando — última execução {{ $insights['last_run_at']->diffForHumans() }}:
                    {{ $insights['last_run_result'] }}.
                </div>
            @endif

            @if($insights['last_reminder_at'] && $insights['last_reminder_at']->lt(now()->subDays(2)) && $insights['waiting_count'] > 0)
                <div class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    O último lembrete saiu em <strong>{{ $insights['last_reminder_at']->format('d/m/Y H:i') }}</strong>
                    ({{ $insights['last_reminder_at']->diffForHumans() }}), mas há PIX abandonados todos os dias.
                </div>
            @endif

            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-xs text-gray-500 mb-1">Lembretes enviados</p>
                    <p class="text-2xl font-bold text-gray-800">{{ $insights['sent'] }}</p>
                    <p class="text-xs text-gray-500">{{ $insights['delivered'] }} entregues</p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-xs text-gray-500 mb-1">Pagaram em até 24h</p>
                    <p class="text-2xl font-bold text-green-600">
                        {{ $insights['converted'] }}
                        @if($insights['sent'] > 0)
                            <span class="text-sm font-medium text-gray-500">({{ number_format($insights['converted'] / $insights['sent'] * 100, 0) }}%)</span>
                        @endif
                    </p>
                    <p class="text-xs text-gray-500">R$ {{ number_format($insights['revenue'], 2, ',', '.') }} depois do lembrete</p>
                    <p class="text-xs text-gray-500">
                        @if($insights['pix_code_enabled'])
                            {{ $insights['paid_with_code'] }} pelo código PIX do WhatsApp
                        @else
                            código PIX no lembrete desligado
                        @endif
                    </p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-xs text-gray-500 mb-1">Aguardando lembrete agora</p>
                    <p class="text-2xl font-bold text-yellow-600">{{ $insights['waiting_count'] }}</p>
                    <p class="text-xs text-gray-500">R$ {{ number_format($insights['waiting_amount'], 2, ',', '.') }} em PIX de hoje, com autorização</p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-xs text-gray-500 mb-1">Proteção contra bloqueio</p>
                    <p class="text-2xl font-bold {{ $insights['sent_today'] >= $insights['cap'] ? 'text-red-600' : 'text-gray-800' }}">{{ $insights['sent_today'] }}<span class="text-sm font-medium text-gray-500"> / {{ $insights['cap'] }} hoje</span></p>
                    <p class="text-xs {{ $insights['opt_outs'] > 0 && $insights['sent'] > 0 && $insights['opt_outs'] / $insights['sent'] > 0.05 ? 'text-red-600 font-medium' : 'text-gray-500' }}">
                        {{ $insights['opt_outs'] }} pediram PARAR · silêncio {{ $insights['quiet_label'] }}{{ $insights['is_quiet'] ? ' (agora)' : '' }}
                    </p>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-3">
                "Pagaram em até 24h" conta quem concluiu um pagamento depois de receber o lembrete; parte deles pagaria de qualquer forma.
                Muitos pedidos de PARAR em relação aos envios (acima de 5%) é sinal de risco: reduza o limite diário.
            </p>
        </div>
    </div>
    @endif

    <!-- Pagamentos Pendentes -->
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="text-2xl">⏰</span>
                    Pagamentos Pendentes ({{ count($pendingPayments) }})
                    <span class="text-sm font-normal text-gray-500">há mais de {{ $settings['pending_minutes'] }} minutos</span>
                </h2>
                <div class="flex items-center gap-2">
                    @if(auth()->user()?->role === 'admin' && count($pendingPayments) > 0)
                    <form method="POST" action="{{ route('admin.whatsapp.pending-payments.bulk-destroy') }}"
                          onsubmit="return confirm('Excluir todos os {{ count($pendingPayments) }} pagamentos pendentes desta lista?');">
                        @csrf
                        @method('DELETE')
                        @foreach($pendingPayments as $pending)
                            <input type="hidden" name="payment_ids[]" value="{{ $pending->id }}">
                        @endforeach
                        <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 py-2 px-3 rounded-lg font-medium text-sm">
                            Excluir pendentes
                        </button>
                    </form>
                    @endif
                    <button id="btn-send-all" onclick="sendToAllPending()" class="bg-gradient-to-r from-tocantins-green to-green-600 text-white py-2 px-4 rounded-lg font-medium hover:from-green-600 hover:to-green-700 transition-all duration-300 flex items-center gap-2 text-sm disabled:opacity-50 disabled:cursor-not-allowed" {{ !$settings['is_connected'] ? 'disabled' : '' }}>
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
                        </svg>
                        Enviar para autorizados
                    </button>
                </div>
            </div>

            @if(count($pendingPayments) > 0)
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Usuário</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Telefone</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Valor</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Criado em</th>
                            <th class="px-4 py-3 text-left font-medium text-gray-600">Tempo</th>
                            <th class="px-4 py-3 text-center font-medium text-gray-600">Ação</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($pendingPayments as $payment)
                        <tr class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800">{{ $payment->user->name ?? 'Sem nome' }}</div>
                                <div class="text-xs text-gray-500">ID: {{ $payment->user_id }}</div>
                            </td>
                            <td class="px-4 py-3 font-mono text-gray-600">{{ $payment->user->phone }}</td>
                            <td class="px-4 py-3 font-medium text-green-600">R$ {{ number_format($payment->amount, 2, ',', '.') }}</td>
                            <td class="px-4 py-3 text-gray-600">{{ $payment->created_at->format('d/m H:i') }}</td>
                            <td class="px-4 py-3">
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-medium">
                                    {{ $payment->created_at->diffForHumans() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="inline-flex items-center gap-1.5">
                                    @if($payment->user->hasWhatsappPaymentOptIn())
                                        <button onclick="sendSingleMessage('{{ $payment->user->phone }}', {{ $payment->user_id }}, {{ $payment->id }})" class="bg-green-100 hover:bg-green-200 text-green-700 px-3 py-1 rounded-lg text-xs font-medium transition-colors disabled:opacity-50" {{ !$settings['is_connected'] ? 'disabled' : '' }}>
                                            Enviar
                                        </button>
                                    @else
                                        <span class="text-xs font-medium text-gray-400" title="O passageiro ainda não autorizou atualizações de pagamento pelo WhatsApp">Sem autorização</span>
                                    @endif
                                    @if(auth()->user()?->role === 'admin')
                                    <form method="POST" action="{{ route('admin.whatsapp.pending-payments.destroy', $payment) }}"
                                          onsubmit="return confirm('Excluir o pagamento pendente #{{ $payment->id }}?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="bg-red-50 hover:bg-red-100 text-red-700 px-3 py-1 rounded-lg text-xs font-medium">
                                            Excluir
                                        </button>
                                    </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @else
            <div class="text-center py-8 text-gray-500">
                <span class="text-4xl mb-2 block">✅</span>
                <p>Nenhum pagamento pendente no momento</p>
            </div>
            @endif
        </div>
    </div>

    <!-- Mensagens Recentes -->
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="text-2xl">💬</span>
                    Mensagens Recentes
                </h2>
                <a href="{{ route('admin.whatsapp.messages') }}" class="text-tocantins-green hover:text-green-700 text-sm font-medium flex items-center gap-1">
                    Ver todas
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>

            @if(count($recentMessages) > 0)
            <div class="space-y-3">
                @foreach($recentMessages as $message)
                <div class="flex items-center justify-between p-3 bg-gray-50 rounded-xl hover:bg-gray-100 transition-colors">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full flex items-center justify-center
                            @if($message->status == 'sent' || $message->status == 'delivered' || $message->status == 'read') bg-green-100 text-green-600
                            @elseif($message->status == 'failed') bg-red-100 text-red-600
                            @else bg-yellow-100 text-yellow-600 @endif">
                            @if($message->status == 'sent' || $message->status == 'delivered' || $message->status == 'read')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                </svg>
                            @elseif($message->status == 'failed')
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            @else
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            @endif
                        </div>
                        <div>
                            <p class="font-medium text-gray-800">{{ $message->phone }}</p>
                            <p class="text-xs text-gray-500">{{ Str::limit($message->message, 50) }}</p>
                        </div>
                    </div>
                    <div class="text-right">
                        <span class="px-2 py-1 rounded-full text-xs font-medium
                            @if($message->status == 'sent') bg-green-100 text-green-700
                            @elseif($message->status == 'delivered') bg-blue-100 text-blue-700
                            @elseif($message->status == 'read') bg-purple-100 text-purple-700
                            @elseif($message->status == 'failed') bg-red-100 text-red-700
                            @else bg-yellow-100 text-yellow-700 @endif">
                            {{ ucfirst($message->status) }}
                        </span>
                        <p class="text-xs text-gray-400 mt-1">{{ $message->created_at->diffForHumans() }}</p>
                    </div>
                </div>
                @endforeach
            </div>
            @else
            <div class="text-center py-8 text-gray-500">
                <span class="text-4xl mb-2 block">📭</span>
                <p>Nenhuma mensagem enviada ainda</p>
            </div>
            @endif
        </div>
    </div>

    <!-- Configurações Rápidas -->
    <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden">
        <div class="p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2">
                    <span class="text-2xl">⚙️</span>
                    Configurações
                </h2>
                <a href="{{ route('admin.whatsapp.settings') }}" class="text-tocantins-green hover:text-green-700 text-sm font-medium">
                    Editar configurações
                </a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-sm text-gray-500 mb-1">Envio Automático</p>
                    <p class="font-medium {{ $settings['auto_send_enabled'] ? 'text-green-600' : 'text-red-600' }}">
                        {{ $settings['auto_send_enabled'] ? '✅ Ativado' : '❌ Desativado' }}
                    </p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-sm text-gray-500 mb-1">Tempo de Pendência</p>
                    <p class="font-medium text-gray-800">{{ $settings['pending_minutes'] }} minutos</p>
                </div>
                <div class="bg-gray-50 rounded-xl p-4">
                    <p class="text-sm text-gray-500 mb-1">Total de Mensagens</p>
                    <p class="font-medium text-gray-800">{{ $stats['total_messages'] }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal de Envio Manual -->
<div id="send-modal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl max-w-md w-full p-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4">Enviar Mensagem</h3>
        <form id="send-form">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
                    <input type="text" id="modal-phone" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-tocantins-green focus:border-transparent" placeholder="63999999999">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Mensagem</label>
                    <textarea id="modal-message" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-tocantins-green focus:border-transparent resize-none">{{ $settings['message_template'] }}</textarea>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button type="button" onclick="closeSendModal()" class="flex-1 bg-gray-200 text-gray-700 py-2 px-4 rounded-lg font-medium hover:bg-gray-300 transition-colors">
                    Cancelar
                </button>
                <button type="submit" class="flex-1 bg-gradient-to-r from-green-500 to-emerald-600 text-white py-2 px-4 rounded-lg font-medium hover:from-green-600 hover:to-emerald-700 transition-all">
                    Enviar
                </button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const BAILEYS_URL = '{{ env("BAILEYS_SERVER_URL", "http://localhost:3001") }}';
let statusCheckInterval = null;

// Verificar status ao carregar
document.addEventListener('DOMContentLoaded', function() {
    checkStatus();
    // Verificar status a cada 5 segundos
    statusCheckInterval = setInterval(checkStatus, 5000);
});

// Verificar status da conexão
async function checkStatus() {
    try {
        const response = await fetch('{{ route("admin.whatsapp.status") }}');
        const data = await response.json();
        updateUI(data);
    } catch (error) {
        console.error('Erro ao verificar status:', error);
        updateUI({ status: 'disconnected', error: 'Erro de conexão' });
    }
}

// Atualizar interface
function updateUI(data) {
    const badge = document.getElementById('connection-badge');
    const dot = document.getElementById('status-dot');
    const text = document.getElementById('status-text');
    const qrContent = document.getElementById('qr-content');
    const phoneInfo = document.getElementById('phone-info');
    const connectedPhone = document.getElementById('connected-phone');
    const btnConnect = document.getElementById('btn-connect');
    const btnDisconnect = document.getElementById('btn-disconnect');
    const btnSendAll = document.getElementById('btn-send-all');

    switch(data.status) {
        case 'connected':
            badge.className = 'px-4 py-2 rounded-full text-sm font-medium bg-green-100 text-green-700';
            dot.className = 'w-3 h-3 rounded-full bg-green-500';
            text.textContent = 'Conectado';
            
            qrContent.innerHTML = `
                <div class="text-green-500 mb-4">
                    <svg class="w-20 h-20 mx-auto" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                    </svg>
                </div>
                <p class="text-xl font-bold text-green-600 mb-2">WhatsApp Conectado!</p>
                <p class="text-gray-500">Pronto para enviar mensagens</p>
            `;
            
            phoneInfo.classList.remove('hidden');
            connectedPhone.textContent = data.phone ? formatPhone(data.phone) : '-';
            
            btnConnect.classList.add('hidden');
            btnDisconnect.classList.remove('hidden');
            btnSendAll.disabled = false;
            
            // Habilitar botões de envio individual
            document.querySelectorAll('button[onclick^="sendSingleMessage"]').forEach(btn => btn.disabled = false);
            break;

        case 'waiting_scan':
            badge.className = 'px-4 py-2 rounded-full text-sm font-medium bg-yellow-100 text-yellow-700';
            dot.className = 'w-3 h-3 rounded-full bg-yellow-500 animate-pulse';
            text.textContent = 'Aguardando Scan';
            
            if (data.qrcode) {
                qrContent.innerHTML = `
                    <p class="text-gray-600 mb-4 font-medium">Escaneie o QR Code com seu WhatsApp</p>
                    <img src="${data.qrcode}" alt="QR Code" class="mx-auto rounded-lg shadow-lg" style="max-width: 250px;">
                    <p class="text-xs text-gray-400 mt-4">Abra o WhatsApp > Menu > Aparelhos conectados > Conectar</p>
                `;
            }
            
            phoneInfo.classList.add('hidden');
            btnConnect.classList.add('hidden');
            btnDisconnect.classList.remove('hidden');
            break;

        case 'connecting':
        case 'reconnecting':
            badge.className = 'px-4 py-2 rounded-full text-sm font-medium bg-blue-100 text-blue-700';
            dot.className = 'w-3 h-3 rounded-full bg-blue-500 animate-pulse';
            text.textContent = data.status === 'reconnecting' ? 'Reconectando...' : 'Conectando...';
            
            qrContent.innerHTML = `
                <div class="animate-spin w-12 h-12 border-4 border-blue-500 border-t-transparent rounded-full mx-auto mb-4"></div>
                <p class="text-gray-600">${data.status === 'reconnecting' ? 'Reconectando...' : 'Conectando ao WhatsApp...'}</p>
            `;
            
            phoneInfo.classList.add('hidden');
            break;

        default: // disconnected
            badge.className = 'px-4 py-2 rounded-full text-sm font-medium bg-red-100 text-red-700';
            dot.className = 'w-3 h-3 rounded-full bg-red-500';
            text.textContent = 'Desconectado';
            
            qrContent.innerHTML = `
                <div class="text-gray-400 mb-4">
                    <svg class="w-20 h-20 mx-auto" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
                <p class="text-xl font-bold text-gray-600 mb-2">WhatsApp Desconectado</p>
                <p class="text-gray-500 mb-4">Clique em "Conectar WhatsApp" para começar</p>
                ${data.error ? `<p class="text-xs text-red-500">${data.error}</p>` : ''}
            `;
            
            phoneInfo.classList.add('hidden');
            btnConnect.classList.remove('hidden');
            btnDisconnect.classList.add('hidden');
            btnSendAll.disabled = true;
            
            // Desabilitar botões de envio individual
            document.querySelectorAll('button[onclick^="sendSingleMessage"]').forEach(btn => btn.disabled = true);
    }
}

// Conectar WhatsApp
async function connectWhatsApp() {
    const btn = document.getElementById('btn-connect');
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-spin">⏳</span> Conectando...';

    try {
        const response = await fetch('{{ route("admin.whatsapp.qrcode") }}');
        const data = await response.json();
        
        if (data.qrcode) {
            updateUI({ status: 'waiting_scan', qrcode: data.qrcode });
        } else if (data.status === 'connected') {
            updateUI(data);
        } else if (data.error) {
            alert('Erro: ' + data.error);
            updateUI({ status: 'disconnected', error: data.error });
        }
    } catch (error) {
        alert('Erro ao conectar. Verifique se o servidor WhatsApp está rodando.');
        updateUI({ status: 'disconnected', error: 'Servidor não disponível' });
    }

    btn.disabled = false;
    btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg> Conectar WhatsApp';
}

// Desconectar WhatsApp
async function disconnectWhatsApp() {
    if (!confirm('Tem certeza que deseja desconectar o WhatsApp?')) return;

    try {
        const response = await fetch('{{ route("admin.whatsapp.disconnect") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        });
        
        const data = await response.json();
        if (data.success) {
            updateUI({ status: 'disconnected' });
        }
    } catch (error) {
        alert('Erro ao desconectar');
    }
}

// Enviar para todos os pendentes
async function sendToAllPending() {
    if (!confirm('Enviar lembrete somente aos passageiros autorizados com pagamento pendente?')) return;

    const btn = document.getElementById('btn-send-all');
    btn.disabled = true;
    btn.innerHTML = '<span class="animate-spin">⏳</span> Enviando...';

    try {
        const response = await fetch('{{ route("admin.whatsapp.send-pending") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json'
            }
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(`Enviadas: ${data.sent}\nFalhas: ${data.failed}\nIgnoradas: ${data.skipped}`);
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        alert('Erro ao enviar mensagens');
    }

    btn.disabled = false;
    btn.innerHTML = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg> Enviar para autorizados';
}

// Enviar mensagem individual
async function sendSingleMessage(phone, userId, paymentId) {
    // Com paymentId é lembrete de PIX: o servidor monta o mesmo texto do envio
    // automático e aplica horário de silêncio, limite diário e trava de 24h.
    const isReminder = !!paymentId;
    if (!confirm(isReminder ? `Enviar o lembrete de PIX para ${phone}?` : `Enviar mensagem para ${phone}?`)) return;

    try {
        const response = await fetch('{{ route("admin.whatsapp.send") }}', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': '{{ csrf_token() }}',
                'Content-Type': 'application/json',
                'Accept': 'application/json'
            },
            body: JSON.stringify(isReminder
                ? { phone: phone, user_id: userId, payment_id: paymentId }
                : { phone: phone, message: document.getElementById('modal-message')?.value || '', user_id: userId })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Mensagem enviada com sucesso!');
            location.reload();
        } else {
            alert('Erro: ' + (data.error || 'Erro desconhecido'));
        }
    } catch (error) {
        alert('Erro ao enviar mensagem');
    }
}

// Formatar telefone
function formatPhone(phone) {
    if (!phone) return '-';
    phone = phone.replace(/\D/g, '');
    if (phone.length === 13 && phone.startsWith('55')) {
        return `+${phone.slice(0,2)} (${phone.slice(2,4)}) ${phone.slice(4,9)}-${phone.slice(9)}`;
    }
    return phone;
}

// Modal de envio
function openSendModal() {
    document.getElementById('send-modal').classList.remove('hidden');
}

function closeSendModal() {
    document.getElementById('send-modal').classList.add('hidden');
}

document.getElementById('send-form')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    const phone = document.getElementById('modal-phone').value;
    const message = document.getElementById('modal-message').value;
    
    if (!phone || !message) {
        alert('Preencha todos os campos');
        return;
    }
    
    await sendSingleMessage(phone, null, null);
    closeSendModal();
});
</script>
@endpush
