<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Integração com o Cash Cash (envio de lead / handoff).
 *
 * REGRAS DO PARCEIRO (guia v1.0):
 *  - Chamadas SEMPRE servidor-a-servidor. Nunca pelo navegador do passageiro.
 *  - client_secret, access_token e a url do handoff NUNCA vão para log/analytics.
 *    O handoff_id pode e deve ser registrado.
 *  - O token é reaproveitado até perto de vencer (limite de 30 pedidos/minuto).
 *  - 401 no envio: pede token novo e repete UMA vez.
 *  - 400/403/422: não repetir.
 */
class CashCashService
{
    private const TOKEN_CACHE_KEY = 'cashcash:access_token';

    public function isEnabled(): bool
    {
        return (bool) config('services.cashcash.enabled')
            && (bool) config('services.cashcash.client_id')
            && (bool) config('services.cashcash.client_secret');
    }

    public function appUrl(): string
    {
        return (string) config('services.cashcash.app_url');
    }

    // ---------- Token ----------

    /**
     * Token de acesso, reaproveitado enquanto válido.
     * $force descarta o cache (usado quando o envio volta 401).
     */
    private function accessToken(bool $force = false): ?string
    {
        if (!$force) {
            $cached = Cache::get(self::TOKEN_CACHE_KEY);
            if ($cached) {
                return $cached;
            }
        }

        $response = Http::asForm()
            ->timeout((int) config('services.cashcash.token_timeout', 10))
            ->post(config('services.cashcash.api_url') . '/partner/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.cashcash.client_id'),
                'client_secret' => config('services.cashcash.client_secret'),
            ]);

        if (!$response->successful()) {
            // Log sem corpo: a resposta de erro pode ecoar credencial.
            Log::warning('🏦 Cash Cash: falha ao obter token', [
                'http_status' => $response->status(),
                'code' => $response->json('code'),
            ]);
            return null;
        }

        $token = $response->json('access_token');
        if (!$token) {
            Log::warning('🏦 Cash Cash: token ausente na resposta', [
                'http_status' => $response->status(),
            ]);
            return null;
        }

        // Renova um minuto antes de vencer, como o guia recomenda.
        $expiresIn = (int) ($response->json('expires_in') ?: 7200);
        $ttl = max(60, $expiresIn - 60);
        Cache::put(self::TOKEN_CACHE_KEY, $token, $ttl);

        return $token;
    }

    // ---------- Envio ----------

    /**
     * Cria o handoff e devolve o link para o passageiro.
     *
     * Retorno:
     *  ['ok' => true,  'handoff_id' => ..., 'url' => ..., 'expires_at' => ..., 'http_status' => 201]
     *  ['ok' => false, 'http_status' => ..., 'code' => ..., 'fields' => [...], 'retryable' => bool]
     *
     * A url volta para quem chamou redirecionar — NÃO pode ser logada nem persistida.
     */
    public function createHandoff(array $consent, string $cpf, bool $retried = false): array
    {
        $token = $this->accessToken($retried);
        if (!$token) {
            return [
                'ok' => false,
                'http_status' => 0,
                'code' => 'TOKEN_UNAVAILABLE',
                'retryable' => true,
            ];
        }

        try {
            $response = Http::withToken($token)
                ->timeout((int) config('services.cashcash.timeout', 20))
                ->acceptJson()
                ->post(config('services.cashcash.api_url') . '/partner/handoffs', [
                    'cpf' => $cpf,
                    'consent' => $consent,
                ]);
        } catch (\Throwable $e) {
            Log::warning('🏦 Cash Cash: erro de rede no envio', ['error' => $e->getMessage()]);
            return [
                'ok' => false,
                'http_status' => 0,
                'code' => 'NETWORK_ERROR',
                'retryable' => true,
            ];
        }

        // Token vencido/recusado: pede outro e repete UMA vez.
        if ($response->status() === 401 && !$retried) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            return $this->createHandoff($consent, $cpf, true);
        }

        if ($response->status() === 201) {
            $handoffId = $response->json('handoff_id');

            Log::info('🏦 Cash Cash: handoff criado', [
                'handoff_id' => $handoffId, // único campo da resposta que pode ir pro log
                'http_status' => 201,
            ]);

            return [
                'ok' => true,
                'http_status' => 201,
                'handoff_id' => $handoffId,
                'url' => $response->json('url'),
                'expires_at' => $response->json('expires_at'),
            ];
        }

        $code = $response->json('code');
        $status = $response->status();

        Log::warning('🏦 Cash Cash: envio recusado', [
            'http_status' => $status,
            'code' => $code,
            'fields' => $response->json('fields'),
        ]);

        return [
            'ok' => false,
            'http_status' => $status,
            'code' => $code,
            'fields' => $response->json('fields'),
            // 502/503 e 429: o guia manda repetir. 400/401/403/422: não.
            'retryable' => in_array($status, [429, 502, 503], true),
            'retry_after' => $response->header('Retry-After'),
        ];
    }

    // ---------- CPF ----------

    /**
     * Valida CPF pelos dígitos verificadores (o parceiro recusa CPF inválido).
     */
    public static function isValidCpf(?string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', (string) $cpf);

        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($position = 9; $position < 11; $position++) {
            $sum = 0;
            for ($i = 0; $i < $position; $i++) {
                $sum += (int) $cpf[$i] * (($position + 1) - $i);
            }
            $digit = ((10 * $sum) % 11) % 10;
            if ((int) $cpf[$position] !== $digit) {
                return false;
            }
        }

        return true;
    }
}
