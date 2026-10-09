<?php

namespace App\Services;

use App\Models\TourismTrip;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pagamento do Turismo: um PIX PagBank com o valor total da viagem, pago
 * pelo responsável através de um link público. Fica totalmente separado da
 * tabela payments e do webhook do portal (tem webhook próprio).
 */
class TourismPaymentService
{
    /** reference_id dos pedidos do turismo: TUR{id da viagem}_{aleatório}. */
    private const REFERENCE_PATTERN = '/^TUR(\d+)_/';

    public function __construct(private PagBankPixService $pagbank)
    {
    }

    /** Cria o link (token) e o primeiro PIX. Lança RuntimeException se o PagBank falhar. */
    public function createLink(TourismTrip $trip): TourismTrip
    {
        if ($trip->isPaid()) {
            return $trip;
        }
        // Viagens antigas tinham "valor combinado" digitado: só cobra o valor calculado.
        if ($trip->price_24h === null || (float) $trip->agreed_amount <= 0) {
            throw new RuntimeException('Abra a viagem e salve de novo para o sistema calcular o valor total.');
        }

        $trip->payment_token ??= $this->newToken();
        $trip->payment_status = 'pending';
        $trip->save();

        return $this->ensurePix($trip);
    }

    /** Garante um PIX válido para o valor atual (gera outro se venceu ou o valor mudou). */
    public function ensurePix(TourismTrip $trip): TourismTrip
    {
        if ($trip->isPaid() || $trip->hasValidPix()) {
            return $trip;
        }

        $amount = round((float) $trip->agreed_amount, 2);
        $reference = 'TUR'.$trip->id.'_'.strtoupper(Str::random(8));
        $result = $this->pagbank->createPixPayment(
            // +0.001: o serviço converte com intval($valor * 100), que pode perder 1 centavo
            // por arredondamento (ex.: 1258.2 * 100 = 125819.99...). Assim sai o valor exato.
            $amount + 0.001,
            Str::limit('WiFi Turismo - '.$trip->title, 100, ''),
            $reference,
            $this->customer($trip),
            rtrim(config('app.url'), '/').'/api/tourism/webhook/pagbank'
        );

        if (! ($result['success'] ?? false) || empty($result['qr_code_text'])) {
            Log::error('Turismo: falha ao gerar PIX PagBank', ['trip_id' => $trip->id, 'message' => $result['message'] ?? null]);
            throw new RuntimeException('Não foi possível gerar o PIX no PagBank agora. Tente novamente em instantes.');
        }

        $expires = ! empty($result['expires_at']) ? Carbon::parse($result['expires_at']) : now()->addHours(23);
        $trip->forceFill([
            'pagbank_order_id' => $result['order_id'],
            'pagbank_reference_id' => $reference,
            'pix_code' => $result['qr_code_text'],
            'pix_amount' => $amount,
            // Margem de 5 min para ninguém pagar um QR prestes a vencer.
            'pix_expires_at' => $expires->subMinutes(5),
        ])->save();

        Log::info('Turismo: PIX gerado', ['trip_id' => $trip->id, 'order_id' => $result['order_id'], 'amount' => $amount]);

        return $trip;
    }

    /** Consulta o PagBank (no máximo a cada 10s por viagem) e marca como paga se foi. */
    public function refreshStatus(TourismTrip $trip): TourismTrip
    {
        if ($trip->isPaid() || ! $trip->pagbank_order_id || ! Cache::add("tourism_pix_check_{$trip->id}", 1, 10)) {
            return $trip;
        }

        $this->confirmIfPaid($trip, $trip->pagbank_order_id);

        return $trip->refresh();
    }

    /**
     * Webhook do PagBank. Não confia no corpo recebido: consulta o pedido na
     * API do PagBank antes de marcar a viagem como paga.
     */
    public function handleWebhook(array $data): bool
    {
        $orderId = $data['id'] ?? null;
        if (! $orderId || ! preg_match(self::REFERENCE_PATTERN, (string) ($data['reference_id'] ?? ''), $m)) {
            return false;
        }

        $trip = TourismTrip::find((int) $m[1]);

        return $trip ? $this->confirmIfPaid($trip, $orderId) : false;
    }

    private function confirmIfPaid(TourismTrip $trip, string $orderId): bool
    {
        $order = $this->pagbank->getOrderStatus($orderId);
        if (! ($order['success'] ?? false) || ($order['status'] ?? null) !== 'PAID') {
            return false;
        }

        // O pedido precisa ser desta viagem (qualquer PIX gerado para ela vale).
        if (! preg_match(self::REFERENCE_PATTERN, (string) ($order['reference_id'] ?? ''), $m) || (int) $m[1] !== $trip->id) {
            Log::warning('Turismo: pedido pago não pertence à viagem', ['trip_id' => $trip->id, 'order_id' => $orderId]);
            return false;
        }

        $amount = (float) ($order['amount'] ?? 0);

        return DB::transaction(function () use ($trip, $orderId, $order, $amount) {
            $trip = TourismTrip::whereKey($trip->id)->lockForUpdate()->firstOrFail();
            if ($trip->isPaid()) {
                return true;
            }

            $trip->forceFill([
                'payment_status' => 'paid',
                'paid_at' => ! empty($order['paid_at']) ? Carbon::parse($order['paid_at']) : now(),
                'paid_amount' => $amount,
                'pagbank_order_id' => $orderId,
            ])->save();

            Log::info('Turismo: pagamento confirmado', ['trip_id' => $trip->id, 'order_id' => $orderId, 'amount' => $amount]);
            if ($amount + 0.005 < (float) $trip->agreed_amount) {
                Log::warning('Turismo: valor pago menor que o total', ['trip_id' => $trip->id,
                    'paid' => $amount, 'total' => (float) $trip->agreed_amount]);
            }

            return true;
        });
    }

    private function customer(TourismTrip $trip): array
    {
        $customer = ['name' => Str::limit($trip->responsible_name ?: 'Cliente Turismo', 60, '')];

        $digits = preg_replace('/\D/', '', (string) $trip->responsible_phone);
        if (strlen($digits) >= 12 && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }
        if (in_array(strlen($digits), [10, 11], true)) {
            $customer['phone_area'] = substr($digits, 0, 2);
            $customer['phone_number'] = substr($digits, 2);
        }

        return $customer;
    }

    private function newToken(): string
    {
        do {
            $token = Str::random(32);
        } while (TourismTrip::where('payment_token', $token)->exists());

        return $token;
    }
}
