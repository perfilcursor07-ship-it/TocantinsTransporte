<?php

namespace App\Helpers;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;

class SettingsHelper
{
    /**
     * Obter preço do WiFi para plano curto (1 hora)
     */
    public static function getWifiPrice(): float
    {
        return (float) Cache::remember('wifi_price', 3600, function () {
            return SystemSetting::getValue('wifi_price', config('wifi.pricing.default_price', 5.99));
        });
    }

    /**
     * Obter preço do WiFi para viagem completa
     */
    public static function getWifiPriceFull(): float
    {
        return (float) Cache::remember('wifi_price_full', 3600, function () {
            return SystemSetting::getValue('wifi_price_full', 6.99);
        });
    }

    /**
     * Obter gateway PIX ativo
     */
    public static function getPixGateway(): string
    {
        return Cache::remember('pix_gateway', 3600, function () {
            return SystemSetting::getValue('pix_gateway', 'pagbank');
        });
    }

    /**
     * Obter duração da sessão viagem completa em horas
     */
    public static function getSessionDuration(): int
    {
        return (int) Cache::remember('session_duration', 3600, function () {
            return SystemSetting::getValue('session_duration', 12);
        });
    }

    /**
     * Obter duração da sessão curta em horas
     */
    public static function getSessionDurationShort(): int
    {
        return (int) Cache::remember('session_duration_short', 3600, function () {
            return SystemSetting::getValue('session_duration_short', 1);
        });
    }

    /**
     * Obter email do PagBank
     */
    public static function getPagBankEmail(): string
    {
        return Cache::remember('pagbank_email', 3600, function () {
            return SystemSetting::getValue('pagbank_email', 'juniormoreiragloboplay@gmail.com');
        });
    }

    /**
     * Obter token do PagBank
     */
    public static function getPagBankToken(): string
    {
        return Cache::remember('pagbank_token', 3600, function () {
            return SystemSetting::getValue('pagbank_token', 'c75a2308-ec9d-4825-94fd-bacba8a7248344f58a634d1b857348dba39f6a5b6c957b2a-2890-4da4-9866-af24b6eee984');
        });
    }

    /**
     * Obter conta PagBank selecionada (junior ou erick)
     */
    public static function getPagBankAccount(): string
    {
        return Cache::remember('pagbank_account', 3600, function () {
            return SystemSetting::getValue('pagbank_account', 'junior');
        });
    }

    /**
     * Calcular preço original da Viagem Completa ("preço de").
     *
     * Prioridade:
     *  1. Configuração manual `wifi_price_original` (admin define o valor real "de").
     *  2. Fallback crível: ~1.8x o preço atual, com final em ,90 (preço psicológico),
     *     gerando ~45% de desconto — bem mais honesto que o antigo 3.5x (~70%).
     */
    public static function getOriginalPrice(): float
    {
        $currentPrice = self::getWifiPriceFull();

        // 1) Override manual via configuração (se for maior que o preço atual)
        $configured = \App\Models\SystemSetting::getValue('wifi_price_original', null);
        if ($configured !== null && (float) $configured > $currentPrice) {
            return round((float) $configured, 2);
        }

        // 2) Fallback crível: 1.8x arredondado para o ,90 mais próximo
        $raw = $currentPrice * 1.8;
        $original = floor($raw) + 0.90;

        // Garante que o "de" seja sempre maior que o preço atual
        if ($original <= $currentPrice) {
            $original = $currentPrice + 3.00;
        }

        return round($original, 2);
    }

    /**
     * Calcular porcentagem de desconto
     */
    public static function getDiscountPercentage(): int
    {
        $currentPrice = self::getWifiPriceFull();
        $originalPrice = self::getOriginalPrice();
        
        if ($originalPrice <= 0) {
            return 0;
        }
        
        $discount = (($originalPrice - $currentPrice) / $originalPrice) * 100;
        
        return (int) round($discount);
    }

    /**
     * Obter informações completas de preço com desconto
     */
    public static function getPriceInfo(): array
    {
        $currentPrice = self::getWifiPriceFull();
        $originalPrice = self::getOriginalPrice();
        $discountPercentage = self::getDiscountPercentage();
        
        return [
            'current_price' => $currentPrice,
            'original_price' => $originalPrice,
            'discount_percentage' => $discountPercentage,
            'savings' => round($originalPrice - $currentPrice, 2),
        ];
    }

    /**
     * Verificar se desconto por vídeo está habilitado
     */
    public static function isVideoDiscountEnabled(): bool
    {
        return (bool) Cache::remember('video_discount_enabled', 3600, function () {
            return SystemSetting::getValue('video_discount_enabled', '1');
        });
    }

    /**
     * Obter valor do desconto por assistir vídeo
     */
    public static function getVideoDiscountAmount(): float
    {
        return (float) Cache::remember('video_discount_amount', 3600, function () {
            return SystemSetting::getValue('video_discount_amount', '1.00');
        });
    }

    /**
     * Tempo mínimo (em segundos) que o passageiro precisa ficar na proposta
     * do Cash Cash para ganhar o desconto — equivale aos 42s do vídeo antigo.
     */
    public static function getCashCashMinSeconds(): int
    {
        $value = (int) Cache::remember('cashcash_min_seconds', 3600, function () {
            return SystemSetting::getValue('cashcash_min_seconds', '20');
        });

        return max(1, min(300, $value));
    }

    /**
     * Verificar se o agendamento automático do plano por hora está habilitado
     */
    public static function isPlanShortScheduleEnabled(): bool
    {
        return (bool) SystemSetting::getValue('plan_short_schedule_enabled', '0');
    }

    /**
     * Obter horário de início do agendamento do plano por hora (formato HH:MM)
     */
    public static function getPlanShortScheduleStart(): string
    {
        return (string) SystemSetting::getValue('plan_short_schedule_start', '21:00');
    }

    /**
     * Obter horário de fim do agendamento do plano por hora (formato HH:MM)
     */
    public static function getPlanShortScheduleEnd(): string
    {
        return (string) SystemSetting::getValue('plan_short_schedule_end', '06:00');
    }

    /**
     * Verifica se o plano por hora deve estar ativo agora.
     * Considera o agendamento automático se estiver habilitado;
     * caso contrário, usa apenas o toggle manual.
     */
    public static function isPlanShortCurrentlyActive(): bool
    {
        // Se agendamento ativo, decide pelo horário
        if (self::isPlanShortScheduleEnabled()) {
            return self::isWithinSchedule(
                self::getPlanShortScheduleStart(),
                self::getPlanShortScheduleEnd()
            );
        }

        // Senão, usa o toggle manual
        return (bool) SystemSetting::getValue('plan_short_enabled', '1');
    }

    /**
     * Verifica se o horário atual está dentro de uma janela.
     * Suporta janelas que cruzam meia-noite (ex: 21:00 → 06:00).
     */
    protected static function isWithinSchedule(string $start, string $end): bool
    {
        try {
            $now = now();
            [$sh, $sm] = array_pad(array_map('intval', explode(':', $start)), 2, 0);
            [$eh, $em] = array_pad(array_map('intval', explode(':', $end)), 2, 0);

            $startToday = $now->copy()->setTime($sh, $sm, 0);
            $endToday = $now->copy()->setTime($eh, $em, 0);

            // Janela cruza meia-noite (ex: 21:00 → 06:00)
            if ($startToday->gte($endToday)) {
                // Está depois do start ou antes do end?
                return $now->gte($startToday) || $now->lt($endToday);
            }

            // Janela normal (ex: 14:00 → 18:00)
            return $now->between($startToday, $endToday);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Limpar cache de configurações
     */
    public static function clearCache(): void
    {
        Cache::forget('wifi_price');
        Cache::forget('wifi_price_full');
        Cache::forget('pix_gateway');
        Cache::forget('session_duration');
        Cache::forget('session_duration_short');
        Cache::forget('pagbank_account');
        Cache::forget('pagbank_email');
        Cache::forget('pagbank_token');
        Cache::forget('video_discount_enabled');
        Cache::forget('video_discount_amount');
        Cache::forget('cashcash_min_seconds');
    }
}
