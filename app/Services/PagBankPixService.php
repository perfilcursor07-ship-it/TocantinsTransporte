<?php

namespace App\Services;

use App\Helpers\SettingsHelper;
use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Serviço de integração PIX PagBank (PagSeguro)
 * 
 * Documentação oficial: https://dev.pagbank.uol.com.br
 * Protocolo: Bearer Token Authentication
 * Versão API: v1
 */
class PagBankPixService
{
    private $token;
    private $baseUrl;
    private $environment;

    public function __construct()
    {
        // Prioriza o token do banco de dados (configurado no painel admin)
        // Se não existir, usa o do .env como fallback
        $this->token = SettingsHelper::getPagBankToken() ?: config('wifi.payment_gateways.pix.pagbank_token');
        $this->environment = config('wifi.payment_gateways.pix.environment', 'sandbox');
        
        // URLs do PagBank
        $this->baseUrl = $this->environment === 'production' 
            ? 'https://api.pagseguro.com' 
            : 'https://sandbox.api.pagseguro.com';
    }

    /**
     * Criar pedido com QR Code PIX (Pagar com PagBank)
     * 
     * @param float $amount Valor em reais
     * @param string $description Descrição do item
     * @param string|null $referenceId ID único do pedido
     * @param array $customerData Dados do cliente (opcional)
     * @param string|null $notificationUrl Webhook (opcional; padrão = webhook do portal)
     * @return array
     */
    public function createPixPayment(float $amount, string $description, ?string $referenceId = null, array $customerData = [], ?string $notificationUrl = null): array
    {
        try {
            $referenceId = $referenceId ?: 'WIFI_' . time() . '_' . strtoupper(Str::random(8));
            
            // Valor em centavos para PagBank
            $amountCents = intval($amount * 100);

            Log::info('📲 Criando pedido PagBank com QR Code', [
                'reference_id' => $referenceId,
                'amount' => $amount,
                'amount_cents' => $amountCents,
                'description' => $description,
            ]);

            // Log detalhado para validação PagBank
            Log::channel('pagbank')->info('=== REQUEST: Criar Pedido PIX ===', [
                'timestamp' => now()->toISOString(),
                'endpoint' => $this->baseUrl . '/orders',
                'method' => 'POST',
                'environment' => $this->environment,
            ]);

            $payload = [
                'reference_id' => $referenceId,
                'customer' => [
                    'name' => $customerData['name'] ?? 'Cliente WiFi Tocantins',
                    'email' => $customerData['email'] ?? 'cliente.wifi@tocantinstransportewifi.com.br', // Email diferente do vendedor
                    'tax_id' => $customerData['tax_id'] ?? '12345678909',
                    'phones' => [
                        [
                            'country' => '55',
                            'area' => $customerData['phone_area'] ?? '63',
                            'number' => $customerData['phone_number'] ?? '999999999',
                            'type' => 'MOBILE'
                        ]
                    ]
                ],
                'items' => [
                    [
                        'reference_id' => $referenceId,
                        'name' => substr($description, 0, 100),
                        'quantity' => 1,
                        'unit_amount' => $amountCents
                    ]
                ],
                'qr_codes' => [
                    [
                        'amount' => [
                            'value' => $amountCents
                        ]
                        // Sem 'arrangements' = PIX padrão (aceita qualquer banco)
                        // Com 'arrangements' => ['PAGBANK'] = Apenas contas PagBank
                    ]
                ],
                'notification_urls' => [
                    $notificationUrl ?: config('app.url') . '/api/payment/webhook/pagbank'
                ]
            ];

            $http = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ]);

            // Desabilitar verificação SSL se configurado (útil em alguns ambientes)
            if (config('wifi.payment_gateways.pix.disable_ssl_verification', false)) {
                $http = $http->withOptions(['verify' => false]);
            }

            // Log do payload completo
            Log::channel('pagbank')->info('REQUEST PAYLOAD:', ['payload' => $payload]);

            $response = $http->post($this->baseUrl . '/orders', $payload);

            // Log da resposta completa
            Log::channel('pagbank')->info('RESPONSE:', [
                'status' => $response->status(),
                'headers' => $response->headers(),
                'body' => $response->json(),
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                Log::info('✅ Pedido PagBank criado com sucesso', [
                    'order_id' => $data['id'] ?? null,
                    'reference_id' => $referenceId,
                    'qr_codes_count' => count($data['qr_codes'] ?? []),
                ]);

                Log::channel('pagbank')->info('=== SUCESSO: Pedido Criado ===', [
                    'order_id' => $data['id'] ?? null,
                    'reference_id' => $referenceId,
                    'full_response' => $data,
                ]);

                $qrCode = $data['qr_codes'][0] ?? null;
                
                if (!$qrCode) {
                    throw new Exception('QR Code não retornado pela API PagBank');
                }

                return [
                    'success' => true,
                    'order_id' => $data['id'],
                    'reference_id' => $referenceId,
                    'qr_code_id' => $qrCode['id'] ?? null,
                    'qr_code_text' => $qrCode['text'] ?? null, // String EMV
                    'qr_code_image' => $this->generateQRCodeImageUrl($qrCode['text'] ?? ''),
                    'amount' => $amount,
                    'amount_cents' => $amountCents,
                    'status' => 'WAITING', // Status inicial
                    'expires_at' => $qrCode['expiration_date'] ?? null,
                    'links' => $qrCode['links'] ?? [],
                ];
            }

            $errorBody = $response->body();
            Log::error('❌ Erro ao criar pedido PagBank', [
                'status' => $response->status(),
                'body' => $errorBody,
                'reference_id' => $referenceId,
            ]);

            Log::channel('pagbank')->error('=== ERRO: Falha ao Criar Pedido ===', [
                'status' => $response->status(),
                'error_body' => $errorBody,
                'reference_id' => $referenceId,
            ]);

            throw new Exception('Erro na API PagBank: ' . $errorBody);

        } catch (Exception $e) {
            Log::error('❌ Erro ao criar pagamento PIX PagBank: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage(),
                'error_type' => get_class($e),
            ];
        }
    }

    /**
     * Consultar pedido
     * 
     * @param string $orderId ID do pedido PagBank
     * @return array
     */
    public function getOrderStatus(string $orderId): array
    {
        try {
            Log::info('🔍 Consultando pedido PagBank', ['order_id' => $orderId]);

            $http = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Accept' => 'application/json',
            ]);

            if (config('wifi.payment_gateways.pix.disable_ssl_verification', false)) {
                $http = $http->withOptions(['verify' => false]);
            }

            $response = $http->get($this->baseUrl . '/orders/' . $orderId);

            if ($response->successful()) {
                $data = $response->json();

                $charges = $data['charges'] ?? [];
                $latestCharge = !empty($charges) ? end($charges) : null;

                return [
                    'success' => true,
                    'order_id' => $data['id'],
                    'reference_id' => $data['reference_id'] ?? null,
                    'status' => $latestCharge['status'] ?? 'WAITING',
                    'amount' => isset($latestCharge['amount']['value']) ? ($latestCharge['amount']['value'] / 100) : null,
                    'paid_at' => $latestCharge['paid_at'] ?? null,
                    'payment_method' => $latestCharge['payment_method']['type'] ?? null,
                ];
            }

            throw new Exception('Erro ao consultar pedido: ' . $response->body());

        } catch (Exception $e) {
            Log::error('❌ Erro ao consultar pedido PagBank: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Processar webhook do PagBank
     * 
     * Documentação: https://dev.pagbank.uol.com.br/reference/webhook-status
     * 
     * Status possíveis:
     * - PAID: Cobrança paga (capturada)
     * - IN_ANALYSIS: PagBank analisando risco
     * - DECLINED: Negada pelo PagBank ou Emissor
     * - CANCELED: Cancelada
     * 
     * @param array $webhookData
     * @return array
     */
    public function processWebhook(array $webhookData): array
    {
        try {
            Log::info('📨 Processando webhook PagBank', [
                'data' => $webhookData,
            ]);

            // Log detalhado do webhook para validação
            Log::channel('pagbank')->info('=== WEBHOOK RECEBIDO ===', [
                'timestamp' => now()->toISOString(),
                'webhook_data' => $webhookData,
            ]);

            // Extrair informações do webhook
            $charges = $webhookData['charges'] ?? [];
            
            if (empty($charges)) {
                return [
                    'success' => true,
                    'payment_approved' => false,
                    'message' => 'Webhook sem informações de cobrança',
                ];
            }

            // Pegar a última cobrança (mais recente)
            $charge = is_array($charges) ? end($charges) : $charges;

            $status = $charge['status'] ?? 'WAITING';
            $referenceId = $webhookData['reference_id'] ?? null;

            Log::channel('pagbank')->info('Status da cobrança:', [
                'status' => $status,
                'reference_id' => $referenceId,
                'charge_details' => $charge,
            ]);

            // Só aprovar pagamento se status for PAID
            if ($status === 'PAID') {
                $amount = isset($charge['amount']['value']) ? ($charge['amount']['value'] / 100) : 0;

                return [
                    'success' => true,
                    'payment_approved' => true,
                    'reference_id' => $referenceId,
                    'order_id' => $webhookData['id'] ?? null,
                    'charge_id' => $charge['id'] ?? null,
                    'amount' => $amount,
                    'status' => $status,
                    'paid_at' => $charge['paid_at'] ?? now(),
                    'payment_method' => $charge['payment_method']['type'] ?? null,
                ];
            }

            // Outros status (IN_ANALYSIS, DECLINED, CANCELED)
            return [
                'success' => true,
                'payment_approved' => false,
                'reference_id' => $referenceId,
                'order_id' => $webhookData['id'] ?? null,
                'status' => $status,
                'message' => $this->getStatusMessage($status),
            ];

        } catch (Exception $e) {
            Log::error('❌ Erro ao processar webhook PagBank: ' . $e->getMessage());
            
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Obter mensagem amigável do status
     */
    private function getStatusMessage(string $status): string
    {
        return match($status) {
            'PAID' => 'Pagamento confirmado',
            'IN_ANALYSIS' => 'Pagamento em análise',
            'DECLINED' => 'Pagamento recusado',
            'CANCELED' => 'Pagamento cancelado',
            'WAITING' => 'Aguardando pagamento',
            default => 'Status desconhecido: ' . $status,
        };
    }

    /**
     * Cancelar pedido
     * 
     * @param string $orderId
     * @return array
     */
    public function cancelOrder(string $orderId): array
    {
        try {
            Log::info('🗑️ Cancelando pedido PagBank', ['order_id' => $orderId]);

            $http = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
            ]);

            if (config('wifi.payment_gateways.pix.disable_ssl_verification', false)) {
                $http = $http->withOptions(['verify' => false]);
            }

            $response = $http->post($this->baseUrl . '/orders/' . $orderId . '/cancel');

            if ($response->successful()) {
                Log::info('✅ Pedido cancelado com sucesso');
                
                return [
                    'success' => true,
                    'message' => 'Pedido cancelado com sucesso',
                ];
            }

            throw new Exception('Erro ao cancelar pedido: ' . $response->body());

        } catch (Exception $e) {
            Log::error('❌ Erro ao cancelar pedido PagBank: ' . $e->getMessage());

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Testar conectividade com PagBank
     */
    public function testConnection(): array
    {
        try {
            Log::info('🧪 Testando conexão com PagBank');

            // Criar um pedido de teste mínimo
            $testPayload = [
                'reference_id' => 'TEST_' . time(),
                'customer' => [
                    'name' => 'Teste PagBank',
                    'email' => 'cliente.teste@wifitocantins.com.br', // Email diferente do vendedor
                    'tax_id' => '12345678909',
                ],
                'items' => [
                    [
                        'reference_id' => 'ITEM_TEST',
                        'name' => 'Teste de conexao',
                        'quantity' => 1,
                        'unit_amount' => 100
                    ]
                ],
                'qr_codes' => [
                    [
                        'amount' => ['value' => 100]
                        // Sem arrangements = PIX padrão
                    ]
                ]
            ];

            $http = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
            ]);

            if (config('wifi.payment_gateways.pix.disable_ssl_verification', false)) {
                $http = $http->withOptions(['verify' => false]);
            }

            $response = $http->post($this->baseUrl . '/orders', $testPayload);

            if ($response->successful()) {
                $data = $response->json();
                
                // Cancelar pedido de teste
                if (isset($data['id'])) {
                    $this->cancelOrder($data['id']);
                }

                return [
                    'success' => true,
                    'message' => 'Conexão com PagBank estabelecida com sucesso',
                    'environment' => $this->environment,
                    'base_url' => $this->baseUrl,
                ];
            }

            $errorBody = $response->json();
            $errorMessage = $errorBody['error_messages'][0]['description'] ?? 'Erro desconhecido';

            throw new Exception($errorMessage);

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro na conexão: ' . $e->getMessage(),
                'environment' => $this->environment,
            ];
        }
    }

    /**
     * Gerar URL para imagem QR Code
     */
    public function generateQRCodeImageUrl(string $emvText): string
    {
        $size = '300x300';
        $encodedData = urlencode($emvText);
        
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}&data={$encodedData}";
    }
}

