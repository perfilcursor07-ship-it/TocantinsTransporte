<?php

namespace App\Support;

use App\Models\MikrotikMacReport;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class HotspotIdentity
{
    /**
     * Resolve the best client IP considering proxy headers.
     */
    public static function resolveClientIp(Request $request): ?string
    {
        $candidates = [
            $request->query('ip'),
            $request->query('ip_address'),
            $request->query('client_ip'),
            $request->input('ip'),
            $request->input('ip_address'),
            $request->input('client_ip'),
            $request->header('CF-Connecting-IP'),
            $request->header('X-Client-IP'),
            $request->header('X-Forwarded-For'),
            $request->header('X-Real-IP'),
        ];

        foreach ($candidates as $value) {
            if (! $value) {
                continue;
            }

            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }

        return $request->ip();
    }

    /**
     * Normalize MAC formatting (uppercase, colon separated).
     */
    public static function normalizeMac(?string $mac): ?string
    {
        if (! $mac) {
            return null;
        }

        $normalized = strtoupper(str_replace('-', ':', trim($mac)));

        return Str::length($normalized) === 17 ? $normalized : null;
    }

    /**
     * Determine if the provided MAC is a locally administered (randomized) address.
     * 
     * IMPORTANT: Modern iOS (14+) and Android (10+) devices use randomized MACs
     * by default. These MACs have the "locally administered" bit set (bit 1 of
     * first byte), resulting in prefixes like 02:, 06:, 0A:, 0E:, etc.
     * 
     * These MACs are CONSISTENT per-network (same random MAC for same SSID),
     * so they are VALID identifiers for our hotspot authentication.
     * 
     * We should NOT treat them as "mock" or invalid - they represent real devices.
     * Only truly empty/null MACs should be considered invalid.
     */
    public static function isMockMac(?string $mac): bool
    {
        if (! $mac) {
            return true;
        }

        $normalized = strtoupper(trim($mac));

        // 02:FA:CE = prefixo exclusivo dos MACs mock gerados internamente
        if (str_starts_with($normalized, '02:FA:CE')) {
            return true;
        }

        // Only reject truly invalid/placeholder MACs
        return in_array($normalized, [
            '00:00:00:00:00:00',
            'FF:FF:FF:FF:FF:FF',
            'UNKNOWN',
            '',
        ]);
    }

    /**
     * Check if a MAC is locally administered (randomized).
     * This is informational only - randomized MACs are perfectly valid.
     */
    public static function isRandomizedMac(?string $mac): bool
    {
        if (! $mac || strlen($mac) < 2) {
            return false;
        }

        $firstByte = hexdec(substr(strtoupper(trim($mac)), 0, 2));

        return ($firstByte & 0x02) !== 0;
    }

    /**
     * Decide whether a new MAC should replace the current one.
     */
    public static function shouldReplaceMac(?string $current, ?string $candidate): bool
    {
        if (! $candidate) {
            return false;
        }

        if (! $current) {
            return true;
        }

        $currentMock = self::isMockMac($current);
        $candidateMock = self::isMockMac($candidate);

        if ($currentMock && ! $candidateMock) {
            return true;
        }

        if (! $currentMock && $candidateMock) {
            return false;
        }

        return strcasecmp($current, $candidate) !== 0;
    }

    /**
     * Try to obtain a real MAC for the given IP using Mikrotik reports.
     */
    public static function resolveRealMac(?string $mac, ?string $ip): ?string
    {
        $normalizedMac = self::normalizeMac($mac);

        if (! $normalizedMac) {
            // Try to get MAC from URL query params
            $queryMac = self::normalizeMac(request()->query('mac'));
            if ($queryMac && ! self::isMockMac($queryMac)) {
                $normalizedMac = $queryMac;
            } elseif ($referer = request()->headers->get('referer')) {
                $parsed = parse_url($referer);
                if (! empty($parsed['query'])) {
                    parse_str($parsed['query'], $queryParams);
                    if (! empty($queryParams['mac'])) {
                        $refererMac = self::normalizeMac($queryParams['mac']);
                        if ($refererMac && ! self::isMockMac($refererMac)) {
                            $normalizedMac = $refererMac;
                        }
                    }
                }
            }
        } elseif (self::isMockMac($normalizedMac)) {
            // Only nullify truly invalid MACs (00:00:00:00:00:00, etc.)
            $normalizedMac = null;
        }

        if (! $ip) {
            return $normalizedMac;
        }

        // Só consultar mikrotik_mac_reports se NÃO temos um MAC válido do frontend
        // Se o frontend já enviou um MAC real (não-mock), ele é confiável - não substituir
        // Isso evita trocar o MAC correto pelo MAC de outro dispositivo com mesmo IP
        if (! $normalizedMac || self::isMockMac($normalizedMac)) {
            $report = self::latestReportForIp($ip);

            if ($report && ! self::isMockMac($report->mac_address)) {
                return self::normalizeMac($report->mac_address);
            }
        }

        return $normalizedMac;
    }

    /**
     * Report mais recente do IP interno, preferindo o ônibus de onde vem a
     * requisição. Todos os ônibus usam 10.5.50.x, então o mesmo IP existe em
     * vários carros: sem o filtro, o MAC devolvido podia ser de um aparelho
     * de outro ônibus (e era esse que acabava liberado).
     *
     * Só fica mais restrito no caso ambíguo; qualquer falha volta ao
     * comportamento anterior.
     */
    private static function latestReportForIp(string $ip): ?MikrotikMacReport
    {
        try {
            $busId = cache()->get('mikrotik_ip_'.request()->ip());

            if ($busId) {
                $fromBus = MikrotikMacReport::getLatestMacForIp($ip, $busId);
                if ($fromBus) {
                    return $fromBus;
                }

                // Nenhum report deste ônibus: só aceitar se o IP aponta para
                // um único MAC. Com mais de um candidato, não adivinhar.
                $candidates = MikrotikMacReport::where('ip_address', $ip)
                    ->recent()
                    ->pluck('mac_address')
                    ->map(fn ($m) => strtoupper(trim((string) $m)))
                    ->unique();

                if ($candidates->count() > 1) {
                    return null;
                }
            }
        } catch (\Throwable $e) {
            // cai no comportamento anterior
        }

        return MikrotikMacReport::getLatestMacForIp($ip);
    }

    /**
     * Marca um MAC antigo como órfão para ser removido do Mikrotik no próximo sync.
     * Usado quando o MAC de um usuário é substituído por um novo (MAC aleatório mudou).
     */
    public static function markOrphanedMac(?string $oldMac): void
    {
        if (! $oldMac || self::isMockMac($oldMac)) {
            return;
        }

        // Mapa MAC => timestamp de expiração. O R: é reenviado em TODOS os syncs
        // durante 15 min para que TODOS os MikroTiks (8 ônibus) recebam a remoção
        // — antes, o cache era apagado no primeiro sync e só 1 ônibus removia.
        $orphanedMacs = cache()->get('orphaned_macs_to_remove', []);
        $normalized = strtoupper(trim($oldMac));

        $orphanedMacs[$normalized] = now()->addMinutes(15)->timestamp;
        cache()->put('orphaned_macs_to_remove', $orphanedMacs, now()->addMinutes(20));

        \Illuminate\Support\Facades\Log::info('🗑️ MAC antigo marcado para remoção do Mikrotik', [
            'orphaned_mac' => $normalized,
        ]);
    }
}
