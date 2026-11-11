@extends('layouts.admin')

@section('title', 'Configurações WhatsApp')

@section('breadcrumb')
    <span class="mx-2">/</span>
    <a href="{{ route('admin.whatsapp.index') }}" class="hover:text-tocantins-green transition-colors">WhatsApp</a>
    <span class="mx-2">/</span>
    <span class="text-tocantins-green font-medium">Configurações</span>
@endsection

@section('page-title', 'Configurações do WhatsApp')

@section('content')
<div class="max-w-3xl mx-auto space-y-6">
    
    @if(session('success'))
    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-xl flex items-center gap-2">
        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
        </svg>
        {{ session('success') }}
    </div>
    @endif

    <form method="POST" action="{{ route('admin.whatsapp.settings.update') }}">
        @csrf
        @method('PUT')

        <!-- Configurações Gerais -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden mb-6">
            <div class="p-6">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2 mb-6">
                    <span class="text-2xl">⚙️</span>
                    Configurações Gerais
                </h2>

                <div class="space-y-6">
                    <!-- Envio Automático -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                        <div>
                            <p class="font-medium text-gray-800">Envio Automático</p>
                            <p class="text-sm text-gray-500">Enviar lembrete somente para passageiros que autorizaram atualizações de pagamento</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" name="auto_send_enabled" value="1" class="sr-only peer" {{ $settings['auto_send_enabled'] ? 'checked' : '' }}>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                        </label>
                    </div>

                    <!-- Código PIX no lembrete -->
                    <div class="flex items-center justify-between p-4 bg-gray-50 rounded-xl">
                        <div class="pr-4">
                            <p class="font-medium text-gray-800">Enviar código PIX novo no lembrete</p>
                            <p class="text-sm text-gray-500">
                                O passageiro recebe o aviso e, em seguida, um código copia-e-cola de uma cobrança nova, para pagar direto no banco.
                                Desligado, o lembrete só manda o link do portal. São duas mensagens por passageiro: se os pedidos de PARAR subirem, desligue.
                            </p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer flex-shrink-0">
                            <input type="checkbox" name="reminder_pix_code_enabled" value="1" class="sr-only peer" {{ $settings['reminder_pix_code_enabled'] ? 'checked' : '' }}>
                            <div class="w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-600"></div>
                        </label>
                    </div>

                    <!-- Tempo de Pendência -->
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Tempo de Pendência (minutos)
                        </label>
                        <p class="text-xs text-gray-500 mb-2">
                            Tempo mínimo que um pagamento deve estar pendente antes de enviar a mensagem
                        </p>
                        <input type="number" name="pending_minutes" value="{{ max(15, $settings['pending_minutes']) }}" min="15" max="1440" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-tocantins-green focus:border-transparent" required>
                        @error('pending_minutes')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
            </div>
        </div>

        <!-- Proteção contra bloqueio -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden mb-6">
            <div class="p-6">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2 mb-1">
                    <span class="text-2xl">🛡️</span>
                    Proteção contra bloqueio
                </h2>
                <p class="text-xs text-gray-500 mb-5">
                    Vale para o envio automático e para o botão "Enviar para pendentes". Hoje já foram enviados
                    <strong>{{ $settings['reminders_today'] }}</strong> de <strong>{{ $settings['daily_reminder_cap'] }}</strong> lembretes.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Não enviar a partir de</label>
                        <select name="quiet_start_hour" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-tocantins-green focus:border-transparent">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" @selected((int) old('quiet_start_hour', $settings['quiet_start_hour']) === $h)>{{ sprintf('%02d:00', $h) }}</option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Voltar a enviar às</label>
                        <select name="quiet_end_hour" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-tocantins-green focus:border-transparent">
                            @for ($h = 0; $h < 24; $h++)
                                <option value="{{ $h }}" @selected((int) old('quiet_end_hour', $settings['quiet_end_hour']) === $h)>{{ sprintf('%02d:00', $h) }}</option>
                            @endfor
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">Máximo de lembretes por dia</label>
                        <input type="number" name="daily_reminder_cap" value="{{ old('daily_reminder_cap', $settings['daily_reminder_cap']) }}" min="1" max="200" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-tocantins-green focus:border-transparent">
                        @error('daily_reminder_cap')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>
                </div>
                <p class="text-xs text-gray-500 mt-3">
                    Recomendado: silêncio das 22:00 às 07:00 e até 30 lembretes por dia. Mensagem automática de madrugada é o que mais gera denúncia.
                    Os lembretes também alternam entre 4 textos e esperam alguns segundos (aleatórios) entre um envio e outro.
                </p>
            </div>
        </div>

        <!-- Template da Mensagem -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-100 overflow-hidden mb-6">
            <div class="p-6">
                <h2 class="text-lg font-bold text-gray-800 flex items-center gap-2 mb-6">
                    <span class="text-2xl">💬</span>
                    Template da Mensagem
                </h2>

                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-2">
                            Mensagem Padrão
                        </label>
                        <p class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 mb-2">
                            Os lembretes de PIX (automáticos e os botões "Enviar" da tela do WhatsApp) <strong>não usam este modelo</strong>:
                            eles alternam entre 4 textos fixos, com o valor do PIX, o código para pagar (ou o link do portal) e a opção PARAR.
                        </p>
                        <p class="text-xs text-gray-500 mb-2">
                            Use as variáveis:<code class="bg-gray-100 px-1 rounded">{nome}</code>, <code class="bg-gray-100 px-1 rounded">{valor}</code>, <code class="bg-gray-100 px-1 rounded">{telefone}</code>
                        </p>
                        <textarea name="message_template" rows="8" class="w-full px-4 py-3 border border-gray-300 rounded-xl focus:ring-2 focus:ring-tocantins-green focus:border-transparent font-mono text-sm" required>{{ $settings['message_template'] }}</textarea>
                        @error('message_template')
                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Preview -->
                    <div class="bg-gradient-to-br from-green-50 to-emerald-50 rounded-xl p-4 border border-green-200">
                        <p class="text-sm font-medium text-green-700 mb-2">📱 Preview da Mensagem:</p>
                        <div id="message-preview" class="bg-white rounded-lg p-3 shadow-sm text-sm whitespace-pre-wrap">
                            {{ $settings['message_template'] }}
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Botões -->
        <div class="flex gap-4">
            <a href="{{ route('admin.whatsapp.index') }}" class="flex-1 bg-gray-200 text-gray-700 py-3 px-4 rounded-xl font-medium hover:bg-gray-300 transition-colors text-center">
                Cancelar
            </a>
            <button type="submit" class="flex-1 bg-gradient-to-r from-tocantins-green to-green-600 text-white py-3 px-4 rounded-xl font-medium hover:from-green-600 hover:to-green-700 transition-all shadow-lg">
                Salvar Configurações
            </button>
        </div>
    </form>

    <!-- Informações -->
    <div class="bg-blue-50 rounded-2xl p-6 border border-blue-200">
        <h3 class="font-bold text-blue-800 mb-3 flex items-center gap-2">
            <span class="text-xl">ℹ️</span>
            Informações Importantes
        </h3>
        <ul class="text-sm text-blue-700 space-y-2">
            <li>• O lembrete só é enviado após pelo menos 15 minutos e somente uma vez por pagamento</li>
            <li>• Apenas passageiros que marcaram a autorização no portal podem receber o envio automático</li>
            <li>• Quem responder PARAR é removido imediatamente das mensagens automáticas</li>
            <li>• Para disparos automáticos fora de uma conversa de 24 horas, use a API oficial do WhatsApp com modelo aprovado</li>
        </ul>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Atualizar preview em tempo real
document.querySelector('textarea[name="message_template"]').addEventListener('input', function() {
    document.getElementById('message-preview').textContent = this.value;
});
</script>
@endpush
