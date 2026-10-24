<?php

namespace App\Services\DiscountSponsors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Patrocinador de API (handoff): nosso servidor chama a API do parceiro,
 * recebe um link personalizado e leva o passageiro até lá.
 *
 * config:
 *   base_url          — raiz da API do parceiro
 *   token_path        — padrão /token         (Cash Cash: /partner/token)
 *   session_path      — padrão /sessions      (Cash Cash: /partner/handoffs)
 *   style             — 'standard' (nossa spec) ou 'cashcash'
 *   client_id_env     — NOME da variável no .env (ex.: CASHCASH_CLIENT_ID)
 *   client_secret_env — NOME da variável no .env (ex.: CASHCASH_CLIENT_SECRET)
 *   open_in           — 'redirect' (padrão) ou 'iframe', se o parceiro permitir embed
 *   timeout           — segundos (padrão 20)
 *
 * SEGREDOS NUNCA FICAM NO BANCO: o config guarda só o nome da variável.
 * O token e a url do handoff também nunca vão para log.
 */
class ApiHandoffDriver extends BaseDriver
{
    public function isReady(): bool
    {
        return (bool) $this->sponsor->cfg('base_url')
            && (bool) $this->credencial('client_id_env');
    }

    public function offer(): array
    {
        return array_merge(parent::offer(), [
            'open_in' => $this->sponsor->cfg('open_in', 'redirect'),
        ]);
    }

    public function start(Request $request, array $input): array
    {
        $cpf = isset($input['cpf']) ? preg_replace('/\D/', '', $input['cpf']) : null;

        if ($this->sponsor->collects_cpf) {
            if (!self::cpfValido($cpf)) {
                return ['ok' => false, 'reason' => 'invalid_cpf',
                        'message' => 'CPF inválido. Confira os números e tente de novo.'];
            }
        }

        $consent = [
            'accepted_at' => now()->toIso8601ZuluString('millisecond'),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
        ];

        // Localização só é montada quando o parceiro pede (ex.: Cash Cash).
        if ($this->sponsor->cfg('requires_location', false)) {
            $local = $input['location'] ?? null;
            if (!$local) {
                return ['ok' => false, 'reason' => 'no_location',
                        'message' => 'Não conseguimos validar sua localização. Siga com o pagamento normal.'];
            }
            $consent['latitude'] = (float) $local['latitude'];
            $consent['longitude'] = (float) $local['longitude'];
            $consent['location_source'] = $local['source'];
        }

        $lead = $this->createLead($request, $input, [
            'cpf' => $cpf,
            'consent_accepted_at' => now(),
            'consent_ip' => $consent['ip_address'],
            'consent_user_agent' => $consent['user_agent'],
            'consent_latitude' => $consent['latitude'] ?? null,
            'consent_longitude' => $consent['longitude'] ?? null,
            'consent_location_source' => $consent['location_source'] ?? null,
            'status' => \App\Models\DiscountLead::STATUS_PENDING,
        ]);

        $resultado = $this->criarSessao($consent, $cpf);

        if (!$resultado['ok']) {
            $lead->update([
                'status' => \App\Models\DiscountLead::STATUS_FAILED,
                'http_status' => $resultado['http_status'],
                'error_code' => $resultado['code'] ?? null,
            ]);

            return ['ok' => false, 'reason' => $resultado['code'] ?? 'handoff_failed',
                    'message' => $this->mensagemFalha($resultado)];
        }

        $lead->update([
            'status' => \App\Models\DiscountLead::STATUS_CREATED,
            'handoff_id' => $resultado['session_id'],
            'http_status' => 201,
            'link_opened_at' => now(),
            'link_expires_at' => $resultado['expires_at']
                ? \Illuminate\Support\Carbon::parse($resultado['expires_at']) : null,
        ]);

        return [
            'ok' => true,
            'lead_id' => $lead->id,
            'mode' => $this->sponsor->cfg('open_in', 'redirect'),
            'url' => $resultado['url'],   // nunca logada
            'min_seconds' => $this->sponsor->minSeconds(),
        ];
    }

    // ---------- HTTP ----------

    private function criarSessao(array $consent, ?string $cpf, bool $repetiu = false): array
    {
        $token = $this->token($repetiu);
        if (!$token) {
            return ['ok' => false, 'http_status' => 0, 'code' => 'TOKEN_UNAVAILABLE'];
        }

        $payload = $this->sponsor->cfg('style') === 'cashcash'
            ? ['cpf' => $cpf, 'consent' => $consent]
            : ['external_id' => (string) \Illuminate\Support\Str::uuid(),
               'document' => $cpf,
               'consent' => $consent];

        try {
            $resp = Http::withToken($token)
                ->timeout((int) $this->sponsor->cfg('timeout', 20))
                ->acceptJson()
                ->post($this->url('session_path', '/sessions'), $payload);
        } catch (\Throwable $e) {
            Log::warning('🤝 Patrocinador: erro de rede', [
                'sponsor' => $this->sponsor->slug, 'error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'http_status' => 0, 'code' => 'NETWORK_ERROR'];
        }

        if ($resp->status() === 401 && !$repetiu) {
            Cache::forget($this->chaveToken());
            return $this->criarSessao($consent, $cpf, true);
        }

        if (in_array($resp->status(), [200, 201], true)) {
            $id = $resp->json('session_id') ?? $resp->json('handoff_id');

            Log::info('🤝 Patrocinador: sessão criada', [
                'sponsor' => $this->sponsor->slug,
                'session_id' => $id,   // único campo da resposta que pode ir pro log
            ]);

            return ['ok' => true, 'session_id' => $id,
                    'url' => $resp->json('url'), 'expires_at' => $resp->json('expires_at')];
        }

        Log::warning('🤝 Patrocinador: sessão recusada', [
            'sponsor' => $this->sponsor->slug,
            'http_status' => $resp->status(),
            'code' => $resp->json('code'),
        ]);

        return ['ok' => false, 'http_status' => $resp->status(), 'code' => $resp->json('code')];
    }

    private function token(bool $forcar = false): ?string
    {
        if (!$forcar && ($cache = Cache::get($this->chaveToken()))) {
            return $cache;
        }

        $resp = Http::asForm()
            ->timeout((int) $this->sponsor->cfg('token_timeout', 10))
            ->post($this->url('token_path', '/token'), [
                'grant_type' => 'client_credentials',
                'client_id' => $this->credencial('client_id_env'),
                'client_secret' => $this->credencial('client_secret_env'),
            ]);

        if (!$resp->successful()) {
            // Sem corpo no log: a resposta de erro pode ecoar credencial.
            Log::warning('🤝 Patrocinador: falha ao obter token', [
                'sponsor' => $this->sponsor->slug, 'http_status' => $resp->status(),
            ]);
            return null;
        }

        $token = $resp->json('access_token');
        if (!$token) return null;

        $validade = (int) ($resp->json('expires_in') ?: 3600);
        Cache::put($this->chaveToken(), $token, max(60, $validade - 60));

        return $token;
    }

    private function chaveToken(): string
    {
        return 'sponsor_token:' . $this->sponsor->slug;
    }

    private function url(string $key, string $padrao): string
    {
        return rtrim((string) $this->sponsor->cfg('base_url'), '/')
            . '/' . ltrim((string) $this->sponsor->cfg($key, $padrao), '/');
    }

    /** Lê a credencial do .env pelo nome guardado no config. */
    private function credencial(string $key): ?string
    {
        $nome = $this->sponsor->cfg($key);
        return $nome ? env($nome) : null;
    }

    private function mensagemFalha(array $r): string
    {
        return match ($r['code'] ?? null) {
            'CPF_UNAVAILABLE' => 'Não há proposta disponível para este CPF agora. Siga com o pagamento normal.',
            'RATE_LIMITED' => 'Muita gente pedindo ao mesmo tempo. Tente de novo em instantes.',
            'VALIDATION_ERROR' => 'Não conseguimos validar seus dados. Confira e tente de novo.',
            default => 'O conteúdo do parceiro está indisponível. Toque em "Pular e pagar normal" para seguir.',
        };
    }

    /** CPF pelos dígitos verificadores. */
    public static function cpfValido(?string $cpf): bool
    {
        $cpf = preg_replace('/\D/', '', (string) $cpf);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;

        for ($pos = 9; $pos < 11; $pos++) {
            $soma = 0;
            for ($i = 0; $i < $pos; $i++) $soma += (int) $cpf[$i] * (($pos + 1) - $i);
            if ((int) $cpf[$pos] !== ((10 * $soma) % 11) % 10) return false;
        }
        return true;
    }
}
