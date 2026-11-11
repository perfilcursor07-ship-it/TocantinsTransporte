<?php

namespace App\Services;

use App\Helpers\SettingsHelper;
use App\Models\Payment;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Gera uma cobrança PIX nova para acompanhar o lembrete de PIX não pago.
 *
 * O lembrete sai pelo menos 15 minutos depois do PIX original, então mandar o
 * código antigo é arriscado (pode ter vencido). Aqui nasce um pagamento novo,
 * igual ao original (mesmo passageiro, valor e plano), que o webhook e a
 * reconciliação confirmam como qualquer outro.
 *
 * Fica fora do fluxo de pagamento do portal de propósito: nada aqui é chamado
 * quando o passageiro paga pelo portal. Qualquer problema devolve null e quem
 * chama manda o lembrete antigo, sem código.
 */
class ReminderPixService
{
    private const DESCRIPTION = 'WiFi Tocantins Express - Internet Premium';

    public function createFreshCharge(Payment $original): ?Payment
    {
        $fresh = null;

        try {
            if ($original->status !== 'pending' || $original->payment_type !== 'pix' || ! $original->user_id) {
                return null;
            }

            // Plano de vários dias tem validação de datas na compra pelo portal;
            // não recriamos essa cobrança por aqui.
            if (IntervalPlanService::isInterval($original)) {
                return null;
            }

            $amount = (float) $original->amount;
            if ($amount <= 0) {
                return null;
            }

            $gateway = $this->supportedGateway();
            if (! $gateway) {
                return null;
            }

            $attributes = [
                'user_id' => $original->user_id,
                'amount' => $original->amount,
                'payment_type' => 'pix',
                'status' => 'pending',
                'transaction_id' => $this->transactionId(),
                'payment_data' => array_merge(
                    is_array($original->payment_data) ? $original->payment_data : [],
                    ['source' => 'whatsapp_reminder', 'reminder_of_payment_id' => $original->id]
                ),
                // Já nasce marcado: a cobrança do lembrete nunca gera outro lembrete.
                'unpaid_reminder_sent_at' => now(),
            ];
            if (filled($original->bus_mikrotik_id)) {
                $attributes['bus_mikrotik_id'] = $original->bus_mikrotik_id;
            }

            $fresh = Payment::create($attributes);

            // Mesmas chamadas e os mesmos campos que o portal grava, para o
            // webhook encontrar este pagamento do jeito que encontra os outros.
            if ($gateway === 'pagbank') {
                $qr = app(PagBankPixService::class)->createPixPayment($amount, self::DESCRIPTION, $fresh->transaction_id);
                $this->assertUsable($qr);
                $fresh->update([
                    'pix_emv_string' => $qr['qr_code_text'],
                    'pix_location' => $qr['reference_id'],
                    'gateway_payment_id' => $qr['order_id'],
                ]);
            } else {
                $qr = app(WooviPixService::class)->createPixPayment($amount, self::DESCRIPTION, $fresh->transaction_id);
                $this->assertUsable($qr);
                $fresh->update([
                    'pix_emv_string' => $qr['qr_code_text'],
                    'pix_location' => $qr['correlation_id'],
                    'gateway_payment_id' => $qr['woovi_id'],
                ]);
            }

            Log::info('💳 PIX novo gerado para o lembrete', [
                'payment_id' => $fresh->id,
                'original_payment_id' => $original->id,
                'user_id' => $original->user_id,
                'gateway' => $gateway,
            ]);

            return $fresh;
        } catch (\Throwable $e) {
            $this->discard($fresh);

            try {
                Log::warning('Lembrete segue sem código PIX: não foi possível gerar a cobrança nova', [
                    'original_payment_id' => $original->id,
                    'erro' => $e->getMessage(),
                ]);
            } catch (\Throwable) {
                // Nem falha de log pode impedir o lembrete simples de sair.
            }

            return null;
        }
    }

    /**
     * Apaga a cobrança do lembrete quando o código não chegou ao passageiro
     * (falha no gateway ou no envio). Ninguém tem como pagar por ela.
     */
    public function discard(?Payment $fresh): void
    {
        try {
            if ($fresh && $fresh->exists && $fresh->status === 'pending'
                && data_get($fresh->payment_data, 'source') === 'whatsapp_reminder') {
                $fresh->delete();
            }
        } catch (\Throwable) {
            // Sobra um pendente a mais na lista; não vale interromper o envio.
        }
    }

    /** Só os gateways em que este caminho grava exatamente o que o portal grava. */
    private function supportedGateway(): ?string
    {
        $gateway = SystemSetting::getValue('pix_gateway', config('wifi.payment_gateways.pix.gateway'));

        if ($gateway === 'pagbank' && SettingsHelper::getPagBankToken()) {
            return 'pagbank';
        }
        if ($gateway === 'woovi' && config('wifi.payment_gateways.pix.woovi_app_id')) {
            return 'woovi';
        }

        return null;
    }

    /** @param array<string,mixed> $qr */
    private function assertUsable(array $qr): void
    {
        if (! ($qr['success'] ?? false) || empty($qr['qr_code_text'])) {
            throw new RuntimeException($qr['message'] ?? 'O gateway não devolveu o código PIX.');
        }
    }

    /** Mesmo formato usado pelo portal (PaymentController::generateTransactionId). */
    private function transactionId(): string
    {
        return 'TXN_'.time().'_'.strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 12));
    }
}
