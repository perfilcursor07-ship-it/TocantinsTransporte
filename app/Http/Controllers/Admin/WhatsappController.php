<?php

namespace App\Http\Controllers\Admin;

use App\Console\Commands\SendUnpaidPaymentReminders;
use App\Http\Controllers\Controller;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOptOut;
use App\Models\WhatsappSetting;
use App\Models\Payment;
use App\Models\User;
use App\Services\UnpaidReminderSender;
use App\Services\WhatsappClient;
use App\Services\WhatsappSendPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class WhatsappController extends Controller
{
    /**
     * URL do servidor Node.js Baileys
     */
    protected $baileysServerUrl;

    public function __construct()
    {
        $this->baileysServerUrl = env('BAILEYS_SERVER_URL', 'http://localhost:3001');
    }

    /**
     * Página principal do módulo WhatsApp
     */
    public function index()
    {
        $stats = [
            'total_messages' => WhatsappMessage::count(),
            'sent_messages' => WhatsappMessage::where('status', 'sent')->count(),
            'failed_messages' => WhatsappMessage::where('status', 'failed')->count(),
            'pending_messages' => WhatsappMessage::where('status', 'pending')->count(),
            'today_messages' => WhatsappMessage::whereDate('created_at', today())->count(),
        ];

        $recentMessages = WhatsappMessage::with(['user', 'payment'])
            ->orderBy('created_at', 'desc')
            ->limit(20)
            ->get();

        $settings = [
            'is_connected' => WhatsappSetting::isConnected(),
            'connection_status' => WhatsappSetting::getConnectionStatus(),
            'connected_phone' => WhatsappSetting::getConnectedPhone(),
            'auto_send_enabled' => WhatsappSetting::isAutoSendEnabled(),
            'pending_minutes' => WhatsappSetting::getPendingMinutes(),
            'message_template' => WhatsappSetting::getMessageTemplate(),
            'qr_code' => WhatsappSetting::getQrCode(),
        ];

        // Buscar pagamentos pendentes há mais de X minutos
        // Excluir usuários que já pagaram (têm pagamento completed nas últimas 24h)
        $pendingMinutes = $settings['pending_minutes'];
        
        // IDs de usuários que já pagaram nas últimas 24 horas
        $paidUserIds = Payment::where('status', 'completed')
            ->where('paid_at', '>=', Carbon::now()->subHours(24))
            ->pluck('user_id')
            ->unique()
            ->toArray();
        
        $pendingPayments = Payment::where('status', 'pending')
            ->where('created_at', '<=', Carbon::now()->subMinutes($pendingMinutes))
            ->whereDate('created_at', Carbon::today()) // Apenas pagamentos do dia
            ->whereNotIn('user_id', $paidUserIds) // Excluir quem já pagou
            ->whereHas('user', function($q) {
                $q->whereNotNull('phone')
                  ->where('phone', '!=', ''); // Garantir que tem telefone válido
            })
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('user_id'); // Mostrar apenas 1 pagamento por usuário

        $insights = $this->reminderInsights($pendingPayments);

        return view('admin.whatsapp.index', compact('stats', 'recentMessages', 'settings', 'pendingPayments', 'insights'));
    }

    /**
     * Números do painel "Resultado dos lembretes": quanto foi enviado, quanto
     * voltou em venda e os sinais de risco de bloqueio. É só leitura; se algo
     * falhar, a página abre sem o painel.
     */
    protected function reminderInsights($pendingPayments): array
    {
        $insights = [
            'ok' => false,
            'sent' => 0, 'delivered' => 0, 'converted' => 0, 'revenue' => 0.0,
            'opt_outs' => 0, 'last_reminder_at' => null, 'paid_with_code' => 0, 'pix_code_enabled' => false,
            'last_run_at' => null, 'last_run_result' => null, 'run_is_stale' => true,
            'feature_enabled' => false,
            'cap' => 0, 'sent_today' => 0, 'quiet_label' => '', 'is_quiet' => false,
            'waiting_count' => 0, 'waiting_amount' => 0.0,
        ];

        try {
            $since = Carbon::now()->subDays(30);

            $reminders = WhatsappMessage::reminders()
                ->where('created_at', '>=', $since)
                ->whereIn('status', ['sent', 'delivered', 'read'])
                ->get(['id', 'user_id', 'status', 'created_at']);

            // Vendas depois do lembrete: pagamento concluído do mesmo passageiro
            // em até 24h. É correlação (ele poderia ter pago de qualquer jeito),
            // mas é a medida que mostra se o texto está funcionando.
            $paidByUser = Payment::where('status', 'completed')
                ->where('paid_at', '>=', $since)
                ->whereIn('user_id', $reminders->pluck('user_id')->filter()->unique())
                ->get(['id', 'user_id', 'amount', 'paid_at'])
                ->groupBy('user_id');

            $recovered = [];
            $converted = 0;
            foreach ($reminders as $reminder) {
                $hit = false;
                foreach ($paidByUser->get($reminder->user_id, []) as $paid) {
                    $paidAt = $paid->paid_at ? Carbon::parse($paid->paid_at) : null;
                    if ($paidAt && $paidAt->gt($reminder->created_at)
                        && $paidAt->lte($reminder->created_at->copy()->addHours(24))) {
                        $recovered[$paid->id] = (float) $paid->amount;
                        $hit = true;
                    }
                }
                $converted += $hit ? 1 : 0;
            }

            $lastRunAt = WhatsappSetting::get('reminder_last_run_at');
            $lastRun = $lastRunAt ? Carbon::parse($lastRunAt) : null;
            $lastReminder = WhatsappMessage::reminders()->max('created_at');
            $waiting = $pendingPayments->filter(fn ($p) => $p->user && $p->user->hasWhatsappPaymentOptIn());

            $insights = array_merge($insights, [
                'ok' => true,
                'sent' => $reminders->count(),
                'delivered' => $reminders->whereIn('status', ['delivered', 'read'])->count(),
                'converted' => $converted,
                'revenue' => array_sum($recovered),
                'opt_outs' => WhatsappOptOut::where('opted_out_at', '>=', $since)->count(),
                // Atribuição exata: cobranças criadas pelo lembrete e pagas.
                'paid_with_code' => Payment::where('status', 'completed')
                    ->where('paid_at', '>=', $since)
                    ->where('payment_data->source', 'whatsapp_reminder')
                    ->count(),
                'pix_code_enabled' => UnpaidReminderSender::pixCodeEnabled(),
                'last_reminder_at' => $lastReminder ? Carbon::parse($lastReminder) : null,
                'last_run_at' => $lastRun,
                'last_run_result' => WhatsappSetting::get('reminder_last_run_result'),
                // O comando roda a cada 5 min; 20 min sem sinal = agendador parado.
                'run_is_stale' => ! $lastRun || $lastRun->lt(Carbon::now()->subMinutes(20)),
                'feature_enabled' => (bool) \App\Models\SystemSetting::getValue('unpaid_reminder_enabled', '0'),
                'cap' => WhatsappSendPolicy::dailyCap(),
                'sent_today' => WhatsappSendPolicy::remindersSentToday(),
                'quiet_label' => WhatsappSendPolicy::quietLabel(),
                'is_quiet' => WhatsappSendPolicy::isQuietTime(),
                'waiting_count' => $waiting->count(),
                'waiting_amount' => (float) $waiting->sum('amount'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Painel de lembretes do WhatsApp indisponível', ['erro' => $e->getMessage()]);
        }

        return $insights;
    }

    /**
     * Página de mensagens
     */
    public function messages(Request $request)
    {
        $query = WhatsappMessage::with(['user', 'payment']);

        // Filtros
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('phone')) {
            $query->where('phone', 'like', '%' . $request->phone . '%');
        }

        if ($request->filled('date_from')) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $messages = $query->orderBy('created_at', 'desc')->paginate(50);

        return view('admin.whatsapp.messages', compact('messages'));
    }

    /**
     * Página de configurações
     */
    public function settings()
    {
        $settings = [
            'auto_send_enabled' => WhatsappSetting::isAutoSendEnabled(),
            'pending_minutes' => WhatsappSetting::getPendingMinutes(),
            'message_template' => WhatsappSetting::getMessageTemplate(),
            'quiet_start_hour' => WhatsappSendPolicy::quietStartHour(),
            'quiet_end_hour' => WhatsappSendPolicy::quietEndHour(),
            'daily_reminder_cap' => WhatsappSendPolicy::dailyCap(),
            'reminders_today' => WhatsappSendPolicy::remindersSentToday(),
            'reminder_pix_code_enabled' => UnpaidReminderSender::pixCodeEnabled(),
        ];

        return view('admin.whatsapp.settings', compact('settings'));
    }

    /**
     * Atualizar configurações
     */
    public function updateSettings(Request $request)
    {
        $request->validate([
            'pending_minutes' => 'required|integer|min:15|max:1440',
            'message_template' => 'required|string|max:1000',
            'auto_send_enabled' => 'nullable|boolean',
            'quiet_start_hour' => 'required|integer|min:0|max:23',
            'quiet_end_hour' => 'required|integer|min:0|max:23',
            'daily_reminder_cap' => 'required|integer|min:1|max:200',
            'reminder_pix_code_enabled' => 'nullable|boolean',
        ]);

        WhatsappSetting::set('quiet_start_hour', (int) $request->quiet_start_hour);
        WhatsappSetting::set('quiet_end_hour', (int) $request->quiet_end_hour);
        WhatsappSetting::set('daily_reminder_cap', (int) $request->daily_reminder_cap);
        WhatsappSetting::set('pending_minutes', $request->pending_minutes);
        WhatsappSetting::set('message_template', $request->message_template);
        WhatsappSetting::set('auto_send_enabled', $request->has('auto_send_enabled') ? 'true' : 'false');
        WhatsappSetting::set('reminder_pix_code_enabled', $request->has('reminder_pix_code_enabled') ? 'true' : 'false');

        return redirect()->back()->with('success', 'Configurações atualizadas com sucesso!');
    }

    /**
     * Obter QR Code para conexão
     */
    public function getQrCode()
    {
        try {
            $response = WhatsappClient::http(30)->get($this->baileysServerUrl . '/qrcode');
            
            if ($response->successful()) {
                $data = $response->json();
                
                if (isset($data['qrcode'])) {
                    WhatsappSetting::set('last_qr_code', $data['qrcode']);
                    WhatsappSetting::set('connection_status', 'waiting_scan');
                }
                
                return response()->json($data);
            }
            
            return response()->json(['error' => 'Erro ao obter QR Code'], 500);
        } catch (\Exception $e) {
            Log::error('Erro ao obter QR Code: ' . $e->getMessage());
            return response()->json([
                'error' => 'Servidor WhatsApp não está rodando. Execute: node whatsapp-server/server.js',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar status da conexão
     */
    public function checkStatus()
    {
        try {
            $response = WhatsappClient::http(10)->get($this->baileysServerUrl . '/status');
            
            if ($response->successful()) {
                $data = $response->json();
                
                // Atualizar status no banco
                WhatsappSetting::updateConnectionStatus(
                    $data['status'] ?? 'disconnected',
                    $data['phone'] ?? null
                );
                
                return response()->json($data);
            }
            
            WhatsappSetting::updateConnectionStatus('disconnected');
            return response()->json(['status' => 'disconnected']);
        } catch (\Exception $e) {
            WhatsappSetting::updateConnectionStatus('disconnected');
            return response()->json([
                'status' => 'disconnected',
                'error' => 'Servidor não disponível'
            ]);
        }
    }

    /**
     * Desconectar WhatsApp
     */
    public function disconnect()
    {
        try {
            $response = WhatsappClient::http(10)->post($this->baileysServerUrl . '/disconnect');
            
            WhatsappSetting::updateConnectionStatus('disconnected');
            WhatsappSetting::set('last_qr_code', null);
            
            return response()->json(['success' => true, 'message' => 'Desconectado com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao desconectar'], 500);
        }
    }

    // ==================================================================
    // 🧩 SESSÃO DE AVALIAÇÃO (número SEPARADO) — espelha os métodos acima,
    // mas conversa com a sessão "review" do servidor Baileys e grava as
    // chaves review_* no banco. Um ban aqui NÃO afeta o número de PIX.
    // ==================================================================

    /**
     * Obter QR Code para conectar o número de AVALIAÇÃO.
     */
    public function getReviewQrCode()
    {
        try {
            $response = WhatsappClient::http(30)->get($this->baileysServerUrl . '/qrcode', ['session' => 'review']);

            if ($response->successful()) {
                $data = $response->json();

                if (isset($data['qrcode'])) {
                    WhatsappSetting::setQrCodeFor('review', $data['qrcode']);
                    WhatsappSetting::updateConnectionStatusFor('review', 'waiting_scan');
                }

                return response()->json($data);
            }

            return response()->json(['error' => 'Erro ao obter QR Code'], 500);
        } catch (\Exception $e) {
            Log::error('Erro ao obter QR Code (review): ' . $e->getMessage());
            return response()->json([
                'error' => 'Servidor WhatsApp não está rodando. Execute: node whatsapp-server/server.js',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Verificar status da conexão do número de AVALIAÇÃO.
     */
    public function checkReviewStatus()
    {
        try {
            $response = WhatsappClient::http(10)->get($this->baileysServerUrl . '/status', ['session' => 'review']);

            if ($response->successful()) {
                $data = $response->json();

                WhatsappSetting::updateConnectionStatusFor(
                    'review',
                    $data['status'] ?? 'disconnected',
                    $data['phone'] ?? null
                );

                return response()->json($data);
            }

            WhatsappSetting::updateConnectionStatusFor('review', 'disconnected');
            return response()->json(['status' => 'disconnected']);
        } catch (\Exception $e) {
            WhatsappSetting::updateConnectionStatusFor('review', 'disconnected');
            return response()->json([
                'status' => 'disconnected',
                'error' => 'Servidor não disponível'
            ]);
        }
    }

    /**
     * Desconectar o número de AVALIAÇÃO.
     */
    public function disconnectReview()
    {
        try {
            WhatsappClient::http(10)->post($this->baileysServerUrl . '/disconnect', ['session' => 'review']);

            WhatsappSetting::updateConnectionStatusFor('review', 'disconnected');
            WhatsappSetting::setQrCodeFor('review', null);

            return response()->json(['success' => true, 'message' => 'Número de avaliação desconectado com sucesso']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Erro ao desconectar'], 500);
        }
    }

    /**
     * Página para escanear o QR Code do número de AVALIAÇÃO (sessão separada).
     * Página autocontida — não depende do layout do admin.
     */
    public function reviewConnect()
    {
        $qrUrl = route('admin.whatsapp.review.qrcode');
        $statusUrl = route('admin.whatsapp.review.status');
        $disconnectUrl = route('admin.whatsapp.review.disconnect');
        $csrf = csrf_token();

        $html = <<<HTML
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{$csrf}">
<title>Conectar WhatsApp de Avaliação</title>
<style>
  body{font-family:Arial,Helvetica,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:24px;display:flex;justify-content:center}
  .card{background:#1e293b;border:1px solid #334155;border-radius:16px;max-width:420px;width:100%;padding:24px;text-align:center}
  h1{font-size:18px;margin:0 0 4px}
  p.sub{color:#94a3b8;font-size:13px;margin:0 0 20px}
  .qr{background:#fff;border-radius:12px;padding:16px;display:inline-block;min-height:240px;min-width:240px;display:flex;align-items:center;justify-content:center}
  .qr img{width:240px;height:240px}
  .status{margin-top:18px;font-size:14px;font-weight:bold}
  .ok{color:#22c55e}.warn{color:#f59e0b}.err{color:#ef4444}
  .badge{display:inline-block;background:#0ea5e9;color:#fff;border-radius:999px;padding:4px 12px;font-size:12px;margin-bottom:16px}
  button{margin-top:18px;background:#ef4444;color:#fff;border:0;border-radius:8px;padding:10px 18px;font-size:13px;cursor:pointer}
  .hint{margin-top:16px;font-size:12px;color:#64748b;line-height:1.5}
</style>
</head>
<body>
<div class="card">
  <div class="badge">📊 Número de AVALIAÇÃO</div>
  <h1>Conectar segundo número</h1>
  <p class="sub">Este número envia SÓ as mensagens de avaliação. Use um chip DIFERENTE do número de PIX.</p>
  <div class="qr" id="qrBox"><span style="color:#000">Carregando…</span></div>
  <div class="status warn" id="status">Aguardando QR Code…</div>
  <button id="btnDisconnect" style="display:none">Desconectar este número</button>
  <div class="hint">No celular do número de avaliação: WhatsApp → Aparelhos conectados → Conectar um aparelho → escaneie o QR acima.</div>
</div>
<script>
const QR_URL='{$qrUrl}', STATUS_URL='{$statusUrl}', DISCONNECT_URL='{$disconnectUrl}';
const token=document.querySelector('meta[name=csrf-token]').content;
const qrBox=document.getElementById('qrBox'), statusEl=document.getElementById('status'), btn=document.getElementById('btnDisconnect');
let connected=false;

function setStatus(text,cls){statusEl.textContent=text;statusEl.className='status '+cls;}

async function loadQr(){
  if(connected)return;
  try{
    const r=await fetch(QR_URL,{headers:{'Accept':'application/json'}});
    const d=await r.json();
    if(d.status==='connected'){onConnected(d.phone);return;}
    if(d.qrcode){qrBox.innerHTML='<img src="'+d.qrcode+'" alt="QR">';setStatus('Escaneie o QR Code com o WhatsApp do número de avaliação','warn');}
    else if(d.error){setStatus(d.error,'err');}
  }catch(e){setStatus('Servidor WhatsApp offline. Inicie: node whatsapp-server/server.js','err');}
}

async function checkStatus(){
  try{
    const r=await fetch(STATUS_URL,{headers:{'Accept':'application/json'}});
    const d=await r.json();
    if(d.status==='connected'){onConnected(d.phone);}
    else if(connected){connected=false;btn.style.display='none';loadQr();}
  }catch(e){}
}

function onConnected(phone){
  connected=true;
  qrBox.innerHTML='<span style="color:#000;font-size:40px">✅</span>';
  setStatus('Conectado'+(phone?(' — '+phone):''),'ok');
  btn.style.display='inline-block';
}

btn.addEventListener('click',async()=>{
  if(!confirm('Desconectar o número de avaliação?'))return;
  await fetch(DISCONNECT_URL,{method:'POST',headers:{'X-CSRF-TOKEN':token,'Accept':'application/json'}});
  connected=false;btn.style.display='none';qrBox.innerHTML='<span style="color:#000">Carregando…</span>';loadQr();
});

loadQr();
setInterval(()=>{connected?checkStatus():loadQr();},4000);
setInterval(checkStatus,4000);
</script>
</body>
</html>
HTML;

        return response($html);
    }

    /**
     * Enviar mensagem para um número específico
     */
    public function sendMessage(Request $request)
    {
        $request->validate([
            'phone' => 'required_without:payment_id|nullable|string',
            'message' => 'required_without:payment_id|nullable|string|max:1000',
            'user_id' => 'nullable|exists:users,id',
            'payment_id' => 'nullable|exists:payments,id',
        ]);

        // Botão "Enviar" da lista de pendentes: é um lembrete de PIX, então
        // segue as mesmas regras e o mesmo texto do envio automático. Antes
        // ele mandava o modelo antigo (preço e link errados, sem PARAR) e
        // ignorava horário de silêncio, teto diário e a trava de 24h.
        if ($request->filled('payment_id')) {
            [$status, $body] = $this->sendManualReminder(Payment::with('user')->find($request->payment_id));

            return response()->json($body, $status);
        }

        if (WhatsappOptOut::isOptedOut($request->phone)) {
            return response()->json(['error' => 'Este número pediu para não receber mais mensagens (PARAR).'], 422);
        }

        $phone = WhatsappMessage::formatPhone($request->phone);
        $user = $request->user_id ? User::find($request->user_id) : null;

        // O botão de envio de pendentes informa user_id. Exigimos a mesma
        // autorização do envio automático para não contornar a proteção pela
        // tela administrativa.
        if ($user && (! $user->hasWhatsappPaymentOptIn() || WhatsappOptOut::isOptedOut($user->phone))) {
            return response()->json([
                'error' => 'Este passageiro não autorizou atualizações de pagamento pelo WhatsApp.',
            ], 422);
        }
        
        // Criar registro da mensagem
        $whatsappMessage = WhatsappMessage::create([
            'user_id' => $request->user_id,
            'payment_id' => $request->payment_id,
            'phone' => $phone,
            'message' => $request->message,
            'status' => 'pending',
        ]);

        try {
            $response = WhatsappClient::send($phone, $request->message, [], 30);

            if ($response->successful()) {
                $data = $response->json();
                $whatsappMessage->markAsSent($data['messageId'] ?? null);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Mensagem enviada com sucesso!',
                    'data' => $whatsappMessage->fresh()
                ]);
            }

            $whatsappMessage->markAsFailed($response->body());
            return response()->json(['error' => 'Falha ao enviar mensagem'], 500);
        } catch (\Exception $e) {
            $whatsappMessage->markAsFailed($e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lembrete manual de um PIX pendente, com as proteções do automático.
     *
     * @return array{0:int,1:array<string,mixed>} [status HTTP, corpo JSON]
     */
    protected function sendManualReminder(?Payment $payment): array
    {
        if (! WhatsappSetting::isConnected()) {
            return [400, ['error' => 'WhatsApp não está conectado']];
        }

        $user = $payment?->user;
        if (! $payment || ! $user || ! $user->phone) {
            return [422, ['error' => 'Pagamento sem passageiro ou sem telefone.']];
        }
        if ($payment->status !== 'pending') {
            return [422, ['error' => 'Este pagamento não está mais pendente.']];
        }
        if (! $user->hasWhatsappPaymentOptIn() || WhatsappOptOut::isOptedOut($user->phone)) {
            return [422, ['error' => 'Este passageiro não autorizou atualizações de pagamento pelo WhatsApp.']];
        }
        if (WhatsappSendPolicy::isQuietTime()) {
            return [422, ['error' => 'Fora do horário de envio. Lembretes não são enviados das '
                . WhatsappSendPolicy::quietLabel() . ' para evitar denúncias.']];
        }
        if (WhatsappSendPolicy::remainingToday() === 0) {
            return [422, ['error' => 'Limite diário de lembretes atingido ('
                . WhatsappSendPolicy::dailyCap() . '). Tente amanhã.']];
        }
        if ($this->remindedInLast24h($user)) {
            return [422, ['error' => 'Este passageiro já recebeu mensagem nas últimas 24h. Insistir é o que mais gera bloqueio.']];
        }

        WhatsappSendPolicy::recordReminder();
        $this->lockReminder($payment, $user);

        try {
            $result = app(UnpaidReminderSender::class)->send($user, $payment, 30);
        } catch (\Throwable $e) {
            return [500, ['error' => $e->getMessage()]];
        }

        if (! $result['ok']) {
            return [500, ['error' => $result['error'] ?: 'Falha ao enviar mensagem']];
        }

        return [200, [
            'success' => true,
            'message' => $result['with_code'] ? 'Lembrete enviado com um código PIX novo!' : 'Lembrete enviado com sucesso!',
            'data' => $result['message']?->fresh(),
        ]];
    }

    /** O passageiro já recebeu mensagem nossa (manual ou automática) em 24h? */
    protected function remindedInLast24h(User $user): bool
    {
        if (Cache::has(SendUnpaidPaymentReminders::phoneLockKey($user->phone))) {
            return true;
        }

        return WhatsappMessage::where('user_id', $user->id)
            ->whereIn('status', ['sent', 'delivered', 'read'])
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->exists();
    }

    /**
     * Marca o pagamento e o telefone como "já lembrados", para o envio
     * automático não mandar um segundo lembrete por cima do manual.
     */
    protected function lockReminder(Payment $payment, User $user): void
    {
        try {
            $payment->forceFill(['unpaid_reminder_sent_at' => now()])->saveQuietly();
            Cache::put(SendUnpaidPaymentReminders::phoneLockKey($user->phone), true, now()->addHours(24));
        } catch (\Throwable $e) {
            Log::warning('Não foi possível travar o lembrete manual', ['payment_id' => $payment->id, 'erro' => $e->getMessage()]);
        }
    }

    /**
     * Enviar mensagens para todos os pagamentos pendentes
     */
    public function sendToPendingPayments()
    {
        if (!WhatsappSetting::isConnected()) {
            return response()->json(['error' => 'WhatsApp não está conectado'], 400);
        }

        // 🛡️ Mesmas proteções do envio automático: nada de madrugada e teto diário.
        if (WhatsappSendPolicy::isQuietTime()) {
            return response()->json(['error' => 'Fora do horário de envio. Lembretes não são enviados das '
                . WhatsappSendPolicy::quietLabel() . ' para evitar denúncias.'], 422);
        }
        if (WhatsappSendPolicy::remainingToday() === 0) {
            return response()->json(['error' => 'Limite diário de lembretes atingido ('
                . WhatsappSendPolicy::dailyCap() . '). Tente amanhã.'], 422);
        }

        // O lote é deliberadamente pequeno: consentimento, uma mensagem útil e
        // teto diário são a proteção principal.
        @set_time_limit(0);
        @ignore_user_abort(true);
        $maxPerRun = min(10, WhatsappSendPolicy::remainingToday());

        $pendingMinutes = max(15, WhatsappSetting::getPendingMinutes());

        // IDs de usuários que já pagaram nas últimas 24 horas
        $paidUserIds = Payment::where('status', 'completed')
            ->where('paid_at', '>=', Carbon::now()->subHours(24))
            ->pluck('user_id')
            ->unique()
            ->toArray();

        // Buscar pagamentos pendentes do dia (excluindo quem já pagou)
        $pendingPayments = Payment::where('status', 'pending')
            ->where('created_at', '<=', Carbon::now()->subMinutes($pendingMinutes))
            ->whereDate('created_at', Carbon::today()) // Apenas pagamentos do dia
            ->whereNotIn('user_id', $paidUserIds) // Excluir quem já pagou
            ->whereHas('user', function($q) {
                $q->whereNotNull('phone')
                  ->where('phone', '!=', '');
            })
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('user_id'); // Apenas 1 por usuário

        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $batchCount = 0;

        foreach ($pendingPayments as $payment) {
            // Teto por execução (tentativas, inclusive falhas) para limitar o
            // contato iniciado pela empresa e respeitar o limite diário.
            if ($batchCount >= $maxPerRun) {
                break;
            }

            // 🛡️ Respeita descadastro (opt-out)
            if (WhatsappOptOut::isOptedOut($payment->user->phone)) {
                $skipped++;
                continue;
            }

            // Somente quem aceitou receber atualizações deste pagamento pode
            // receber contato iniciado pela empresa.
            if (! $payment->user->hasWhatsappPaymentOptIn()) {
                $skipped++;
                continue;
            }

            // Já recebeu mensagem (manual ou automática) nas últimas 24 horas?
            if ($this->remindedInLast24h($payment->user)) {
                $skipped++;
                continue;
            }

            // 🛡️ Ritmo humano: pausa aleatória entre envios (não fixa).
            if ($batchCount > 0) {
                WhatsappSendPolicy::pauseBetweenSends(3, 8);
            }
            $batchCount++;
            WhatsappSendPolicy::recordReminder();
            $this->lockReminder($payment, $payment->user);

            // Mesmo lembrete do automático (aviso + código PIX novo, ou só o
            // aviso com o link do portal se a cobrança não puder ser gerada).
            try {
                $result = app(UnpaidReminderSender::class)->send($payment->user, $payment, 30);
                $result['ok'] ? $sent++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
            }
        }

        return response()->json([
            'success' => true,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'total' => $pendingPayments->count(),
        ]);
    }

    /**
     * Reenviar mensagem com falha
     */
    public function resendMessage($id)
    {
        $message = WhatsappMessage::findOrFail($id);

        if (!WhatsappSetting::isConnected()) {
            return response()->json(['error' => 'WhatsApp não está conectado'], 400);
        }

        if ($message->user && (! $message->user->hasWhatsappPaymentOptIn() || WhatsappOptOut::isOptedOut($message->user->phone))) {
            return response()->json(['error' => 'O passageiro não autorizou ou cancelou atualizações pelo WhatsApp.'], 422);
        }

        try {
            $response = WhatsappClient::send($message->phone, $message->message, [], 30);

            if ($response->successful()) {
                $data = $response->json();
                $message->markAsSent($data['messageId'] ?? null);
                
                return response()->json([
                    'success' => true,
                    'message' => 'Mensagem reenviada com sucesso!'
                ]);
            }

            return response()->json(['error' => 'Falha ao reenviar mensagem'], 500);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Webhook para receber atualizações do servidor Baileys
     */
    public function webhook(Request $request)
    {
        $type = $request->input('type');
        $data = $request->input('data');
        // 🧩 Sessão de origem: "main" (PIX/transacional) ou "review" (avaliação).
        // Sem o campo (servidor antigo) assume "main" para manter compatibilidade.
        $session = $request->input('session', 'main');

        Log::info('WhatsApp Webhook', ['type' => $type, 'session' => $session, 'data' => $data]);

        switch ($type) {
            case 'connection':
                WhatsappSetting::updateConnectionStatusFor(
                    $session,
                    $data['status'] ?? 'disconnected',
                    $data['phone'] ?? null
                );
                break;

            case 'qr':
                WhatsappSetting::setQrCodeFor($session, $data['qrcode'] ?? null);
                WhatsappSetting::updateConnectionStatusFor($session, 'waiting_scan');
                break;

            case 'message_status':
                if (isset($data['messageId'])) {
                    $message = WhatsappMessage::where('message_id', $data['messageId'])->first();
                    if ($message) {
                        if ($data['status'] === 'delivered') {
                            $message->update([
                                'status' => 'delivered',
                                'delivered_at' => now()
                            ]);
                        } elseif ($data['status'] === 'read') {
                            $message->update([
                                'status' => 'read',
                                'read_at' => now()
                            ]);
                        }
                    }
                }
                break;

            case 'message_in':
                $phone = $data['phone'] ?? '';
                $messageText = $data['message'] ?? '';
                $lid = $data['lid'] ?? null;
                $pushName = $data['pushName'] ?? null;
                $timestamp = isset($data['timestamp']) ? (int) $data['timestamp'] : null;

                // 🛑 OPT-OUT: se a pessoa pediu "PARAR" (ou similar), registramos o
                // descadastro ANTES de qualquer outro processamento e confirmamos.
                // Isso vale para qualquer número/sessão e é o principal anti-denúncia.
                if ($messageText !== '' && \App\Models\WhatsappOptOut::isOptOutMessage($messageText)) {
                    try {
                        \App\Models\WhatsappOptOut::optOut($phone, 'keyword', mb_substr(trim($messageText), 0, 60));

                        WhatsappClient::send(
                            \App\Models\WhatsappMessage::formatPhone($phone),
                            "Pronto! ✅ Você não vai mais receber nossas mensagens automáticas.\n\nSe mudar de ideia, é só pagar uma nova viagem que voltamos a te avisar. 💚",
                            ['session' => $session, 'priority' => true],
                            15
                        );

                        Log::info('🛑 Opt-out registrado via WhatsApp', [
                            'phone' => $phone,
                            'session' => $session,
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('❌ Erro ao registrar opt-out', [
                            'phone' => $phone,
                            'error' => $e->getMessage(),
                        ]);
                    }
                    break; // não repassa pro bot de avaliação
                }

                if ($messageText !== '') {
                    try {
                        $handled = app(\App\Services\ServiceReviewBotService::class)
                            ->handleIncomingMessage($phone, $messageText, $lid, $pushName, $timestamp);
                        
                        Log::info('📩 WhatsApp mensagem recebida', [
                            'session' => $session,
                            'phone' => $phone,
                            'lid' => $lid,
                            'pushName' => $pushName,
                            'timestamp' => $timestamp,
                            'handled_by_review_bot' => $handled,
                            'message_preview' => mb_substr($messageText, 0, 80),
                        ]);
                    } catch (\Throwable $e) {
                        Log::error('❌ Erro ao processar mensagem recebida', [
                            'phone' => $phone,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
                break;
        }

        return response()->json(['success' => true]);
    }

    public function destroyPendingPayment(Payment $payment)
    {
        if (auth()->user()?->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem excluir pagamentos.');
        }

        if ($payment->status !== 'pending') {
            return back()->with('error', 'Só é possível excluir pagamentos pendentes.');
        }

        $payment->delete();

        return back()->with('success', "Pagamento #{$payment->id} excluído.");
    }

    public function destroyPendingPayments(Request $request)
    {
        if (auth()->user()?->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem excluir pagamentos.');
        }

        $validated = $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', 'exists:payments,id'],
        ]);

        $deleted = Payment::whereIn('id', $validated['payment_ids'])
            ->where('status', 'pending')
            ->delete();

        return back()->with('success', "{$deleted} pagamento(s) pendente(s) excluído(s).");
    }
}
