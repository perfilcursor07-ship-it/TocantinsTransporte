<?php

namespace Tests\Feature;

use App\Helpers\SettingsHelper;
use App\Models\SystemSetting;
use App\Services\IntervalPlanService;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Tests\Support\IntervalTestDatabase;

/**
 * Trava os preços padrão do sistema e, principalmente, garante que rodar o
 * seeder de novo (depois de um pull/deploy) não derruba o preço que o admin
 * salvou em /admin/settings.
 */
class SettingsDefaultsTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        IntervalTestDatabase::create();
        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        SettingsHelper::clearCache();
    }

    public function test_precos_padrao_sem_nada_salvo(): void
    {
        $this->assertSame(6.99, SettingsHelper::getWifiPrice(), 'Plano por hora');
        $this->assertSame(7.99, SettingsHelper::getWifiPriceFull(), 'Viagem completa');
        $this->assertSame(15.98, IntervalPlanService::settings()['price_24h'], 'Diária de 24h');
        $this->assertFalse(SettingsHelper::isVideoDiscountEnabled(), 'Espaço de desconto vem desligado');
    }

    public function test_diaria_24h_ainda_dobra_o_valor_antigo_de_12h(): void
    {
        SystemSetting::setValue('plan_interval_price_12h', '6.99');
        SettingsHelper::clearCache();

        $this->assertSame(13.98, IntervalPlanService::settings()['price_24h']);
    }

    public function test_seeder_nao_sobrescreve_preco_salvo_pelo_admin(): void
    {
        SystemSetting::setValue('wifi_price', '8.50');
        SystemSetting::setValue('wifi_price_full', '9.90');
        SystemSetting::setValue('plan_interval_price_24h', '19.80');
        SystemSetting::setValue('video_discount_enabled', '1');

        (new SystemSettingsSeeder())->run();
        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        SettingsHelper::clearCache();

        $this->assertSame('8.50', SystemSetting::getValue('wifi_price'));
        $this->assertSame('9.90', SystemSetting::getValue('wifi_price_full'));
        $this->assertSame('19.80', SystemSetting::getValue('plan_interval_price_24h'));
        $this->assertSame('1', SystemSetting::getValue('video_discount_enabled'));
    }

    public function test_seeder_cria_as_chaves_que_faltam_com_os_valores_novos(): void
    {
        (new SystemSettingsSeeder())->run();
        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        SettingsHelper::clearCache();

        $this->assertSame('6.99', SystemSetting::getValue('wifi_price'));
        $this->assertSame('7.99', SystemSetting::getValue('wifi_price_full'));
        $this->assertSame('15.98', SystemSetting::getValue('plan_interval_price_24h'));
        $this->assertSame('0', SystemSetting::getValue('video_discount_enabled'));
    }

    public function test_migration_grava_a_tabela_de_precos_nova(): void
    {
        SystemSetting::setValue('wifi_price', '5.99');
        SystemSetting::setValue('wifi_price_full', '6.99');
        SystemSetting::setValue('video_discount_enabled', '1');

        $migration = require __DIR__.'/../../database/migrations/2026_11_11_000001_set_new_plan_prices.php';
        $migration->up();

        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        SettingsHelper::clearCache();

        $this->assertSame(6.99, SettingsHelper::getWifiPrice());
        $this->assertSame(7.99, SettingsHelper::getWifiPriceFull());
        $this->assertSame(15.98, IntervalPlanService::settings()['price_24h']);
        $this->assertFalse(SettingsHelper::isVideoDiscountEnabled());
    }

    public function test_migration_nao_quebra_quando_a_tabela_ainda_nao_existe(): void
    {
        \Illuminate\Support\Facades\Schema::drop('system_settings');

        $migration = require __DIR__.'/../../database/migrations/2026_11_11_000001_set_new_plan_prices.php';
        $migration->up();

        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('system_settings'));
    }
}
