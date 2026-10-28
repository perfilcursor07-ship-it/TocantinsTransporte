<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro enviado - WiFi Tocantins</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body{font-family:Inter,system-ui,sans-serif}</style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-white to-slate-100 min-h-screen flex items-center justify-center p-4">
<div class="w-full max-w-md text-center">
    <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4">
        <svg class="w-8 h-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
    </div>

    @php $result = $result ?? null; @endphp

    <h1 class="text-xl font-bold text-slate-800 mb-2">
        {{ $result && $result['is_update'] ? 'Dados confirmados!' : 'Cadastro enviado!' }}
    </h1>

    @if($result)
    <div class="bg-white rounded-2xl border border-slate-100 shadow-lg shadow-slate-200/60 p-5 text-left mt-4 mb-5">
        <div class="flex justify-between gap-3 py-1.5 text-sm">
            <span class="text-slate-500">Mês</span>
            <span class="font-bold text-slate-800">{{ $result['month_label'] }}</span>
        </div>
        <div class="flex justify-between gap-3 py-1.5 text-sm border-t border-slate-100">
            <span class="text-slate-500">Carro / ônibus</span>
            <span class="font-bold text-slate-800">{{ $result['bus_number'] }}</span>
        </div>
        <div class="flex justify-between gap-3 py-1.5 text-sm border-t border-slate-100">
            <span class="text-slate-500">Situação</span>
            <span class="font-bold {{ $result['needs_review'] ? 'text-amber-600' : 'text-emerald-600' }}">
                {{ $result['needs_review'] ? 'Em análise' : 'Liberado para pagamento' }}
            </span>
        </div>
    </div>

    @if($result['pix_changed'])
    <p class="text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-2xl px-4 py-3 mb-4">
        Você trocou a chave PIX. O administrador precisa aprovar a nova chave antes do próximo pagamento.
    </p>
    @elseif($result['needs_review'])
    <p class="text-sm text-slate-600 mb-4">O administrador vai analisar e aprovar seu cadastro em breve.</p>
    @else
    <p class="text-sm text-slate-600 mb-4">Seus dados deste mês estão confirmados. Nada mais é necessário.</p>
    @endif
    @else
    <p class="text-sm text-slate-600 mb-6">Seus dados foram recebidos. O administrador irá analisar e aprovar seu cadastro PIX em breve.</p>
    @endif

    <div class="rounded-2xl border-2 border-emerald-300 bg-emerald-50 px-4 py-4 mb-4">
        <p class="text-[15px] font-bold text-emerald-900 leading-snug">
            O sistema irá liberar o pagamento em até 24 horas.
        </p>
        <p class="text-sm text-emerald-800 mt-1">Obrigado!</p>
    </div>

    <p class="text-xs text-slate-400">Você pode fechar esta página.</p>
</div>
</body>
</html>
