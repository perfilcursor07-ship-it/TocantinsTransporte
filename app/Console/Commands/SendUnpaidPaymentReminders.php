<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappOptOut;
use App\Models\WhatsappSetting;
use App\Services\UnpaidReminderSender;
use App\Services\WhatsappSendPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Lembrete automático para clientes que geraram QR Code PIX mas não pagaram.
 *
 * Fluxo:
 * 1. Busca pagamentos pendentes há pelo menos 15 minutos sem lembrete enviado
 * 2. Envia uma única atualização ao passageiro que autorizou esse contato
 * 3. Marca payment.unpaid_reminder_sent_at pra não enviar de novo
 *
 * IMPORTANTE: o PIX expira em poucos minutos, então este comando não envia
 * código PIX antigo. Ele orienta o passageiro a retornar ao portal para gerar
 * uma cobrança válida, se ainda desejar pagar.
 *
 * Esse comando NÃO afeta o fluxo de pagamento normal — só age depois de 15min
 * que o usuário gerou o QR Code e não pagou.
 */
class SendUnpaidPaymentReminders extends Command
{
    protected $signature = 'payments:send-unpaid-reminders';

    protected $description = 'Envia um lembrete WhatsApp autorizado para PIX pendente após 15 minutos';

    public function handle(): int
    {
        // Verificar se a feature está habilitada nas configs
        $enabled = \App\Models\SystemSetting::getValue('unpaid_reminder_enabled', '0');
        if (!$enabled) {
            $this->info('Lembretes de pagamento pendente desabilitados nas configurações.');
            $this->heartbeat('desativado nas configurações do sistema');
            return 0;
        }

        if (!WhatsappSetting::isConnected() || !WhatsappSetting::isAutoSendEnabled()) {
            $this->warn('Envio automático do WhatsApp está desabilitado ou desconectado. Pulando.');
            $this->heartbeat(WhatsappSetting::isConnected() ? 'envio automático desligado' : 'WhatsApp desconectado');
            return 0;
        }

        // 🛡️ Mensagem automática de madrugada é o que mais gera denúncia.
        // Não marca nada: se o PIX ainda estiver na janela quando o horário
        // liberar, o lembrete sai normalmente.
        if (WhatsappSendPolicy::isQuietTime()) {
            $this->info('Horário de silêncio (' . WhatsappSendPolicy::quietLabel() . '). Nenhum lembrete enviado.');
            $this->heartbeat('horário de silêncio (' . WhatsappSendPolicy::quietLabel() . ')');
            return 0;
        }

        // 🛡️ Teto diário de lembretes (automático + manual somados).
        if (WhatsappSendPolicy::remainingToday() === 0) {
            $this->info('Limite diário de lembretes atingido (' . WhatsappSendPolicy::dailyCap() . ').');
            $this->heartbeat('limite diário atingido (' . WhatsappSendPolicy::dailyCap() . ')');
            return 0;
        }

        // A interface administrativa não permite configurar menos de 15 min.
        // Um lembrete imediato tende a surpreender o passageiro e aumenta os
        // bloqueios/denúncias sem ajudar: o PIX original já pode ter expirado.
        $pendingMinutes = max(15, WhatsappSetting::getPendingMinutes());

        // Buscar pagamentos:
        // - Status pendente
        // - Criado há pelo menos 15 minutos
        // - Criado há menos de 6 horas (não enviar pra QRs muito antigos)
        // - Sem lembrete enviado ainda
        $payments = Payment::with('user')
            ->where('status', 'pending')
            ->whereNull('unpaid_reminder_sent_at')
            ->where('created_at', '<=', now()->subMinutes($pendingMinutes))
            ->where('created_at', '>=', now()->subHours(6))
            ->orderBy('created_at')
            ->limit(10) // Lote pequeno: somente notificações esperadas e autorizadas
            ->get();

        if ($payments->isEmpty()) {
            $this->info('Nenhum pagamento pendente para enviar lembrete.');
            $this->heartbeat('nenhum PIX pendente na janela');
            return 0;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($payments as $payment) {
            /** @var Payment $payment */
            $user = $payment->user;

            // Validações básicas
            if (!$user) {
                $this->markReminderSent($payment, 'usuário não encontrado');
                $skipped++;
                continue;
            }

            if (!$user->phone || strlen(preg_replace('/[^\d]/', '', $user->phone)) < 10) {
                $this->markReminderSent($payment, 'sem telefone válido');
                $skipped++;
                continue;
            }

            // A política do WhatsApp exige consentimento explícito antes de um
            // contato iniciado pela empresa. Usuários antigos, sem registro de
            // consentimento, não recebem disparo automático.
            if (!$user->hasWhatsappPaymentOptIn()) {
                $this->markReminderSent($payment, 'sem autorização explícita para atualizações de pagamento');
                $skipped++;
                continue;
            }

            // 🛡️ Respeita descadastro (opt-out): quem pediu "PARAR" não recebe mais lembretes.
            if (WhatsappOptOut::isOptedOut($user->phone)) {
                $this->markReminderSent($payment, 'telefone descadastrado (opt-out)');
                $skipped++;
                continue;
            }

            if (!$user->mac_address) {
                $this->markReminderSent($payment, 'sem MAC');
                $skipped++;
                continue;
            }

            // Não enviar se o usuário já tem acesso ativo (pagou outro pagamento)
            if (in_array($user->status, ['connected', 'active']) && $user->expires_at && $user->expires_at->isFuture()) {
                $this->markReminderSent($payment, 'usuário já tem acesso ativo');
                $skipped++;
                continue;
            }

            // Não enviar mais de uma vez por dia para o mesmo telefone
            $cacheKey = self::phoneLockKey($user->phone);
            if (Cache::has($cacheKey)) {
                $this->markReminderSent($payment, 'telefone já recebeu lembrete hoje');
                $skipped++;
                continue;
            }

            // 🛡️ Teto diário: o que sobrar fica para o próximo ciclo (sem marcar).
            if (WhatsappSendPolicy::remainingToday() === 0) {
                $this->info('Limite diário de lembretes atingido durante o lote.');
                break;
            }

            // 🛡️ Ritmo humano: pausa aleatória entre um envio e outro.
            if ($sent + $failed > 0) {
                WhatsappSendPolicy::pauseBetweenSends(3, 8);
            }

            // Um único lembrete objetivo. Nunca enviamos o código PIX porque,
            // neste momento, ele pode estar vencido e gerar mais frustração.
            $ok = $this->sendReminderMessage($user, $payment);
            WhatsappSendPolicy::recordReminder();

            // 🛡️ SEMPRE marca como enviado, mesmo se falhar — evita reenvio infinito
            // pro mesmo número (ex: número inválido tentando a cada 5 min).
            $this->markReminderSent($payment);

            // 🛡️ Trava diária SEMPRE gravada (mesmo com falha no envio): antes,
            // falha no WhatsApp fazia o cron reprocessar o telefone sem parar.
            Cache::put($cacheKey, true, now()->addHours(24));

            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }

        }

        $this->info("Lembretes: {$sent} enviados, {$skipped} pulados, {$failed} falharam.");
        $this->heartbeat("{$sent} enviados, {$skipped} pulados, {$failed} falharam");

        Log::info('💰 Lembretes de pagamento pendente processados', [
            'total' => $payments->count(),
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
        ]);

        return 0;
    }

    /**
     * Envia o lembrete: aviso + código PIX novo ou, se a cobrança nova não
     * puder ser gerada, só o aviso com o link do portal.
     */
    protected function sendReminderMessage(User $user, Payment $payment): bool
    {
        try {
            $result = app(UnpaidReminderSender::class)->send($user, $payment, 15);

            if (! $result['ok']) {
                return false;
            }

            Log::info('📱 Lembrete de pagamento pendente autorizado enviado', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'com_codigo_pix' => $result['with_code'],
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::error('❌ Erro ao enviar lembrete WhatsApp', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Monta o lembrete de um pagamento. Usado pelo envio automático e pelos
     * botões de /admin/whatsapp, para o passageiro receber sempre o mesmo
     * texto (com valor certo, link do portal e PARAR).
     */
    public static function buildReminderMessage(User $user, Payment $payment): string
    {
        $name = $user->name ? trim(explode(' ', $user->name)[0]) : null;
        $amount = number_format((float) $payment->amount, 2, ',', '.');
        $greeting = $name ? "Oi {$name}!" : 'Oi!';
        $portalUrl = rtrim(config('app.url'), '/') . '/';

        return self::reminderText($payment, $greeting, $amount, $portalUrl);
    }

    /** Chave da trava "um lembrete por telefone a cada 24h". */
    public static function phoneLockKey(?string $phone): string
    {
        return 'unpaid_reminder_phone_' . preg_replace('/[^\d]/', '', (string) $phone);
    }

    /**
     * Registra quando o comando rodou e o que aconteceu, para o painel
     * /admin/whatsapp avisar se o agendador parar. Nunca interrompe o envio.
     */
    protected function heartbeat(string $result): void
    {
        try {
            WhatsappSetting::set('reminder_last_run_at', now()->toDateTimeString());
            WhatsappSetting::set('reminder_last_run_result', $result);
        } catch (\Throwable $e) {
            // Painel é só informação: falha aqui não pode afetar os lembretes.
        }
    }

    /**
     * 🛡️ Texto idêntico para muitos números é sinal de disparo em massa.
     * Alterna 4 versões com o mesmo conteúdo: autorização, valor, como
     * pagar, "se já pagou, ignore", convite para responder e PARAR.
     */
    public static function reminderText(Payment $payment, string $greeting, string $amount, string $portalUrl): string
    {
        $variants = [
            "{$greeting}\n\n"
                . "Você autorizou receber atualizações deste pagamento. O PIX de *R\$ {$amount}* ainda não foi confirmado.\n\n"
                . "Se ainda quiser conectar à internet, abra o portal e gere um novo PIX:\n{$portalUrl}\n\n"
                . "Se você já pagou, ignore esta mensagem — a confirmação é automática.\n\n"
                . "Teve algum problema? Responda aqui.\n\n"
                . "_Para parar estas mensagens, responda *PARAR*._",
            "{$greeting} Aqui é o WiFi do ônibus da Tocantins Transporte.\n\n"
                . "Seu PIX de *R\$ {$amount}* não foi concluído, então a internet ainda não foi liberada.\n\n"
                . "Para conectar, é só abrir o portal no WiFi do ônibus e gerar um PIX novo:\n{$portalUrl}\n\n"
                . "Já pagou? Pode ignorar, a liberação é automática.\n\n"
                . "Se algo deu errado, me responda que eu ajudo.\n\n"
                . "_Não quer mais receber? Responda *PARAR*._",
            "{$greeting}\n\n"
                . "Vimos que o pagamento de *R\$ {$amount}* do WiFi não chegou a ser confirmado. Como você pediu aviso, estamos lembrando.\n\n"
                . "Ainda dá tempo: abra {$portalUrl} conectado ao WiFi do ônibus e gere um novo PIX.\n\n"
                . "Se já pagou, desconsidere.\n\n"
                . "Dúvida ou problema? Responda esta mensagem.\n\n"
                . "_Para não receber mais, responda *PARAR*._",
            "{$greeting}\n\n"
                . "Seu acesso ao WiFi do ônibus ficou pendente: o PIX de *R\$ {$amount}* não foi pago.\n\n"
                . "Quer navegar? Abra o portal no WiFi do ônibus e gere outro PIX:\n{$portalUrl}\n\n"
                . "Caso já tenha pago, é só ignorar.\n\n"
                . "Precisa de ajuda? Responda aqui.\n\n"
                . "_Responda *PARAR* para não receber mais._",
        ];

        return $variants[$payment->id % count($variants)];
    }

    /**
     * Marca o lembrete como enviado (ou pulado) para não tentar de novo
     */
    protected function markReminderSent(Payment $payment, ?string $reason = null): void
    {
        $payment->update(['unpaid_reminder_sent_at' => now()]);
    }
}
