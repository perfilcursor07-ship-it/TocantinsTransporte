<?php

namespace App\Services;

use App\Helpers\SettingsHelper;
use App\Models\Payment;
use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cartão de crédito pelo PagBank (API de Pedidos, a mesma do PIX).
 *
 * Fica separado do PagBankPixService de propósito: nada aqui é chamado pelo
 * fluxo do PIX, então uma falha no cartão não tem como alcançar o PIX.
 *
 * O número do cartão nunca chega a este servidor: o navegador criptografa com
 * a chave pública (SDK do PagBank) e só o resultado criptografado passa aqui.
 */
class PagBankCardService
{
    private string $token;

    private string $baseUrl;

    public function __construct()
    {
        $this->token = (string) (SettingsHelper::getPagBankToken() ?: config('wifi.payment_gateways.pix.pagbank_token'));
        $this->baseUrl = config('wifi.payment_gateways.pix.environment', 'sandbox') === 'production'
            ? 'https://api.pagseguro.com'
            : 'https://sandbox.api.pagseguro.com';
    }

    /**
     * Quais formas de pagamento o portal oferece: 'pix', 'card' ou 'both'.
     * Qualquer valor estranho (ou erro) vira 'pix', que é o comportamento de sempre.
     */
    public static function paymentMethods(): string
    {
        try {
            $value = (string) SystemSetting::getValue('payment_methods', 'pix');

            return in_array($value, ['pix', 'card', 'both'], true) ? $value : 'pix';
        } catch (\Throwable $e) {
            return 'pix';
        }
    }

    public static function cardEnabled(): bool
    {
        return self::paymentMethods() !== 'pix';
    }

    public function isSandbox(): bool
    {
        return str_contains($this->baseUrl, 'sandbox');
    }

    /**
     * Chave pública usada pelo navegador para criptografar o cartão.
     * O PagBank devolve sempre a mesma chave para o mesmo token; guardamos 12h.
     */
    public function getPublicKey(): ?string
    {
        if ($this->token === '') {
            return null;
        }

        $cacheKey = 'pagbank_card_public_key_'.md5($this->token.$this->baseUrl);

        $cached = Cache::get($cacheKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->http()->post($this->baseUrl.'/public-keys', ['type' => 'card']);

            $key = $response->json('public_key');
            if ($response->successful() && is_string($key) && $key !== '') {
                Cache::put($cacheKey, $key, now()->addHours(12));

                return $key;
            }

            Log::error('💳 PagBank cartão: não foi possível obter a chave pública', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::error('💳 PagBank cartão: erro ao obter a chave pública', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Cria o pedido já com a cobrança no cartão (1x, captura automática).
     *
     * O reference_id é o transaction_id do pagamento: assim o webhook que já
     * existe (/api/payment/webhook/pagbank) encontra o mesmo pagamento caso a
     * confirmação chegue depois (cobrança em análise).
     *
     * @return array{success:bool,status?:string,order_id?:?string,charge_id?:?string,paid_at?:?string,brand?:?string,last_digits?:?string,code?:?string,message?:string}
     */
    public function charge(Payment $payment, string $encryptedCard, string $holderName, string $cpf): array
    {
        $user = $payment->user;
        $amountCents = (int) round(((float) $payment->amount) * 100);
        $planName = (string) data_get($payment->payment_data, 'plan_name', 'Internet no onibus');

        $customer = [
            'name' => $holderName,
            'email' => $this->customerEmail($user?->email),
            'tax_id' => $cpf,
        ];

        $phone = preg_replace('/\D/', '', (string) ($user?->phone ?? ''));
        if (str_starts_with($phone, '55') && strlen($phone) > 11) {
            $phone = substr($phone, 2);
        }
        if (strlen($phone) === 10 || strlen($phone) === 11) {
            $customer['phones'] = [[
                'country' => '55',
                'area' => substr($phone, 0, 2),
                'number' => substr($phone, 2),
                'type' => 'MOBILE',
            ]];
        }

        $payload = [
            'reference_id' => $payment->transaction_id,
            'customer' => $customer,
            'items' => [[
                'reference_id' => $payment->transaction_id,
                'name' => substr('WiFi Tocantins - '.$planName, 0, 100),
                'quantity' => 1,
                'unit_amount' => $amountCents,
            ]],
            'notification_urls' => [
                config('app.url').'/api/payment/webhook/pagbank',
            ],
            'charges' => [[
                'reference_id' => $payment->transaction_id,
                'description' => 'WiFi Tocantins Express',
                'amount' => ['value' => $amountCents, 'currency' => 'BRL'],
                'payment_method' => [
                    'type' => 'CREDIT_CARD',
                    'installments' => 1,
                    'capture' => true,
                    'card' => ['encrypted' => $encryptedCard, 'store' => false],
                    'holder' => ['name' => $holderName, 'tax_id' => $cpf],
                ],
            ]],
        ];

        try {
            $response = $this->http()->timeout(40)->post($this->baseUrl.'/orders', $payload);
            $data = (array) $response->json();

            if (! $response->successful()) {
                $description = (string) data_get($data, 'error_messages.0.description', '');

                Log::warning('💳 PagBank cartão: pedido recusado pela API', [
                    'payment_id' => $payment->id,
                    'status' => $response->status(),
                    'errors' => data_get($data, 'error_messages'),
                ]);

                return [
                    'success' => false,
                    'message' => $description !== '' ? $description : 'Erro ao processar o cartão.',
                ];
            }

            $charges = (array) ($data['charges'] ?? []);
            $charge = (array) (end($charges) ?: []);
            $status = (string) ($charge['status'] ?? 'UNKNOWN');

            Log::info('💳 PagBank cartão: resposta da cobrança', [
                'payment_id' => $payment->id,
                'order_id' => $data['id'] ?? null,
                'charge_status' => $status,
                'response_code' => data_get($charge, 'payment_response.code'),
                'response_message' => data_get($charge, 'payment_response.message'),
            ]);

            return [
                'success' => true,
                'status' => $status,
                'order_id' => $data['id'] ?? null,
                'charge_id' => $charge['id'] ?? null,
                'paid_at' => $charge['paid_at'] ?? null,
                'brand' => data_get($charge, 'payment_method.card.brand'),
                'last_digits' => data_get($charge, 'payment_method.card.last_digits'),
                'code' => data_get($charge, 'payment_response.code'),
                'message' => (string) data_get($charge, 'payment_response.message', ''),
            ];
        } catch (\Throwable $e) {
            // Timeout ou queda: não sabemos se cobrou. O webhook confirma depois, se cobrou.
            Log::error('💳 PagBank cartão: falha na chamada', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Não foi possível falar com a operadora do cartão.', 'unknown' => true];
        }
    }

    private function customerEmail(?string $email): string
    {
        $fallback = 'cliente.wifi@tocantinstransportewifi.com.br';
        $email = trim((string) $email);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $fallback;
        }

        // O PagBank recusa pedido em que o comprador tem o e-mail do vendedor.
        if (strcasecmp($email, (string) SettingsHelper::getPagBankEmail()) === 0) {
            return $fallback;
        }

        return $email;
    }

    private function http()
    {
        $http = Http::withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ])->timeout(15);

        if (config('wifi.payment_gateways.pix.disable_ssl_verification', false)) {
            $http = $http->withOptions(['verify' => false]);
        }

        return $http;
    }
}
