<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\SystemSetting;

class SystemSettingsSeeder extends Seeder
{
    /**
     * Valores iniciais do sistema.
     *
     * IMPORTANTE: este seeder NUNCA sobrescreve o que já está salvo.
     * Os preços são editados pelo admin em /admin/settings e rodar o seeder
     * de novo (depois de um deploy/pull, por exemplo) não pode derrubar o
     * preço que está no ar. Só cria a chave quando ela ainda não existe.
     */
    public function run(): void
    {
        $settings = [
            ['key' => 'wifi_price', 'value' => '6.99'],
            ['key' => 'wifi_price_full', 'value' => '7.99'],
            ['key' => 'plan_interval_price_24h', 'value' => '15.98'],
            ['key' => 'pix_gateway', 'value' => 'pagbank'],
            ['key' => 'session_duration', 'value' => '12'],
            ['key' => 'session_duration_short', 'value' => '1'],
            ['key' => 'plan_short_enabled', 'value' => '1'],
            ['key' => 'plan_full_enabled', 'value' => '1'],
            ['key' => 'video_discount_enabled', 'value' => '0'],
            ['key' => 'video_discount_amount', 'value' => '1.00'],
        ];

        $created = 0;

        foreach ($settings as $setting) {
            $record = SystemSetting::firstOrCreate(
                ['key' => $setting['key']],
                ['value' => $setting['value']]
            );

            if ($record->wasRecentlyCreated) {
                $created++;
            }
        }

        \App\Helpers\SettingsHelper::clearCache();

        $this->command?->info("✅ Configurações do sistema: {$created} criada(s), as já existentes foram preservadas.");
    }
}
