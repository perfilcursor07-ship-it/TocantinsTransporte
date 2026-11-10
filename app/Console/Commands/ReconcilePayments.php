<?php

namespace App\Console\Commands;

use App\Http\Controllers\PaymentController;
use App\Models\Payment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🔄 RECONCILIAÇÃO DE PAGAMENTOS
 *
 * Fecha as duas brechas de "pagou mas não liberou":
 *
 * 1. WEBHOOK PERDIDO: pagamento PagBank ficou 'pending' porque o webhook
 *    nunca chegou (queda de rede, deploy, retry esgotado). Consulta a API
 *    do PagBank diretamente e, se estiver PAID, confirma e ativa o acesso.
 *
 * 2. ATIVAÇÃO PERDIDA: pagamento ficou 'completed' mas o usuário não ficou
 *    'connected' com expires_at válido (exceção entre o update e a ativação).
 *    Reativa o usuário usando paid_at + duração do plano.
 *
 * Agendado a cada minuto em routes/console.php.
 */
class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile
                            {--hours=6 : Janela (horas) de pagamentos a verificar}
                            {--limit=40 : Máximo de consultas ao PagBank por execução}';

    protected $description = 'Reconcilia pagamentos: confirma PIX pago sem webhook e reativa acessos perdidos';

    public function handle(): int
    {
        $hours = max((int) $this->option('hours'), 1);
        $limit = max((int) $this->option('limit'), 1);

        $confirmed = $this->reconcilePendingPagBank($hours, $limit);
        $healed = $this->reconcileCompletedWithoutAccess($hours);
        $this->warnUnreconcilablePayments();

        $this->info("Reconciliação: {$confirmed} pagamento(s) confirmados via API, {$healed} acesso(s) reativados.");

        return self::SUCCESS;
    }

    /**
     * 1️⃣ Pagamentos PagBank 'pending' → consultar API e confirmar se PAID.
     */
    private function reconcilePendingPagBank(int $hours, int $limit): int
    {
        // 'cancelled' entra junto: o PIX antigo, cancelado quando o passageiro
        // gerou um novo, continua pagável no PagBank.
        $pendings = Payment::whereIn('status', ['pending', 'cancelled'])
            ->where('payment_type', 'pix')
            ->where('gateway_payment_id', 'like', 'ORDE_%')
            ->where('created_at', '>', now()->subHours($hours))
            ->where('created_at', '<', now()->subSeconds(90))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        if ($pendings->isEmpty()) {
            return 0;
        }

        $pagbank = new \App\Services\PagBankPixService;
        $confirmed = 0;

        foreach ($pendings as $payment) {
            // Backoff: pagamentos com mais de 15 min só são consultados a cada 5 min
            // (os primeiros 15 min são verificados em toda execução, que é quando
            // o pagamento real tende a acontecer).
            $isOld = $payment->created_at < now()->subMinutes(15);
            $backoffKey = "reconcile_checked_{$payment->id}";

            if ($isOld && Cache::has($backoffKey)) {
                continue;
            }

            if ($isOld) {
                Cache::put($backoffKey, true, now()->addMinutes(5));
            }

            try {
                $status = $pagbank->getOrderStatus($payment->gateway_payment_id);
            } catch (\Throwable $e) {
                Log::warning('🔄 Reconcile: falha ao consultar PagBank', [
                    'payment_id' => $payment->id,
                    'order_id' => $payment->gateway_payment_id,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            if (! ($status['success'] ?? false)) {
                continue;
            }

            if (($status['status'] ?? null) !== 'PAID') {
                continue;
            }

            // 🚨 Pagamento PAGO no PagBank mas 'pending' no banco = webhook perdido
            Log::warning('🔄 Reconcile: pagamento PAGO sem webhook detectado — confirmando agora', [
                'payment_id' => $payment->id,
                'order_id' => $payment->gateway_payment_id,
                'amount' => $payment->amount,
                'paid_at_gateway' => $status['paid_at'] ?? null,
            ]);

            try {
                DB::transaction(function () use ($payment, $status) {
                    $fresh = Payment::where('id', $payment->id)->lockForUpdate()->first();

                    if (! $fresh || ! in_array($fresh->status, ['pending', 'cancelled'], true)) {
                        return; // webhook chegou nesse meio tempo
                    }

                    $fresh->update([
                        'status' => 'completed',
                        'paid_at' => $status['paid_at'] ?? now(),
                        'payment_data' => array_merge((array) $fresh->payment_data, [
                            'reconciled_at' => now()->toISOString(),
                            'reconcile_reason' => 'Webhook não recebido - confirmado via consulta à API PagBank',
                        ]),
                    ]);

                    app(PaymentController::class)->activateUserAccess($fresh);
                });

                $confirmed++;
            } catch (\Throwable $e) {
                Log::error('🔄 Reconcile: erro ao confirmar pagamento', [
                    'payment_id' => $payment->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $confirmed;
    }

    /**
     * 2️⃣ Pagamentos 'completed' cujo usuário NÃO está liberado.
     * Reativa com expires_at = paid_at + duração do plano (não dá tempo extra).
     */
    private function reconcileCompletedWithoutAccess(int $hours): int
    {
        $payments = Payment::with('user')
            ->where('status', 'completed')
            ->where('paid_at', '>', now()->subHours($hours))
            ->orderBy('paid_at', 'desc')
            ->limit(200)
            ->get();

        $healed = 0;

        foreach ($payments as $payment) {
            $user = $payment->user;
            if (! $user) {
                continue;
            }
            // Bloqueado pelo admin no painel: não reativar sozinho.
            if (Cache::has('admin_blocked_user_'.$user->id)) {
                continue;
            }
            if (\App\Services\IntervalPlanService::isInterval($payment)) {
                app(\App\Services\IntervalPlanService::class)->activatePaidCheckout($payment);
                continue;
            }

            $hasAccess = in_array($user->status, ['connected', 'active', 'temp_bypass'])
                && $user->expires_at
                && $user->expires_at > now();

            if ($hasAccess) {
                continue;
            }

            // Calcular até quando o acesso DEVERIA valer
            $durationHours = max((float) data_get($payment->payment_data, 'duration_hours', \App\Helpers\SettingsHelper::getSessionDuration()), 0.1);
            $expectedExpiry = $payment->paid_at->copy()->addHours($durationHours);

            if ($expectedExpiry <= now()) {
                continue; // acesso expirou naturalmente, nada a fazer
            }

            $previousStatus = $user->status;

            $user->update([
                'status' => 'connected',
                'connected_at' => $user->connected_at ?: now(),
                'expires_at' => $expectedExpiry,
            ]);

            // Forçar o próximo sync dos MikroTiks a re-consultar o banco
            Cache::forget('mikrotik_sync_lists_all');

            $healed++;

            Log::warning('🔄 Reconcile: usuário pago sem acesso foi reativado', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'mac_address' => $user->mac_address,
                'previous_status' => $previousStatus,
                'expires_at' => $expectedExpiry->toISOString(),
            ]);

            if (! $user->mac_address) {
                Log::error('🔄 Reconcile: usuário pago SEM MAC — não será liberado no MikroTik. Oriente /reativar', [
                    'payment_id' => $payment->id,
                    'user_id' => $user->id,
                    'phone' => $user->phone,
                ]);
            }
        }

        return $healed;
    }

    /**
     * 3️⃣ Alerta sobre pagamentos no fallback EMV manual (sem gateway),
     * que NUNCA serão confirmados automaticamente.
     */
    private function warnUnreconcilablePayments(): void
    {
        $count = Payment::where('status', 'pending')
            ->where('payment_type', 'pix')
            ->whereNull('gateway_payment_id')
            ->whereNotNull('pix_emv_string')
            ->where('created_at', '>', now()->subHours(2))
            ->where('created_at', '<', now()->subMinutes(5))
            ->count();

        if ($count > 0) {
            Log::error("🚨 Reconcile: {$count} pagamento(s) PIX no fallback EMV manual (sem gateway). " .
                'Esses pagamentos NÃO são confirmados automaticamente — verifique a configuração do PagBank no painel admin.');
        }
    }
}
