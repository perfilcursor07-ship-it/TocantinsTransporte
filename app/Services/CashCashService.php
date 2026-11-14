<?php

namespace App\Services;

use App\Models\DiscountSponsor;
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

    /** Resultado do último pedido de token — só para diagnóstico (cashcash:testar). */
    private array $ultimoToken = [];

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

    /**
     * Endereço (só esquema + host) da página que o passageiro abre para ver a
     * proposta. É o que o portal testa do aparelho dele e o que precisa estar
     * liberado no MikroTik. Vale o do último link que o parceiro devolveu;
     * antes do primeiro, o do .env.
     */
    public function partnerOrigin(): ?string
    {
        try {
            $visto = Cache::get('cashcash_app_origin');
        } catch (\Throwable $e) {
            $visto = null;
        }

        return $visto ?: self::originOf($this->appUrl());
    }

    /** Guarda só o host do link devolvido — o link em si nunca é guardado. */
    public function rememberPartnerOrigin(?string $url): void
    {
        try {
            if ($origin = self::originOf($url)) {
                Cache::put('cashcash_app_origin', $origin, now()->addDays(30));
            }
        } catch (\Throwable $e) {
            // só diagnóstico
        }
    }

    public static function originOf(?string $url): ?string
    {
        $parts = parse_url((string) $url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Cadastro da Cash Cash em /admin/parceiros, quando é ele que está ativo.
     * Dele vêm a Base URL (homologação x produção) e o "Como abrir".
     * As credenciais continuam só no .env — o cadastro nunca guarda segredo.
     */
    private function sponsor(): ?DiscountSponsor
    {
        $sponsor = DiscountSponsor::active();

        return $sponsor
            && $sponsor->type === DiscountSponsor::TYPE_API
            && $sponsor->cfg('style') === 'cashcash'
                ? $sponsor
                : null;
    }

    /** Raiz da API: a do cadastro ativo; sem cadastro, a do .env. */
    public function apiUrl(): string
    {
        return rtrim((string) ($this->sponsor()?->cfg('base_url') ?: config('services.cashcash.api_url')), '/');
    }

    /**
     * Como o portal mostra a proposta:
     *  - 'redirect' (padrão): a mesma aba vai para o app do parceiro;
     *  - 'popup': o app abre numa janela/aba à parte e o portal fica aberto
     *     por trás, aplicando o desconto quando ela fecha;
     *  - 'iframe': dentro do portal — a Cash Cash bloqueia (X-Frame-Options: DENY).
     */
    public function openIn(): string
    {
        $modo = $this->sponsor()?->cfg('open_in');

        return in_array($modo, ['popup', 'iframe'], true) ? $modo : 'redirect';
    }

    /**
     * Um token por ambiente e credencial. Trocar homologação por produção não
     * pode reaproveitar o token antigo, que ainda vale por até 2h no cache.
     */
    private function tokenCacheKey(): string
    {
        return self::TOKEN_CACHE_KEY . ':' . md5($this->apiUrl() . '|' . config('services.cashcash.client_id'));
    }

    // ---------- Token ----------

    /**
     * Token de acesso, reaproveitado enquanto válido.
     * $force descarta o cache (usado quando o envio volta 401).
     */
    private function accessToken(bool $force = false): ?string
    {
        if (!$force) {
            $cached = Cache::get($this->tokenCacheKey());
            if ($cached) {
                return $cached;
            }
        }

        $response = Http::asForm()
            ->timeout((int) config('services.cashcash.token_timeout', 10))
            ->post($this->apiUrl() . '/partner/token', [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.cashcash.client_id'),
                'client_secret' => config('services.cashcash.client_secret'),
            ]);

        $this->ultimoToken = [
            'http_status' => $response->status(),
            'code' => $response->json('code'),
            'message' => $response->json('message'),
            'expires_in' => $response->json('expires_in'),
        ];

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
        Cache::put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    /**
     * Pede um token novo (ignora o cache) e diz se a Cash Cash aceitou o login.
     * Nunca devolve o token: só o código HTTP e o motivo, para diagnóstico.
     */
    public function testarToken(): array
    {
        $this->ultimoToken = [];

        try {
            $ok = (bool) $this->accessToken(true);
        } catch (\Throwable $e) {
            return ['ok' => false, 'http_status' => 0, 'code' => 'NETWORK_ERROR', 'message' => $e->getMessage()];
        }

        return ['ok' => $ok] + $this->ultimoToken + ['http_status' => 0, 'code' => null, 'message' => null, 'expires_in' => null];
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
                ->post($this->apiUrl() . '/partner/handoffs', [
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
            Cache::forget($this->tokenCacheKey());
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
