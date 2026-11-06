<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reativar Internet - WiFi Tocantins</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="{{ asset('js/tailwind.play.js') }}"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'tocantins-gold': '#FFD700',
                        'tocantins-green': '#228B22',
                        'tocantins-dark-green': '#006400',
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .animate-fade-in { animation: fadeIn 0.4s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body class="bg-gradient-to-b from-gray-50 to-gray-100 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <!-- Header -->
        <div class="text-center mb-6 animate-fade-in">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-amber-100 rounded-full mb-3">
                <span class="text-3xl">🚌</span>
            </div>
            <h1 class="text-xl font-bold text-gray-800">WiFi Tocantins</h1>
            <p class="text-sm text-gray-500 mt-1">Reativar acesso à internet</p>
        </div>

        <!-- Card principal -->
        <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden animate-fade-in">
            <!-- Banner -->
            <div class="bg-gradient-to-r from-amber-500 to-amber-600 px-5 py-4">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-white/20 rounded-full flex items-center justify-center">
                        <span class="text-xl">⚠️</span>
                    </div>
                    <div>
                        <p class="text-white font-bold text-sm">Pagou e não tem acesso?</p>
                        <p class="text-amber-100 text-xs">Informe seu telefone para reativar</p>
                    </div>
                </div>
            </div>

            <!-- Formulário -->
            <div class="p-5">
                <div class="bg-amber-50 rounded-lg p-3 mb-4">
                    <p class="text-xs text-amber-700">
                        <strong>Tente primeiro:</strong> nas configurações do Wi-Fi, esqueça a rede
                        <strong>TocantinsTransporteWiFi</strong> e conecte de novo.
                    </p>
                    <p class="text-xs text-amber-700 mt-2">
                        Não voltou? Informe o telefone que você usou para pagar.
                    </p>
                </div>

                <div class="space-y-3">
                    <div>
                        <label for="reactivate-phone" class="block text-xs font-semibold text-gray-600 mb-1">Telefone com DDD</label>
                        <input 
                            type="tel" 
                            id="reactivate-phone" 
                            placeholder="(63) 99999-9999" 
                            class="w-full px-4 py-3 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-amber-400 focus:border-amber-400 transition-all"
                            maxlength="15"
                        >
                    </div>
                    
                    <button 
                        type="button" 
                        onclick="reactivateAccess()" 
                        id="reactivate-btn"
                        class="w-full py-3 bg-amber-500 text-white font-bold rounded-xl text-sm hover:bg-amber-600 active:bg-amber-700 transition-colors shadow-md"
                    >
                        Reativar minha internet
                    </button>
                </div>

                <!-- Resultado -->
                <div id="reactivate-result" class="hidden mt-4"></div>

                <!-- Dicas (aparecem após resultado) -->
                <div id="reactivate-tips" class="hidden mt-4 bg-blue-50 rounded-xl p-4 border border-blue-100">
                    <p class="text-xs font-bold text-blue-800 mb-2">Se ainda não funcionar:</p>
                    <ol class="text-xs text-blue-700 space-y-1.5">
                        <li><strong>1.</strong> Abra as configurações de Wi-Fi</li>
                        <li><strong>2.</strong> Toque em <strong>TocantinsTransporteWiFi</strong> e escolha <strong>Esquecer</strong></li>
                        <li><strong>3.</strong> Conecte de novo na rede</li>
                    </ol>
                    <p class="text-xs text-blue-700 mt-2 pt-2 border-t border-blue-100">
                        <strong>Para não acontecer de novo:</strong> nas opções dessa rede, deixe o
                        <em>Endereço Privado</em> como <strong>Fixo</strong> ou desativado (iPhone), ou escolha
                        <strong>usar o MAC do aparelho</strong> (Android).
                    </p>
                </div>
            </div>
        </div>

        <!-- Voltar ao portal -->
        <div class="text-center mt-4 animate-fade-in">
            <a href="/" class="text-xs text-gray-400 hover:text-gray-600 transition-colors">
                ← Voltar ao portal
            </a>
        </div>

        <!-- Contato -->
        <div class="text-center mt-6 animate-fade-in">
            <p class="text-xs text-gray-400">Precisa de ajuda?</p>
            <p class="text-xs text-gray-500 mt-1">Envie mensagem pelo WhatsApp</p>
        </div>
    </div>

    <script>
        // Máscara de telefone
        document.getElementById('reactivate-phone').addEventListener('input', function(e) {
            let v = e.target.value.replace(/\D/g, '');
            if (v.length > 11) v = v.substring(0, 11);
            if (v.length > 7) v = '(' + v.substring(0,2) + ') ' + v.substring(2,7) + '-' + v.substring(7);
            else if (v.length > 2) v = '(' + v.substring(0,2) + ') ' + v.substring(2);
            e.target.value = v;
        });

        // Enter para enviar
        document.getElementById('reactivate-phone').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') reactivateAccess();
        });

        function reactivateAccess() {
            const phoneInput = document.getElementById('reactivate-phone');
            const btn = document.getElementById('reactivate-btn');
            const result = document.getElementById('reactivate-result');
            const tips = document.getElementById('reactivate-tips');
            const phone = (phoneInput?.value || '').replace(/\D/g, '');

            if (phone.length < 10) {
                showResult('Informe um telefone válido com DDD.', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="animate-pulse">Verificando...</span>';

            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';

            fetch('/api/reativar-acesso', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json', 
                    'X-CSRF-TOKEN': csrfToken, 
                    'Accept': 'application/json' 
                },
                body: JSON.stringify({ phone: phone })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showResult(data.message, 'success');
                    // Mostrar dicas após sucesso
                    setTimeout(() => { tips.classList.remove('hidden'); }, 1500);
                } else {
                    showResult(data.message || 'Não foi possível reativar.', data.needs_payment ? 'warning' : 'error');
                }
            })
            .catch(() => showResult('Erro de conexão. Tente novamente.', 'error'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = 'Reativar minha internet';
            });
        }

        function showResult(message, type) {
            const result = document.getElementById('reactivate-result');
            if (!result) return;
            result.classList.remove('hidden');
            const colors = { 
                success: 'bg-emerald-50 text-emerald-700 border-emerald-200', 
                error: 'bg-red-50 text-red-700 border-red-200', 
                warning: 'bg-amber-50 text-amber-700 border-amber-200' 
            };
            const icons = { success: '✅', error: '❌', warning: '⚠️' };
            result.innerHTML = '<div class="p-3 rounded-xl border text-sm ' + (colors[type] || colors.error) + '">' + (icons[type] || '') + ' ' + message + '</div>';
        }
    </script>
</body>
</html>
