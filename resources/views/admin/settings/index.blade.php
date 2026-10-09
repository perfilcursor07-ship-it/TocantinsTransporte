@extends('layouts.admin')

@section('title', 'Configurações do Sistema')

@section('content')
<div class="container mx-auto px-4 py-8">
    <div class="max-w-8xl mx-auto">
        
        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2">⚙️ Configurações do Sistema</h1>
            <p class="text-gray-600">Gerencie as configurações gerais do WiFi Tocantins</p>
        </div>

        @if(session('success'))
            <div class="bg-green-50 border-l-4 border-green-500 p-4 mb-6 rounded-lg">
                <div class="flex items-center">
                    <svg class="w-6 h-6 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-green-800 font-medium">{{ session('success') }}</p>
                </div>
            </div>
        @endif

        @if($errors->any())
            <div class="bg-red-50 border-l-4 border-red-500 p-4 mb-6 rounded-lg">
                <div class="flex items-start">
                    <svg class="w-6 h-6 text-red-500 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <div>
                        <p class="text-red-800 font-bold mb-1">Nada foi salvo. Corrija os itens abaixo:</p>
                        <ul class="list-disc list-inside text-sm text-red-700 space-y-0.5">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <form action="{{ route('admin.settings.update') }}" method="POST" class="space-y-6">
            @csrf
            @method('PUT')

            <!-- Card: Preços -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Preços e Valores
                    </h2>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-4">Configure os preços dos dois planos disponíveis para os passageiros.</p>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <!-- Plano Curto -->
                        <div class="p-4 border-2 rounded-xl transition-all {{ $settings['plan_short_enabled'] || $settings['plan_short_schedule_enabled'] ? 'border-gray-200' : 'border-gray-200 opacity-60' }}" id="plan-short-card">
                            <div class="flex items-center justify-between mb-3">
                                <label for="wifi_price" class="block text-sm font-bold text-gray-700">
                                    ⏱️ Plano por Hora
                                </label>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="plan_short_enabled" value="0">
                                    <input type="checkbox" name="plan_short_enabled" value="1" class="sr-only peer" id="plan_short_toggle"
                                        {{ old('plan_short_enabled', $settings['plan_short_enabled']) ? 'checked' : '' }}
                                        onchange="document.getElementById('plan-short-card').classList.toggle('opacity-60', !this.checked && !document.getElementById('plan_short_schedule_toggle').checked)">
                                    <div class="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mb-3">Acesso rápido por tempo limitado</p>
                            <div class="flex gap-3">
                                <div class="flex-1 relative">
                                    <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 font-bold text-sm">R$</span>
                                    <input 
                                        type="number" name="wifi_price" id="wifi_price" step="0.01" min="0.01" max="999.99"
                                        value="{{ old('wifi_price', $settings['wifi_price']) }}"
                                        class="w-full pl-10 pr-3 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-bold"
                                        required>
                                </div>
                                <div class="w-24 relative">
                                    <input 
                                        type="number" name="session_duration_short" id="session_duration_short" min="1" max="168"
                                        value="{{ old('session_duration_short', $settings['session_duration_short']) }}"
                                        class="w-full px-3 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-bold text-center"
                                        required>
                                    <span class="absolute -bottom-5 left-0 right-0 text-center text-[10px] text-gray-400">hora(s)</span>
                                </div>
                            </div>
                            @error('wifi_price')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            @error('session_duration_short')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror

                            <!-- Agendamento Automático -->
                            <div class="mt-6 pt-4 border-t border-gray-200">
                                <div class="flex items-center justify-between mb-3">
                                    <div>
                                        <label for="plan_short_schedule_toggle" class="block text-sm font-bold text-gray-700 flex items-center gap-1.5">
                                            <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                            Habilitar automaticamente em horário
                                        </label>
                                        <p class="text-[11px] text-gray-500 mt-0.5">Quando ativo, o plano por hora liga e desliga sozinho todos os dias</p>
                                    </div>
                                    <label class="relative inline-flex items-center cursor-pointer">
                                        <input type="hidden" name="plan_short_schedule_enabled" value="0">
                                        <input type="checkbox" name="plan_short_schedule_enabled" value="1" class="sr-only peer" id="plan_short_schedule_toggle"
                                            {{ old('plan_short_schedule_enabled', $settings['plan_short_schedule_enabled']) ? 'checked' : '' }}
                                            onchange="document.getElementById('plan-short-schedule-fields').classList.toggle('hidden', !this.checked); document.getElementById('plan-short-card').classList.toggle('opacity-60', !this.checked && !document.getElementById('plan_short_toggle').checked)">
                                        <div class="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-500"></div>
                                    </label>
                                </div>

                                <div id="plan-short-schedule-fields" class="{{ $settings['plan_short_schedule_enabled'] ? '' : 'hidden' }}">
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-[11px] text-gray-500 font-medium mb-1">Horário de início</label>
                                            <input type="time" name="plan_short_schedule_start"
                                                value="{{ old('plan_short_schedule_start', $settings['plan_short_schedule_start']) }}"
                                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm font-bold">
                                        </div>
                                        <div>
                                            <label class="block text-[11px] text-gray-500 font-medium mb-1">Horário de fim</label>
                                            <input type="time" name="plan_short_schedule_end"
                                                value="{{ old('plan_short_schedule_end', $settings['plan_short_schedule_end']) }}"
                                                class="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-sm font-bold">
                                        </div>
                                    </div>

                                    <!-- Status atual -->
                                    @if($settings['plan_short_schedule_enabled'])
                                    <div class="mt-3 p-3 rounded-lg {{ $settings['plan_short_currently_active'] ? 'bg-green-50 border border-green-200' : 'bg-gray-50 border border-gray-200' }}">
                                        <p class="text-xs flex items-center gap-1.5 {{ $settings['plan_short_currently_active'] ? 'text-green-700' : 'text-gray-500' }}">
                                            <span class="w-2 h-2 rounded-full {{ $settings['plan_short_currently_active'] ? 'bg-green-500 animate-pulse' : 'bg-gray-400' }}"></span>
                                            <strong>Status atual:</strong>
                                            {{ $settings['plan_short_currently_active'] ? 'Plano ATIVO agora (dentro do horário)' : 'Plano INATIVO agora (fora do horário)' }}
                                        </p>
                                    </div>
                                    @endif

                                    <p class="mt-2 text-[10px] text-gray-400">
                                        Suporta intervalos que cruzam meia-noite (ex: 21:00 → 06:00 = das 21h até 6h da manhã do dia seguinte)
                                    </p>
                                    @error('plan_short_schedule_start')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                    @error('plan_short_schedule_end')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                </div>
                            </div>
                        </div>

                        <!-- Plano Viagem Completa -->
                        <div class="p-4 border-2 border-green-200 bg-green-50 rounded-xl transition-all {{ $settings['plan_full_enabled'] ? '' : 'opacity-60' }}" id="plan-full-card">
                            <div class="flex items-center justify-between mb-3">
                                <label for="wifi_price_full" class="block text-sm font-bold text-gray-700">
                                    🚌 Viagem Completa
                                </label>
                                <label class="relative inline-flex items-center cursor-pointer">
                                    <input type="hidden" name="plan_full_enabled" value="0">
                                    <input type="checkbox" name="plan_full_enabled" value="1" class="sr-only peer" id="plan_full_toggle"
                                        {{ old('plan_full_enabled', $settings['plan_full_enabled']) ? 'checked' : '' }}
                                        onchange="document.getElementById('plan-full-card').classList.toggle('opacity-60', !this.checked)">
                                    <div class="w-9 h-5 bg-gray-300 peer-focus:ring-2 peer-focus:ring-green-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-green-500"></div>
                                </label>
                            </div>
                            <p class="text-xs text-gray-500 mb-3">WiFi até o destino final</p>
                            <div class="flex gap-3">
                                <div class="flex-1 relative">
                                    <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 font-bold text-sm">R$</span>
                                    <input 
                                        type="number" name="wifi_price_full" id="wifi_price_full" step="0.01" min="0.01" max="999.99"
                                        value="{{ old('wifi_price_full', $settings['wifi_price_full']) }}"
                                        class="w-full pl-10 pr-3 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-bold"
                                        required>
                                </div>
                                <div class="w-24 relative">
                                    <input 
                                        type="number" name="session_duration" id="session_duration" min="1" max="168"
                                        value="{{ old('session_duration', $settings['session_duration']) }}"
                                        class="w-full px-3 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-green-500 focus:border-green-500 text-lg font-bold text-center"
                                        required>
                                    <span class="absolute -bottom-5 left-0 right-0 text-center text-[10px] text-gray-400">hora(s)</span>
                                </div>
                            </div>
                            @error('wifi_price_full')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            @error('session_duration')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>
                </div>
            </div>

            <section class="bg-white border border-gray-200 rounded-lg p-6">
                <div class="flex items-center justify-between gap-4 mb-4">
                    <h2 class="text-lg font-bold text-gray-800">Plano por intervalo</h2>
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700" for="plan_interval_enabled">
                        <input type="hidden" name="plan_interval_enabled" value="0">
                        <input id="plan_interval_enabled" type="checkbox" name="plan_interval_enabled" value="1"
                            class="h-5 w-5 rounded border-gray-300 accent-green-600"
                            @checked(old('plan_interval_enabled', $settings['plan_interval_enabled']))>
                        Ativo
                    </label>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="plan_interval_max_days" class="block text-sm font-semibold mb-2">Máximo de dias por intervalo</label>
                        <input id="plan_interval_max_days" name="plan_interval_max_days" type="number" min="1" max="90" required
                            value="{{ old('plan_interval_max_days', $settings['plan_interval_max_days']) }}"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        @error('plan_interval_max_days')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="plan_interval_price_24h" class="block text-sm font-semibold mb-2">Preço da diária de 24h (R$)</label>
                        <input id="plan_interval_price_24h" name="plan_interval_price_24h" type="number" min="0.05" max="999.99" step="0.01" required
                            value="{{ old('plan_interval_price_24h', $settings['plan_interval_price_24h']) }}"
                            class="w-full border border-gray-300 rounded-lg px-3 py-2">
                        @error('plan_interval_price_24h')<p class="text-sm text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                </div>
                <p class="text-sm text-gray-600 mt-3">Cada dia do plano vale 24 horas seguidas. O passageiro escolhe o primeiro e o último dia: de 28 a 30 = 2 dias = 48 horas. O acesso é liberado de uma vez na confirmação do PIX e fica ativo direto até o fim do período, sem ativar todo dia. Compras para outra data começam quando o passageiro se conecta no Wi-Fi do ônibus. Depois que expira, ele compra de novo.</p>
            </section>

            <!-- Card: Desconto por Vídeo -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-purple-500 to-purple-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                        </svg>
                        Desconto por Vídeo
                    </h2>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-4">O passageiro pode assistir um vídeo promocional (42 segundos) antes do pagamento para ganhar desconto.</p>
                    
                    <div class="flex items-center justify-between mb-5 p-4 bg-purple-50 rounded-xl border border-purple-200">
                        <div>
                            <label for="video_discount_toggle" class="block text-sm font-bold text-gray-700">
                                🎬 Habilitar desconto por vídeo
                            </label>
                            <p class="text-xs text-gray-500 mt-1">Quando ativado, o usuário pode assistir o vídeo completo para ganhar desconto</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="hidden" name="video_discount_enabled" value="0">
                            <input type="checkbox" name="video_discount_enabled" value="1" class="sr-only peer" id="video_discount_toggle"
                                {{ old('video_discount_enabled', $settings['video_discount_enabled']) ? 'checked' : '' }}>
                            <div class="w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-purple-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-purple-500"></div>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="video_discount_amount" class="block text-sm font-bold text-gray-700 mb-2">
                                💰 Valor do desconto
                            </label>
                            <div class="relative">
                                <span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-500 font-bold text-sm">R$</span>
                                <input 
                                    type="number" name="video_discount_amount" id="video_discount_amount" step="0.01" min="0.01" max="99.99"
                                    value="{{ old('video_discount_amount', $settings['video_discount_amount']) }}"
                                    class="w-full pl-10 pr-3 py-3 border-2 border-gray-300 rounded-xl focus:ring-2 focus:ring-purple-500 focus:border-purple-500 text-lg font-bold"
                                    required>
                            </div>
                            <p class="text-xs text-gray-500 mt-2">Valor que será descontado do preço do plano ao assistir o vídeo completo</p>
                            @error('video_discount_amount')
                                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">
                                📊 Exemplo de desconto
                            </label>
                            <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                                <div class="flex items-center justify-between text-sm mb-2">
                                    <span class="text-gray-600">Viagem completa:</span>
                                    <span class="font-bold">R$ {{ number_format((float)$settings['wifi_price_full'], 2, ',', '.') }}</span>
                                </div>
                                <div class="flex items-center justify-between text-sm mb-2 text-purple-600">
                                    <span>Desconto vídeo:</span>
                                    <span class="font-bold">- R$ {{ number_format((float)$settings['video_discount_amount'], 2, ',', '.') }}</span>
                                </div>
                                <div class="border-t border-gray-300 pt-2 flex items-center justify-between text-sm">
                                    <span class="font-bold text-green-700">Preço final:</span>
                                    <span class="font-extrabold text-green-700 text-lg">R$ {{ number_format((float)$settings['wifi_price_full'] - (float)$settings['video_discount_amount'], 2, ',', '.') }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Card: Lembrete de Pagamento Pendente -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-amber-500 to-orange-500 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Recuperação de Vendas — Lembrete WhatsApp
                    </h2>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600 mb-4">Para clientes que geraram QR Code PIX mas nao pagaram, o sistema envia automaticamente uma mensagem WhatsApp depois de 15 minutos com o link do portal e libera acesso temporario apenas para finalizar o pagamento.</p>
                    
                    <div class="flex items-center justify-between mb-4 p-4 bg-amber-50 rounded-xl border border-amber-200">
                        <div>
                            <label for="unpaid_reminder_toggle" class="block text-sm font-bold text-gray-700">
                                💸 Habilitar lembrete de pagamento pendente
                            </label>
                            <p class="text-xs text-gray-500 mt-1">Ativa o envio automático após 15 minutos do PIX gerado e não pago</p>
                        </div>
                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="hidden" name="unpaid_reminder_enabled" value="0">
                            <input type="checkbox" name="unpaid_reminder_enabled" value="1" class="sr-only peer" id="unpaid_reminder_toggle"
                                {{ old('unpaid_reminder_enabled', $settings['unpaid_reminder_enabled']) ? 'checked' : '' }}>
                            <div class="w-11 h-6 bg-gray-300 peer-focus:ring-2 peer-focus:ring-amber-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-amber-500"></div>
                        </label>
                    </div>

                    <div class="bg-gray-50 rounded-xl p-4 border border-gray-200">
                        <p class="text-xs font-bold text-gray-700 mb-2">Como funciona:</p>
                        <ul class="text-xs text-gray-600 space-y-1.5 list-disc list-inside">
                            <li>Cliente gera o PIX no portal mas não paga</li>
                            <li>Após <strong>15 minutos</strong>, o sistema verifica e identifica esse pagamento pendente</li>
                            <li>Libera <strong>acesso temporario</strong> pra ele conseguir abrir o WhatsApp, o portal e o app do banco</li>
                            <li>Envia mensagem com link para finalizar o pagamento</li>
                            <li>Mensagem é enviada <strong>1 vez por dia</strong> por telefone (não floda o cliente)</li>
                            <li>Apos pagar, libera o tempo do plano escolhido</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Card: Gateway PIX -->
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
                    <h2 class="text-xl font-bold text-white flex items-center">
                        <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
                        </svg>
                        Gateway de Pagamento
                    </h2>
                </div>
                <div class="p-6">
                    <div class="mb-6">
                        <label for="pix_gateway" class="block text-sm font-bold text-gray-700 mb-3">
                            🔌 Gateway PIX Ativo
                        </label>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-500 {{ $settings['pix_gateway'] == 'woovi' ? 'border-blue-500 bg-blue-50' : 'border-gray-300' }}">
                                <input 
                                    type="radio" 
                                    name="pix_gateway" 
                                    value="woovi" 
                                    {{ $settings['pix_gateway'] == 'woovi' ? 'checked' : '' }}
                                    class="mr-3"
                                >
                                <div>
                                    <p class="font-bold text-gray-800">Woovi</p>
                                    <p class="text-xs text-gray-600">OpenPix</p>
                                </div>
                            </label>

                            <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-500 {{ $settings['pix_gateway'] == 'pagbank' ? 'border-blue-500 bg-blue-50' : 'border-gray-300' }}">
                                <input 
                                    type="radio" 
                                    name="pix_gateway" 
                                    value="pagbank" 
                                    {{ $settings['pix_gateway'] == 'pagbank' ? 'checked' : '' }}
                                    class="mr-3"
                                >
                                <div>
                                    <p class="font-bold text-gray-800">PagBank</p>
                                    <p class="text-xs text-gray-600">PagSeguro</p>
                                </div>
                            </label>

                            <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-500 {{ $settings['pix_gateway'] == 'santander' ? 'border-blue-500 bg-blue-50' : 'border-gray-300' }}">
                                <input 
                                    type="radio" 
                                    name="pix_gateway" 
                                    value="santander" 
                                    {{ $settings['pix_gateway'] == 'santander' ? 'checked' : '' }}
                                    class="mr-3"
                                >
                                <div>
                                    <p class="font-bold text-gray-800">Santander</p>
                                    <p class="text-xs text-gray-600">Banco</p>
                                </div>
                            </label>
                        </div>
                        @error('pix_gateway')
                            <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Campos PagBank (aparecem quando PagBank está selecionado) -->
                    <div id="pagbank-fields" class="mt-6 p-4 bg-blue-50 rounded-xl border-2 border-blue-200" style="display: {{ $settings['pix_gateway'] == 'pagbank' ? 'block' : 'none' }};">
                        <h3 class="text-lg font-bold text-blue-800 mb-4 flex items-center">
                            <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                            </svg>
                            Credenciais PagBank
                        </h3>
                        
                        <div class="space-y-4">
                            <!-- Seleção de Conta Pré-configurada -->
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-3">
                                    👤 Selecionar Conta PagBank
                                </label>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                                    <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-500 {{ ($settings['pagbank_account'] ?? 'junior') == 'junior' ? 'border-blue-500 bg-white' : 'border-gray-300 bg-white' }}">
                                        <input 
                                            type="radio" 
                                            name="pagbank_account" 
                                            value="junior" 
                                            id="pagbank_account_junior"
                                            {{ ($settings['pagbank_account'] ?? 'junior') == 'junior' ? 'checked' : '' }}
                                            class="mr-3"
                                        >
                                        <div>
                                            <p class="font-bold text-gray-800">Conta Junior</p>
                                            <p class="text-xs text-gray-600">juniormoreiragloboplay@gmail.com</p>
                                            <span class="inline-block mt-1 px-2 py-0.5 bg-green-100 text-green-700 text-xs rounded-full">Padrão</span>
                                        </div>
                                    </label>

                                    <label class="relative flex items-center p-4 border-2 rounded-xl cursor-pointer transition-all hover:border-blue-500 {{ ($settings['pagbank_account'] ?? 'junior') == 'erick' ? 'border-blue-500 bg-white' : 'border-gray-300 bg-white' }}">
                                        <input 
                                            type="radio" 
                                            name="pagbank_account" 
                                            value="erick" 
                                            id="pagbank_account_erick"
                                            {{ ($settings['pagbank_account'] ?? 'junior') == 'erick' ? 'checked' : '' }}
                                            class="mr-3"
                                        >
                                        <div>
                                            <p class="font-bold text-gray-800">Conta Erick</p>
                                            <p class="text-xs text-gray-600">erickafram10@gmail.com</p>
                                            <span class="inline-block mt-1 px-2 py-0.5 bg-blue-100 text-blue-700 text-xs rounded-full">Secundária</span>
                                        </div>
                                    </label>
                                </div>
                                @error('pagbank_account')
                                    <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Campos ocultos com os valores das credenciais (preenchidos via JS) -->
                            <input type="hidden" name="pagbank_email" id="pagbank_email" value="{{ old('pagbank_email', $settings['pagbank_email'] ?? '') }}">
                            <input type="hidden" name="pagbank_token" id="pagbank_token" value="{{ old('pagbank_token', $settings['pagbank_token'] ?? '') }}">

                            <!-- Info da conta selecionada -->
                            <div class="mt-4 p-3 bg-white rounded-lg border border-blue-200">
                                <p class="text-sm text-gray-600">
                                    <span class="font-bold">Email ativo:</span> 
                                    <span id="active-email" class="font-mono text-blue-600">{{ $settings['pagbank_email'] ?? 'juniormoreiragloboplay@gmail.com' }}</span>
                                </p>
                                <p class="text-sm text-gray-600 mt-1">
                                    <span class="font-bold">Token:</span> 
                                    <span class="font-mono text-gray-500">••••{{ substr($settings['pagbank_token'] ?? '', -8) }}</span>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Botões de Ação -->
            <div class="flex justify-end space-x-4">
                <a href="{{ route('admin.dashboard') }}" class="px-6 py-3 bg-gray-200 text-gray-700 font-bold rounded-xl hover:bg-gray-300 transition-colors">
                    Cancelar
                </a>
                <button type="submit" class="px-6 py-3 bg-gradient-to-r from-green-500 to-green-600 text-white font-bold rounded-xl hover:from-green-600 hover:to-green-700 transition-all transform hover:scale-105 shadow-lg">
                    💾 Salvar Configurações
                </button>
            </div>
        </form>

    </div>
</div>

@push('scripts')
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const gatewayRadios = document.querySelectorAll('input[name="pix_gateway"]');
        const pagbankFields = document.getElementById('pagbank-fields');
        const accountRadios = document.querySelectorAll('input[name="pagbank_account"]');
        const emailInput = document.getElementById('pagbank_email');
        const tokenInput = document.getElementById('pagbank_token');
        const activeEmailSpan = document.getElementById('active-email');
        
        // Credenciais das contas pré-configuradas
        const accounts = {
            junior: {
                email: 'juniormoreiragloboplay@gmail.com',
                token: 'c75a2308-ec9d-4825-94fd-bacba8a7248344f58a634d1b857348dba39f6a5b6c957b2a-2890-4da4-9866-af24b6eee984'
            },
            erick: {
                email: 'erickafram10@gmail.com',
                token: 'e41abc67-2aee-45d7-82e1-69b3b4c35c52caece8f4410eb9e73f94523285451060679e-608e-4cf9-9d02-6894277eaa88'
            }
        };
        
        function togglePagbankFields() {
            const selectedGateway = document.querySelector('input[name="pix_gateway"]:checked');
            if (selectedGateway && selectedGateway.value === 'pagbank') {
                pagbankFields.style.display = 'block';
            } else {
                pagbankFields.style.display = 'none';
            }
        }
        
        function updatePagbankCredentials() {
            const selectedAccount = document.querySelector('input[name="pagbank_account"]:checked');
            if (selectedAccount && accounts[selectedAccount.value]) {
                const account = accounts[selectedAccount.value];
                emailInput.value = account.email;
                tokenInput.value = account.token;
                activeEmailSpan.textContent = account.email;
                
                // Atualizar visual dos cards
                document.querySelectorAll('input[name="pagbank_account"]').forEach(radio => {
                    const label = radio.closest('label');
                    if (radio.checked) {
                        label.classList.remove('border-gray-300');
                        label.classList.add('border-blue-500');
                    } else {
                        label.classList.remove('border-blue-500');
                        label.classList.add('border-gray-300');
                    }
                });
            }
        }
        
        gatewayRadios.forEach(radio => {
            radio.addEventListener('change', togglePagbankFields);
        });
        
        accountRadios.forEach(radio => {
            radio.addEventListener('change', updatePagbankCredentials);
        });
        
        // Inicializar credenciais com a conta selecionada
        updatePagbankCredentials();
    });
</script>
@endpush
@endsection
