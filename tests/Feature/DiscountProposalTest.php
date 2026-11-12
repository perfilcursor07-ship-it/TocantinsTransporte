<?php

namespace Tests\Feature;

use App\Http\Controllers\CashCashController;
use App\Models\DiscountLead;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Support\IntervalTestDatabase;

/**
 * Regra do desconto: só ganha quem informou telefone + CPF e deixou a proposta
 * do parceiro aberta pelo tempo mínimo. O servidor confere e grava.
 */
class DiscountProposalTest extends TestCase
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

    /** Proposta com CPF que o parceiro aceitou e o portal acabou de abrir. */
    private function proposal(array $overrides = []): DiscountLead
    {
        return DiscountLead::create(array_merge([
            'mac_address' => self::MAC, 'phone' => '63981013088', 'cpf' => '12345678909',
            'status' => DiscountLead::STATUS_CREATED, 'handoff_id' => 'HND_1', 'link_opened_at' => now(),
        ], $overrides));
    }

    private function confirm(int $leadId): array
    {
        return app(CashCashController::class)->confirm(
            Request::create('/api/cashcash/confirm', 'POST', ['lead_id' => $leadId])
        )->getData(true);
    }

    public function test_seeing_the_proposal_for_the_minimum_time_grants_and_records_the_discount(): void
    {
        $lead = $this->proposal();

        $this->at(19);
        $early = $this->confirm($lead->id);
        $this->assertFalse($early['granted']);
        $this->assertSame(1, $early['remaining_seconds']);
        $this->assertNull($lead->fresh()->discount_granted_at);

        $this->at(30);
        $done = $this->confirm($lead->id);
        $this->assertTrue($done['granted']);
        $this->assertEqualsWithDelta(1.00, $done['discount_amount'], 0.001);

        // Fica gravado: quando abriu, quando voltou, quanto tempo viu e o desconto.
        $saved = $lead->fresh();
        $this->assertSame('2026-11-12 19:30:00', $saved->link_opened_at->toDateTimeString());
        $this->assertSame('2026-11-12 19:30:30', $saved->returned_at->toDateTimeString());
        $this->assertSame(30, $saved->dwell_seconds);
        $this->assertSame('2026-11-12 19:30:30', $saved->discount_granted_at->toDateTimeString());
        $this->assertEqualsWithDelta(1.00, (float) $saved->discount_amount, 0.001);

        // E é esse registro que mantém o desconto se a página recarregar.
        $this->assertSame($lead->id, DiscountLead::activeDiscountFor(self::MAC)?->id);
    }

    public function test_without_cpf_or_without_the_partner_link_there_is_never_a_discount(): void
    {
        $semCpf = $this->proposal(['cpf' => null, 'handoff_id' => null]);       // antigo "ver o anúncio"
        $semLink = $this->proposal(['handoff_id' => null]);                      // CPF informado, parceiro não devolveu link

        $this->at(600);
        foreach ([$semCpf, $semLink] as $lead) {
            $answer = $this->confirm($lead->id);
            $this->assertFalse($answer['granted']);
            $this->assertSame('requires_proposal', $answer['reason']);
            $this->assertNull($lead->fresh()->discount_granted_at);
        }

        $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
        $this->assertNull(DiscountLead::pendingReturnFor(self::MAC));
    }

    public function test_a_discount_recorded_without_a_proposal_is_not_restored_on_reload(): void
    {
        // Registro do fluxo antigo, já "concedido" sem CPF: não vale mais.
        $this->proposal(['cpf' => null, 'handoff_id' => null, 'discount_granted_at' => now(), 'discount_amount' => 1]);

        $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
    }

    public function test_the_no_cpf_discount_route_no_longer_exists(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri());

        $this->assertTrue($uris->contains('api/cashcash/confirm'));
        $this->assertFalse($uris->contains('api/cashcash/exposure'));
    }

    public function test_return_from_the_partner_app_is_resumed_on_reload(): void
    {
        $lead = $this->proposal();

        $this->at(40);
        $this->assertSame($lead->id, DiscountLead::pendingReturnFor(self::MAC)?->id);
    }
}
