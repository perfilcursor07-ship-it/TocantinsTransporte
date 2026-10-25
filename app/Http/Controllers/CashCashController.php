<?php

namespace App\Http\Controllers;

use App\Helpers\SettingsHelper;
use App\Models\Bus;
use App\Models\DiscountLead;
use App\Services\CashCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Proposta Cash Cash exibida no lugar do vídeo de desconto.
 *
 * Fluxo:
 *  1. handoff()  — passageiro confirma telefone, informa CPF e consente.
 *                  Criamos o lead, chamamos o parceiro e devolvemos o link.
 *  2. confirm()  — passageiro volta do app. Se ficou o tempo mínimo fora,
 *                  o desconto é concedido e gravado no lead.
 *
 * Nada aqui toca em cobrança, pagamento ou liberação de acesso.
 */
class CashCashController extends Controller
{
    public function __construct(private CashCashService $cashCash)
    {
    }

    /**
     * Cria o envio e devolve o link do app.
     * A url NÃO é gravada em banco nem em log (regra do parceiro).
     */
    public function handoff(Request $request)
    {
        if (!$this->cashCash->isEnabled()) {
            return response()->json([
                'success' => false,
                'reason' => 'disabled',
                'message' => 'Oferta indisponível no momento.',
            ], 503);
        }

        $validated = $request->validate([
            'cpf' => 'required|string|max:20',
            'phone' => 'nullable|string|max:20',
            'mac_address' => 'nullable|string|max:32',
            'user_id' => 'nullable|integer',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
        ]);

        $cpf = preg_replace('/\D/', '', $validated['cpf']);

        if (!CashCashService::isValidCpf($cpf)) {
            return response()->json([
                'success' => false,
                'reason' => 'invalid_cpf',
                'message' => 'CPF inválido. Confira os números e tente de novo.',
            ], 422);
        }

        $ip = $request->ip();
        $userAgent = mb_substr((string) $request->userAgent(), 0, 1024);
        $location = $this->resolveLocation($request, $validated);

        if (!$location) {
            Log::warning('🏦 Cash Cash: sem localização para montar o consentimento', ['ip' => $ip]);
            return response()->json([
                'success' => false,
                'reason' => 'no_location',
                'message' => 'Não conseguimos validar sua localização agora. Siga com o pagamento normal.',
            ], 422);
        }

        // accepted_at é AGORA: o parceiro recusa aceites com mais de 15 minutos.
        $acceptedAt = now();

        $lead = DiscountLead::create([
            'user_id' => $validated['user_id'] ?? null,
            'cpf' => $cpf,
            'phone' => $validated['phone'] ?? null,
            'mac_address' => strtoupper(trim((string) ($validated['mac_address'] ?? ''))) ?: null,
            'consent_accepted_at' => $acceptedAt,
            'consent_ip' => $ip,
            'consent_user_agent' => $userAgent,
            'consent_latitude' => $location['latitude'],
            'consent_longitude' => $location['longitude'],
            'consent_location_source' => $location['source'],
            'status' => DiscountLead::STATUS_PENDING,
        ]);

        $result = $this->cashCash->createHandoff([
            'accepted_at' => $acceptedAt->toIso8601ZuluString('millisecond'),
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'latitude' => (float) $location['latitude'],
            'longitude' => (float) $location['longitude'],
            'location_source' => $location['source'],
        ], $cpf);

        if (!$result['ok']) {
            $lead->update([
                'status' => DiscountLead::STATUS_FAILED,
                'http_status' => $result['http_status'],
                'error_code' => $result['code'] ?? null,
            ]);

            // Plano B do guia: só em 502/503, e só se ligado na config.
            // Sem handoff o app abre na tela inicial com o CPF em branco, então
            // por padrão preferimos manter o passageiro no portal.
            $fallbackAllowed = config('services.cashcash.fallback_to_app')
                && in_array($result['http_status'], [502, 503], true);

            return response()->json([
                'success' => false,
                'reason' => $result['code'] ?? 'handoff_failed',
                'fallback_url' => $fallbackAllowed ? $this->cashCash->appUrl() . '/' : null,
                'message' => $this->messageForFailure($result),
            ], 422);
        }

        $lead->update([
            'status' => DiscountLead::STATUS_CREATED,
            'handoff_id' => $result['handoff_id'],
            'http_status' => 201,
            'link_expires_at' => $result['expires_at'] ? \Illuminate\Support\Carbon::parse($result['expires_at']) : null,
            'link_opened_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
            'handoff_id' => $result['handoff_id'],
            'url' => $result['url'], // entregue ao navegador para o redirect — não logar
            'min_seconds' => SettingsHelper::getCashCashMinSeconds(),
            'discount_amount' => SettingsHelper::getVideoDiscountAmount(),
        ]);
    }

    /**
     * Inicia a exposição ao anúncio SEM nenhum dado pessoal.
     *
     * Esta é a rota que torna o desconto independente do CPF. O passageiro
     * ganha o desconto por ver o anúncio pelo tempo mínimo — informar CPF é
     * um passo separado e opcional, que não altera o preço.
     *
     * Condicionar desconto à entrega de CPF caracteriza consentimento não
     * livre (LGPD art. 5º, XII) e venda casada (CDC art. 39, I). Por isso
     * este caminho precisa existir sempre, para qualquer patrocinador.
     */
    public function exposure(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'nullable|string|max:20',
            'mac_address' => 'nullable|string|max:32',
            'user_id' => 'nullable|integer',
        ]);

        $mac = strtoupper(trim((string) ($validated['mac_address'] ?? ''))) ?: null;

        // Já tem exposição em andamento para este aparelho? Reaproveita, para o
        // contador não reiniciar se a página recarregar.
        $lead = $mac ? DiscountLead::pendingReturnFor($mac) : null;

        if (!$lead) {
            $lead = DiscountLead::create([
                'user_id' => $validated['user_id'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'mac_address' => $mac,
                'cpf' => null,                 // nenhum dado pessoal nesta via
                'status' => DiscountLead::STATUS_CREATED,
                'link_opened_at' => now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
            'min_seconds' => SettingsHelper::getCashCashMinSeconds(),
            'discount_amount' => SettingsHelper::getVideoDiscountAmount(),
        ]);
    }

    /**
     * Passageiro voltou do app. Concede o desconto se ficou o tempo mínimo.
     */
    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'lead_id' => 'required|integer',
        ]);

        $lead = DiscountLead::find($validated['lead_id']);

        if (!$lead || $lead->status !== DiscountLead::STATUS_CREATED) {
            return response()->json([
                'success' => false,
                'reason' => 'not_found',
                'message' => 'Não encontramos sua proposta. Tente de novo.',
            ], 404);
        }

        if ($lead->discount_granted_at) {
            return response()->json([
                'success' => true,
                'granted' => true,
                'discount_amount' => (float) $lead->discount_amount,
            ]);
        }

        $minSeconds = SettingsHelper::getCashCashMinSeconds();

        // Tempo medido no servidor, entre a abertura da proposta e a volta ao portal.
        // O navegador não participa da conta — não dá para forjar pelo console.
        $dwell = $lead->link_opened_at
            ? (int) $lead->link_opened_at->diffInSeconds(now())
            : 0;

        $lead->update([
            'returned_at' => now(),
            'dwell_seconds' => $dwell,
        ]);

        if ($dwell < $minSeconds) {
            return response()->json([
                'success' => true,
                'granted' => false,
                'min_seconds' => $minSeconds,
                'dwell_seconds' => $dwell,
                'remaining_seconds' => $minSeconds - $dwell,
                'message' => 'Você voltou antes de ver a proposta. Abra de novo para ganhar o desconto.',
            ]);
        }

        $amount = SettingsHelper::getVideoDiscountAmount();

        $lead->update([
            'discount_granted_at' => now(),
            'discount_amount' => $amount,
        ]);

        Log::info('🏦 Cash Cash: desconto concedido', [
            'handoff_id' => $lead->handoff_id,
            'dwell_seconds' => $dwell,
        ]);

        return response()->json([
            'success' => true,
            'granted' => true,
            'discount_amount' => $amount,
        ]);
    }

    /**
     * Reabre a proposta de um lead existente.
     *
     * O link do parceiro vale 5 minutos e funciona uma única vez, então reabrir
     * exige um handoff novo. O guia garante que repetir é seguro: o mesmo CPF
     * no mesmo dia reaproveita a análise e devolve só um link novo.
     */
    public function reopen(Request $request)
    {
        if (!$this->cashCash->isEnabled()) {
            return response()->json(['success' => false, 'reason' => 'disabled'], 503);
        }

        $validated = $request->validate(['lead_id' => 'required|integer']);

        $lead = DiscountLead::find($validated['lead_id']);
        if (!$lead || $lead->discount_granted_at) {
            return response()->json(['success' => false, 'reason' => 'not_found'], 404);
        }

        $acceptedAt = now();

        $result = $this->cashCash->createHandoff([
            'accepted_at' => $acceptedAt->toIso8601ZuluString('millisecond'),
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 1024),
            'latitude' => (float) $lead->consent_latitude,
            'longitude' => (float) $lead->consent_longitude,
            'location_source' => $lead->consent_location_source,
        ], $lead->cpf);

        if (!$result['ok']) {
            return response()->json([
                'success' => false,
                'reason' => $result['code'] ?? 'handoff_failed',
                'message' => $this->messageForFailure($result),
            ], 422);
        }

        // Reinicia a contagem: é uma nova visita à proposta.
        $lead->update([
            'handoff_id' => $result['handoff_id'],
            'consent_accepted_at' => $acceptedAt,
            'link_opened_at' => now(),
            'returned_at' => null,
            'dwell_seconds' => null,
            'link_expires_at' => $result['expires_at'] ? \Illuminate\Support\Carbon::parse($result['expires_at']) : null,
        ]);

        return response()->json([
            'success' => true,
            'lead_id' => $lead->id,
            'url' => $result['url'],
            'min_seconds' => SettingsHelper::getCashCashMinSeconds(),
        ]);
    }

    // ---------- Apoio ----------

    /**
     * Localização do passageiro para a evidência do consentimento.
     *
     * O parceiro exige latitude/longitude. Preferimos o GPS do aparelho
     * (funciona sem internet); sem ele, estimamos pelo IP público — que é
     * o que o próprio contrato chama de location_source "ip".
     */
    private function resolveLocation(Request $request, array $validated): ?array
    {
        // 1) GPS do aparelho — funciona sem internet e é a melhor fonte.
        if (isset($validated['latitude'], $validated['longitude'])) {
            return [
                'latitude' => (float) $validated['latitude'],
                'longitude' => (float) $validated['longitude'],
                'source' => 'gps',
            ];
        }

        $ip = $request->ip();

        // 2) Coordenada já conhecida do ônibus deste IP público.
        $bus = Bus::where('last_public_ip', $ip)
            ->whereNotNull('last_lat')
            ->whereNotNull('last_lng')
            ->first();

        if ($bus) {
            return $this->ipLocation($bus->last_lat, $bus->last_lng);
        }

        // 3) ip-api.com — só faz sentido para IP público. Faixas privadas
        //    (10.x do hotspot, 127.0.0.1 em teste local) retornam "fail".
        if ($this->isPublicIp($ip)) {
            $geo = cache()->remember('cashcash:geoip:' . $ip, now()->addHours(6), function () use ($ip) {
                try {
                    $resp = Http::timeout(5)->get("http://ip-api.com/json/{$ip}?fields=status,lat,lon");
                    if ($resp->successful() && $resp->json('status') === 'success') {
                        return ['lat' => (float) $resp->json('lat'), 'lng' => (float) $resp->json('lon')];
                    }
                } catch (\Throwable $e) {
                    Log::warning('🏦 Cash Cash: geoip falhou', ['error' => $e->getMessage()]);
                }
                return null;
            });

            if ($geo) {
                return $this->ipLocation($geo['lat'], $geo['lng']);
            }

            cache()->forget('cashcash:geoip:' . $ip); // não cacheia falha por 6h
        }

        // 4) Última coordenada conhecida da frota — o passageiro está num
        //    desses ônibus, então é uma estimativa melhor que nada.
        $frota = Bus::whereNotNull('last_lat')
            ->whereNotNull('last_lng')
            ->orderByDesc('last_sync_at')
            ->first();

        if ($frota) {
            return $this->ipLocation($frota->last_lat, $frota->last_lng);
        }

        // 5) Região de operação configurada. O parceiro exige lat/long;
        //    sem isso o passageiro ficaria travado sem conseguir o desconto.
        $lat = config('services.cashcash.default_latitude');
        $lng = config('services.cashcash.default_longitude');

        if ($lat && $lng) {
            return $this->ipLocation($lat, $lng);
        }

        return null;
    }

    private function ipLocation($lat, $lng): array
    {
        return [
            'latitude' => (float) $lat,
            'longitude' => (float) $lng,
            'source' => 'ip',
        ];
    }

    private function isPublicIp(?string $ip): bool
    {
        return (bool) filter_var(
            (string) $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function messageForFailure(array $result): string
    {
        return match ($result['code'] ?? null) {
            'CPF_UNAVAILABLE' => 'Não há proposta disponível para este CPF agora. Siga com o pagamento normal.',
            'RATE_LIMITED' => 'Muita gente pedindo ao mesmo tempo. Tente de novo em instantes.',
            'VALIDATION_ERROR' => 'Não conseguimos validar seus dados. Confira o CPF e tente de novo.',
            'UPSTREAM_UNAVAILABLE', 'TOKEN_UNAVAILABLE', 'NETWORK_ERROR' =>
                'A proposta está indisponível no momento. Toque em "Pular e pagar normal" para seguir.',
            default => 'Não conseguimos carregar a proposta agora. Siga com o pagamento normal.',
        };
    }
}
