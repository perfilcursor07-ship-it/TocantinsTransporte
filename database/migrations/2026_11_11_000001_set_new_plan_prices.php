<?php

use App\Helpers\SettingsHelper;
use App\Models\SystemSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Aplica a tabela de preços nova de uma vez em cada ambiente:
 *
 *   Plano por hora .......... R$ 6,99
 *   Viagem completa ......... R$ 7,99
 *   Diária de 24h ........... R$ 15,98
 *   Espaço de desconto ...... desligado
 *
 * Roda uma única vez por banco. Depois disso o painel (/admin/settings)
 * continua mandando: quem grava por último ganha, e o seeder não mexe mais
 * em chave que já existe.
 */
return new class extends Migration
{
    private const VALUES = [
        'wifi_price' => '6.99',
        'wifi_price_full' => '7.99',
        'plan_interval_price_24h' => '15.98',
        'video_discount_enabled' => '0',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('system_settings')) {
            return;
        }

        foreach (self::VALUES as $key => $value) {
            SystemSetting::setValue($key, $value);
        }

        // O preço fica em cache por 1 hora; sem isso o portal continuaria
        // mostrando o valor antigo até o cache vencer sozinho.
        try {
            SettingsHelper::clearCache();
        } catch (\Throwable $e) {
            // Cache indisponível durante o deploy não pode derrubar a migration.
        }
    }

    public function down(): void
    {
        // Sem volta: não dá para adivinhar o preço que estava salvo antes.
        // Se precisar reverter, ajuste em /admin/settings.
    }
};
