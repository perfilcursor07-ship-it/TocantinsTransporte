<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\PagBankCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Pagamento com cartão de crédito no portal.
 *
 * Controller à parte para não encostar no PaymentController (PIX). O cartão
 * paga o MESMO pagamento pendente que o PIX criou: valor e plano vêm do banco,
 * nunca do navegador, e a liberação usa o mesmo activateUserAccess do webhook.
 */
class CardPaymentController extends Controller
{
    private const MAX_ATTEMPTS_PER_PAYMENT = 5;

    private const MAX_ATTEMPTS_PER_IP_HOUR = 12;

    /**
     * O que o portal precisa para montar o formulário do cartão.
     */
    public function config()
    {
        $mode = PagBankCardService::paymentMethods();

        if ($mode === 'pix') {
            return response()->json(['enabled' => false, 'mode' => 'pix']);
        }

        $service = new PagBankCardService;
        $publicKey = $service->getPublicKey();

        if (! $publicKey) {
            // Sem chave não há como criptografar: o portal segue só com PIX.
            return response()->json(['enabled' => false, 'mode' => 'pix']);
        }

        return response()->json([
            'enabled' => true,
            'mode' => $mode,
            'public_key' => $publicKey,
        ]);
    }

    public function charge(Request $request)
    {
        if (! PagBankCardService::cardEnabled()) {
            return response()->json(['success' => false, 'message' => 'Pagamento com cartão não está disponível.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|integer',
            'encrypted_card' => 'required|string|min:50|max:4000',
            'holder_name' => 'required|string|min:3|max:80',
            'cpf' => 'required|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Confira os dados do cartão.'], 422);
        }

        $cpf = preg_replace('/\D/', '', (string) $request->input('cpf'));
        if (! $this->validCpf($cpf)) {
            return response()->json(['success' => false, 'message' => 'CPF inválido.', 'field' => 'cpf'], 422);
        }

        $holderName = trim(preg_replace('/\s+/', ' ', (string) $request->input('holder_name')));
        $paymentId = (int) $request->input('payment_id');

        // Trava contra teste de cartão roubado: poucas tentativas por pagamento e por IP.
        $ipKey = 'card_attempts_ip_'.md5((string) $request->ip());
        $paymentKey = 'card_attempts_payment_'.$paymentId;
        if ((int) Cache::get($paymentKey, 0) >= self::MAX_ATTEMPTS_PER_PAYMENT
            || (int) Cache::get($ipKey, 0) >= self::MAX_ATTEMPTS_PER_IP_HOUR) {
            return response()->json([
                'success' => false,
                'message' => 'Muitas tentativas com cartão. Pague com PIX ou tente mais tarde.',
                'limit_reached' => true,
            ], 429);
        }

        // Um clique duplo não pode virar duas cobranças.
        $lock = Cache::lock('card_charge_'.$paymentId, 60);
        if (! $lock->get()) {
            return response()->json(['success' => false, 'message' => 'Pagamento em processamento. Aguarde.'], 409);
        }

        try {
            $payment = Payment::with('user')->find($paymentId);

            if (! $payment || ! $payment->user) {
                return response()->json(['success' => false, 'message' => 'Pagamento não encontrado.'], 404);
            }

            if ($payment->status === 'completed') {
                return response()->json(['success' => true, 'status' => 'PAID', 'already_paid' => true]);
            }

            if ($payment->status !== 'pending' || $payment->created_at < now()->subHours(2)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este pagamento expirou. Volte e gere um novo.',
                    'expired' => true,
                ], 409);
            }

            if (data_get($payment->payment_data, 'card_order_id') && data_get($payment->payment_data, 'card_status') === 'IN_ANALYSIS') {
                return response()->json([
                    'success' => true,
                    'status' => 'IN_ANALYSIS',
                    'message' => 'Seu cartão está em análise. A internet libera sozinha quando aprovar.',
                ]);
            }

            Cache::put($paymentKey, (int) Cache::get($paymentKey, 0) + 1, now()->addHours(2));
            Cache::put($ipKey, (int) Cache::get($ipKey, 0) + 1, now()->addHour());

            $result = (new PagBankCardService)->charge($payment, (string) $request->input('encrypted_card'), $holderName, $cpf);

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => ! empty($result['unknown'])
                        ? 'Não conseguimos confirmar a cobrança. Aguarde 1 minuto: se foi aprovada, a internet libera sozinha. Não tente de novo agora.'
                        : 'Não foi possível processar o cartão. Confira os dados ou pague com PIX.',
                ], 502);
            }

            $status = $result['status'] ?? 'UNKNOWN';

            if ($status === 'PAID') {
                $this->confirmPaid($payment->id, $result);

                return response()->json(['success' => true, 'status' => 'PAID']);
            }

            if (in_array($status, ['IN_ANALYSIS', 'AUTHORIZED', 'WAITING'], true)) {
                $this->rememberCardOrder($payment->id, $result, 'IN_ANALYSIS');

                return response()->json([
                    'success' => true,
                    'status' => 'IN_ANALYSIS',
                    'message' => 'Seu cartão está em análise. A internet libera sozinha quando aprovar.',
                ]);
            }

            return response()->json([
                'success' => false,
                'status' => $status,
                'declined' => true,
                'message' => 'Cartão recusado. Confira os dados, tente outro cartão ou pague com PIX.',
                'attempts_left' => max(0, self::MAX_ATTEMPTS_PER_PAYMENT - (int) Cache::get($paymentKey, 0)),
            ], 402);
        } catch (\Throwable $e) {
            Log::error('💳 Erro no pagamento com cartão', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'line' => $e->getLine(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar o cartão. Se a cobrança foi aprovada, a internet libera sozinha em instantes.',
            ], 500);
        } finally {
            $lock->release();
        }
    }

    /**
     * Mesmo desfecho do webhook do PagBank: marca pago e libera o acesso.
     */
    private function confirmPaid(int $paymentId, array $result): void
    {
        DB::transaction(function () use ($paymentId, $result) {
            $fresh = Payment::whereKey($paymentId)->lockForUpdate()->first();
            if (! $fresh) {
                return;
            }

            if (! in_array($fresh->status, ['pending', 'cancelled'], true)) {
                // Já estava pago. Se não foi o webhook deste mesmo pedido, o
                // passageiro pagou duas vezes (PIX + cartão): precisa de estorno manual.
                if (data_get($fresh->payment_data, 'id') !== ($result['order_id'] ?? null)) {
                    Log::error('🚨 CARTÃO COBRADO EM PAGAMENTO JÁ PAGO — estornar um dos dois', [
                        'payment_id' => $fresh->id,
                        'card_order_id' => $result['order_id'] ?? null,
                        'status' => $fresh->status,
                    ]);
                }

                return;
            }

            $fresh->update([
                'status' => 'completed',
                'payment_type' => 'card',
                'paid_at' => $result['paid_at'] ?? now(),
                'payment_data' => array_merge((array) $fresh->payment_data, [
                    'paid_with' => 'card',
                    'card_status' => 'PAID',
                    'card_order_id' => $result['order_id'] ?? null,
                    'card_charge_id' => $result['charge_id'] ?? null,
                    'card_brand' => $result['brand'] ?? null,
                    'card_last_digits' => $result['last_digits'] ?? null,
                ]),
            ]);

            app(PaymentController::class)->activateUserAccess($fresh);

            Log::info('✅ Pagamento com CARTÃO confirmado e acesso liberado', [
                'payment_id' => $fresh->id,
                'user_id' => $fresh->user_id,
                'card_order_id' => $result['order_id'] ?? null,
            ]);
        });
    }

    private function rememberCardOrder(int $paymentId, array $result, string $status): void
    {
        DB::transaction(function () use ($paymentId, $result, $status) {
            $fresh = Payment::whereKey($paymentId)->lockForUpdate()->first();
            if (! $fresh || $fresh->status !== 'pending') {
                return;
            }

            $fresh->update([
                'payment_data' => array_merge((array) $fresh->payment_data, [
                    'card_status' => $status,
                    'card_order_id' => $result['order_id'] ?? null,
                    'card_charge_id' => $result['charge_id'] ?? null,
                ]),
            ]);
        });
    }

    private function validCpf(string $cpf): bool
    {
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int) $cpf[$i] * (($t + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$t] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
