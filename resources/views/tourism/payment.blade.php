<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pagamento da internet - WiFi Tocantins</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body { font-family: "Segoe UI", system-ui, -apple-system, sans-serif; }
        #qrcode img, #qrcode canvas { margin: 0 auto; }
    </style>
</head>
@php
    $money = fn ($v) => 'R$ '.number_format((float) $v, 2, ',', '.');
    $percent = rtrim(rtrim(number_format($quote['discount_percent'], 2, ',', ''), '0'), ',');
@endphp
<body class="bg-gradient-to-br from-emerald-50 via-white to-slate-100 min-h-screen py-8 px-4">
<div class="w-full max-w-lg mx-auto">
    <div class="text-center mb-6">
        <div class="w-14 h-14 bg-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-emerald-200">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/></svg>
        </div>
        <p class="text-[11px] font-bold uppercase tracking-widest text-emerald-700">WiFi Tocantins · Turismo</p>
        <h1 class="text-2xl font-bold text-slate-800 mt-1">Pagamento da internet</h1>
        <p class="text-sm text-slate-500 mt-1">{{ $trip->title }}</p>
    </div>

    {{-- Resumo da viagem e do valor --}}
    <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 p-5 mb-5">
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
            @if($trip->contract_number)
                <div><dt class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Contrato</dt><dd class="font-semibold text-slate-700">{{ $trip->contract_number }}</dd></div>
            @endif
            <div><dt class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Responsável</dt><dd class="font-semibold text-slate-700">{{ $trip->responsible_name }}</dd></div>
            <div><dt class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Saída</dt><dd class="text-slate-700">{{ $trip->starts_at->format('d/m/Y H:i') }}</dd></div>
            <div><dt class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Chegada</dt><dd class="text-slate-700">{{ $trip->ends_at->format('d/m/Y H:i') }}</dd></div>
            <div class="col-span-2"><dt class="text-[10px] font-bold uppercase tracking-widest text-slate-400">Internet</dt><dd class="text-slate-700">{{ $trip->planLabel() }}</dd></div>
        </dl>

        <div class="mt-4 pt-4 border-t border-slate-100 space-y-1.5 text-sm text-slate-600">
            @foreach($quote['lines'] as $line)
                <div class="flex justify-between gap-3"><span>{{ $line['label'] }}: {{ $line['plan'] }}</span><span>{{ $money($line['price']) }}</span></div>
            @endforeach
            <div class="flex justify-between gap-3"><span>{{ $quote['passengers'] }} passageiros × {{ $money($quote['per_passenger']) }}</span><span>{{ $money($quote['subtotal']) }}</span></div>
            @if($quote['discount'] > 0)
                <div class="flex justify-between gap-3 text-emerald-700"><span>Desconto de {{ $percent }}%</span><span>− {{ $money($quote['discount']) }}</span></div>
            @endif
            <div class="flex justify-between items-end gap-3 pt-2">
                <span class="text-[11px] font-bold uppercase tracking-widest text-slate-400">Total</span>
                <span class="text-2xl font-bold text-slate-800">{{ $money($trip->agreed_amount) }}</span>
            </div>
        </div>
    </div>

    @if($trip->isPaid())
        <div class="bg-emerald-600 text-white rounded-3xl p-6 text-center shadow-xl shadow-emerald-200">
            <svg class="w-12 h-12 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
            <p class="text-xl font-bold">Pagamento confirmado!</p>
            <p class="text-sm text-white/80 mt-1">Recebemos {{ $money($trip->paid_amount) }} em {{ $trip->paid_at?->format('d/m/Y H:i') }}. Obrigado!</p>
        </div>
    @elseif($trip->status === 'cancelled')
        <div class="bg-white rounded-3xl border border-slate-200 p-6 text-center text-sm text-slate-600">
            Esta viagem foi cancelada. Fale com a empresa antes de fazer qualquer pagamento.
        </div>
    @elseif($error)
        <div class="bg-red-50 border border-red-200 rounded-3xl p-5 text-center text-sm text-red-700">
            <p>{{ $error }}</p>
            <button type="button" onclick="location.reload()" class="mt-3 px-4 py-2 bg-red-600 text-white rounded-xl text-xs font-bold">Tentar novamente</button>
        </div>
    @else
        <div class="bg-white rounded-3xl shadow-xl shadow-slate-200/60 border border-slate-100 p-5 text-center">
            <p class="text-sm font-bold text-slate-800">Pague com PIX</p>
            <p class="text-xs text-slate-500 mt-0.5 mb-4">Escaneie o QR Code no app do seu banco ou use o PIX copia e cola.</p>

            <div id="qrcode" class="inline-block p-3 bg-white rounded-2xl border border-slate-100"></div>

            <div class="mt-4 text-left">
                <label for="pix-code" class="text-[10px] font-bold uppercase tracking-widest text-slate-400">PIX copia e cola</label>
                <textarea id="pix-code" readonly rows="3" onclick="this.select()"
                          class="mt-1 w-full px-3 py-2 bg-slate-50 border border-slate-200 rounded-xl text-[11px] text-slate-600 break-all resize-none">{{ $trip->pix_code }}</textarea>
                <button type="button" id="copy-pix" class="mt-2 w-full py-3 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-bold transition">
                    Copiar código PIX
                </button>
            </div>

            <div class="mt-4 flex items-center justify-center gap-2 text-xs text-amber-700">
                <span class="w-2 h-2 rounded-full bg-amber-500 animate-pulse"></span>
                <span id="payment-status">Aguardando pagamento...</span>
            </div>
            @if($trip->pix_expires_at)
                <p class="text-[11px] text-slate-400 mt-1">Este QR Code vale até {{ $trip->pix_expires_at->format('d/m/Y H:i') }}. Depois disso, abra o link de novo.</p>
            @endif
        </div>
    @endif

    <p class="text-center text-[11px] text-slate-400 mt-6">Pagamento processado pelo PagBank.</p>
</div>

@if(! $trip->isPaid() && $trip->status !== 'cancelled' && ! $error)
<script src="{{ asset('js/qrcode.min.js') }}"></script>
<script>
(function () {
    const code = document.getElementById('pix-code').value;
    new QRCode(document.getElementById('qrcode'), { text: code, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });

    const copy = document.getElementById('copy-pix');
    copy.addEventListener('click', () => {
        document.getElementById('pix-code').select();
        (navigator.clipboard ? navigator.clipboard.writeText(code) : Promise.reject())
            .catch(() => document.execCommand('copy'))
            .finally(() => { copy.textContent = 'Código copiado!'; setTimeout(() => copy.textContent = 'Copiar código PIX', 2500); });
    });

    // Confere a cada 8s; quando o PIX cair, recarrega mostrando a confirmação.
    const statusUrl = @json(route('tourism.payment.status', $trip->payment_token));
    const timer = setInterval(() => {
        fetch(statusUrl, { headers: { Accept: 'application/json' } })
            .then(r => r.ok ? r.json() : null)
            .then(data => { if (data && data.paid) { clearInterval(timer); location.reload(); } })
            .catch(() => {});
    }, 8000);
})();
</script>
@endif
</body>
</html>
