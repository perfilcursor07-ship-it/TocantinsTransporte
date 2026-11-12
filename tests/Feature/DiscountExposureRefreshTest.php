<?php

namespace Tests\Feature;

use App\Http\Controllers\CashCashController;
use App\Models\DiscountLead;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IntervalTestDatabase;

/**
 * O desconto do anúncio exige o tempo mínimo NA TELA do anúncio. Recarregar a
 * página não pode valer como ter visto.
 */
class DiscountExposureRefreshTest extends TestCase
{
    private const MAC = 'D6:DE:C4:66:F2:01';

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
        Schema::create('discount_leads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('sponsor_id')->nullable();
            $t->foreignId('user_id')->nullable();
            $t->string('cpf')->nullable();
            $t->string('phone')->nullable();
            $t->string('mac_address')->nullable();
            $t->dateTime('consent_accepted_at')->nullable();
            $t->string('consent_ip')->nullable();
            $t->text('consent_user_agent')->nullable();
            $t->decimal('consent_latitude', 10, 7)->nullable();
            $t->decimal('consent_longitude', 10, 7)->nullable();
            $t->string('consent_location_source')->nullable();
            $t->string('status');
            $t->string('handoff_id')->nullable();
            $t->integer('http_status')->nullable();
            $t->string('error_code')->nullable();
            $t->dateTime('link_expires_at')->nullable();
            $t->dateTime('link_opened_at')->nullable();
            $t->dateTime('returned_at')->nullable();
            $t->integer('dwell_seconds')->nullable();
            $t->dateTime('discount_granted_at')->nullable();
            $t->decimal('discount_amount', 8, 2)->nullable();
            $t->timestamps();
        });
        (new \ReflectionProperty(\App\Models\SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        Carbon::setTestNow(Carbon::parse('2026-11-12 19:30:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function at(int $seconds): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 19:30:00')->addSeconds($seconds));
    }

    /** Passageiro abre a tela do anúncio ("Tenha desconto"). */
    private function openAd(): int
    {
        $response = app(CashCashController::class)->exposure(
            Request::create('/api/cashcash/exposure', 'POST', ['mac_address' => self::MAC])
        );

        return $response->getData(true)['lead_id'];
    }

    private function confirm(int $leadId): array
    {
        return app(CashCashController::class)->confirm(
            Request::create('/api/cashcash/confirm', 'POST', ['lead_id' => $leadId])
        )->getData(true);
    }

    public function test_reloading_the_page_never_resumes_an_ad_exposure(): void
    {
        $this->openAd();

        // Recarregou logo em seguida, ou minutos depois: o portal não tem nada
        // a "retomar", então não confere nem concede desconto no carregamento.
        foreach ([2, 25, 600] as $seconds) {
            $this->at($seconds);
            $this->assertNull(DiscountLead::pendingReturnFor(self::MAC));
            $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
        }
    }

    public function test_reopening_the_ad_restarts_the_clock(): void
    {
        $lead = $this->openAd();

        // Abriu, recarregou e ficou 5 minutos fora. Ao abrir o anúncio de novo
        // o tempo recomeça — o relógio antigo não vale.
        $this->at(300);
        $this->assertSame($lead, $this->openAd(), 'Reaproveita o mesmo registro.');

        $this->at(305);
        $early = $this->confirm($lead);
        $this->assertFalse($early['granted']);
        $this->assertSame(15, $early['remaining_seconds']);

        $this->at(321);
        $this->assertTrue($this->confirm($lead)['granted']);

        // Ganhou de verdade: aí sim o desconto sobrevive a um recarregamento.
        $this->assertNotNull(DiscountLead::activeDiscountFor(self::MAC));
    }

    public function test_staying_the_minimum_time_on_the_ad_still_grants_the_discount(): void
    {
        $lead = $this->openAd();

        $this->at(19);
        $this->assertFalse($this->confirm($lead)['granted']);

        $this->at(20);
        $this->assertTrue($this->confirm($lead)['granted']);
    }

    public function test_return_from_the_partner_app_is_still_resumed_on_reload(): void
    {
        // Proposta com CPF que foi para o app do parceiro (modo "mesma aba"):
        // essa continua sendo retomada quando o portal recarrega.
        $handoff = DiscountLead::create([
            'mac_address' => self::MAC, 'cpf' => '12345678909', 'status' => DiscountLead::STATUS_CREATED,
            'handoff_id' => 'HND_1', 'link_opened_at' => now(),
        ]);

        $this->at(40);
        $this->assertSame($handoff->id, DiscountLead::pendingReturnFor(self::MAC)?->id);
        $this->assertNull(DiscountLead::pendingExposureFor(self::MAC));

        // E abrir o anúncio não mexe no relógio dessa proposta.
        $exposure = $this->openAd();
        $this->assertNotSame($handoff->id, $exposure);
        $this->assertSame('2026-11-12 19:30:00', $handoff->fresh()->link_opened_at->toDateTimeString());
    }
}
