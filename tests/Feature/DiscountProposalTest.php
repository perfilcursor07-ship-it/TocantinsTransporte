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

    /** Proposta vista até o fim: desconto liberado agora. */
    private function granted(array $overrides = []): DiscountLead
    {
        return $this->proposal(array_merge(['discount_granted_at' => now(), 'discount_amount' => 2], $overrides));
    }

    private function passenger(): \App\Models\User
    {
        return \App\Models\User::create(['name' => 'Passageiro', 'phone' => '63981013088', 'mac_address' => self::MAC, 'status' => 'pending']);
    }

    public function test_discount_expires_after_the_configured_validity(): void
    {
        $lead = $this->granted();

        $this->at(59 * 60);
        $this->assertSame($lead->id, DiscountLead::activeDiscountFor(self::MAC)?->id, 'Padrão: vale 60 minutos.');
        $this->assertSame('ativo', $lead->fresh()->discountState());

        $this->at(61 * 60);
        $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
        $this->assertSame('vencido', $lead->fresh()->discountState());

        // O servidor também recusa quem ficou com a página aberta e pede de novo.
        $answer = $this->confirm($lead->id);
        $this->assertFalse($answer['granted']);
        $this->assertSame('expired', $answer['reason']);
    }

    public function test_validity_is_configurable_and_sent_to_the_portal(): void
    {
        \App\Models\SystemSetting::setValue('discount_validity_minutes', 10);
        \App\Helpers\SettingsHelper::clearCache();

        $lead = $this->proposal();
        $this->at(30);
        $this->assertSame(600, $this->confirm($lead->id)['expires_in']);

        $this->at(30 + 9 * 60);
        $this->assertNotNull(DiscountLead::activeDiscountFor(self::MAC));
        $this->assertSame(60, $this->confirm($lead->id)['expires_in'], 'Falta 1 minuto.');

        $this->at(30 + 11 * 60);
        $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
    }

    public function test_discount_is_single_use_it_ends_when_the_passenger_pays(): void
    {
        $user = $this->passenger();
        $lead = $this->granted(['user_id' => $user->id]);

        // PIX gerado e ainda não pago não consome o desconto.
        $payment = \App\Models\Payment::create(['user_id' => $user->id, 'amount' => 5.99, 'payment_type' => 'pix', 'status' => 'pending']);
        $this->at(120);
        $this->assertNotNull(DiscountLead::activeDiscountFor(self::MAC));

        $payment->update(['status' => 'completed', 'paid_at' => now()]);

        // Pagou: mesmo dentro do prazo, a próxima conexão volta ao preço normal.
        $this->at(600);
        $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
        $this->assertSame('usado', $lead->fresh()->discountState());
        $this->assertSame($payment->id, $lead->fresh()->usedPayment()?->id);
        $this->assertSame('used', $this->confirm($lead->id)['reason']);
    }

    public function test_a_payment_made_before_the_discount_does_not_consume_it(): void
    {
        $user = $this->passenger();
        \App\Models\Payment::create(['user_id' => $user->id, 'amount' => 7.99, 'payment_type' => 'pix',
            'status' => 'completed', 'paid_at' => now()->subDay()]);

        $lead = $this->granted(['user_id' => $user->id]);

        $this->assertSame($lead->id, DiscountLead::activeDiscountFor(self::MAC)?->id);
    }

    public function test_repeat_interval_blocks_a_new_discount_by_device_phone_or_cpf(): void
    {
        $this->granted();

        $this->at(3 * 86400);
        $this->assertFalse(DiscountLead::earnedRecently(0, self::MAC), '0 = sem intervalo.');
        $this->assertTrue(DiscountLead::earnedRecently(7, self::MAC));
        $this->assertTrue(DiscountLead::earnedRecently(7, 'AA:BB:CC:DD:EE:FF', '(63) 98101-3088'), 'Outro aparelho, mesmo telefone.');
        $this->assertTrue(DiscountLead::earnedRecently(7, null, null, '123.456.789-09'), 'Só o CPF.');
        $this->assertFalse(DiscountLead::earnedRecently(7, 'AA:BB:CC:DD:EE:FF', '63999990000', '98765432100'));

        $this->at(8 * 86400);
        $this->assertFalse(DiscountLead::earnedRecently(7, self::MAC), 'Passou o intervalo.');
    }

    public function test_admin_screen_classifies_each_proposal(): void
    {
        $user = $this->passenger();
        $this->be(\App\Models\User::create(['name' => 'Admin', 'role' => 'admin', 'mac_address' => 'AA:AA:AA:AA:AA:01']));

        $naoVoltou = $this->proposal(['mac_address' => 'AA:AA:AA:AA:AA:02', 'cpf' => '11111111111']);
        $usado = $this->granted(['user_id' => $user->id]);
        $vencido = $this->granted(['mac_address' => 'AA:AA:AA:AA:AA:03', 'cpf' => '22222222222',
            'discount_granted_at' => now()->subHours(3)]);
        \App\Models\Payment::create(['user_id' => $user->id, 'amount' => 5.99, 'payment_type' => 'pix',
            'status' => 'completed', 'paid_at' => now()->addMinutes(4)]);
        $this->at(300);
        $ativo = $this->granted(['mac_address' => 'AA:AA:AA:AA:AA:04', 'cpf' => '33333333333']);

        $data = app(\App\Http\Controllers\Admin\DiscountControlController::class)
            ->index(Request::create('/admin/descontos', 'GET'))->getData();

        $situacoes = collect($data['leads']->items())->pluck('situacao', 'id');
        $this->assertSame('sem_desconto', $situacoes[$naoVoltou->id]);
        $this->assertSame('usado', $situacoes[$usado->id]);
        $this->assertSame('vencido', $situacoes[$vencido->id]);
        $this->assertSame('ativo', $situacoes[$ativo->id]);
        $this->assertSame(['propostas' => 4, 'liberados' => 3, 'usados' => 1, 'ativos' => 1, 'vencidos' => 1, 'valor_usado' => 2.0], $data['resumo']);

        // Filtro por situação e a gravação das regras.
        $filtrado = app(\App\Http\Controllers\Admin\DiscountControlController::class)
            ->index(Request::create('/admin/descontos', 'GET', ['situacao' => 'usado']))->getData();
        $this->assertSame([$usado->id], collect($filtrado['leads']->items())->pluck('id')->all());

        app(\App\Http\Controllers\Admin\DiscountControlController::class)->update(
            Request::create('/admin/descontos', 'PUT', ['discount_validity_minutes' => 15, 'discount_repeat_days' => 30])
        );
        $this->assertSame(15, \App\Helpers\SettingsHelper::getDiscountValidityMinutes());
        $this->assertSame(30, \App\Helpers\SettingsHelper::getDiscountRepeatDays());
    }

    private function reportReachability(bool $reachable): array
    {
        return app(CashCashController::class)->reachability(
            Request::create('/api/cashcash/reachability', 'POST', ['reachable' => $reachable])
        )->getData(true);
    }

    public function test_partner_reachability_is_recorded_per_bus_and_shown_to_the_admin(): void
    {
        // Fora de um ônibus (IP sem MikroTik conhecido) o aviso é ignorado.
        $this->assertFalse($this->reportReachability(false)['recorded']);

        // O MikroTik do ônibus sincronizou por este IP.
        cache()->put('mikrotik_ip_127.0.0.1', 'HC10ABC', now()->addHour());

        $this->assertTrue($this->reportReachability(false)['recorded']);
        $this->at(60);
        $this->reportReachability(false);

        $this->be(\App\Models\User::create(['name' => 'Admin', 'role' => 'admin', 'mac_address' => 'AA:AA:AA:AA:AA:01']));
        $tela = fn () => app(\App\Http\Controllers\Admin\DiscountControlController::class)
            ->index(Request::create('/admin/descontos', 'GET'))->getData()['alcance'];

        $linha = $tela()[0];
        $this->assertSame('HC10ABC', $linha['serial']);
        $this->assertFalse($linha['abre']);
        $this->assertSame([0, 2], [$linha['ok'], $linha['falhas']]);

        // Liberaram o parceiro no roteador: o próximo passageiro já muda a situação.
        $this->at(600);
        $this->reportReachability(true);
        $linha = $tela()[0];
        $this->assertTrue($linha['abre']);
        $this->assertSame('2026-11-12 19:40:00', $linha['ultimo_ok']->toDateTimeString());
    }

    public function test_only_the_host_of_the_partner_link_is_remembered(): void
    {
        $service = app(\App\Services\CashCashService::class);

        $service->rememberPartnerOrigin('https://app.cashcash.com.br/avaliacao/processando?token=SEGREDO&utm_source=x');

        $this->assertSame('https://app.cashcash.com.br', $service->partnerOrigin());
        $this->assertNull(\App\Services\CashCashService::originOf('nao-e-um-link'));
    }

    public function test_admin_can_delete_proposals_one_by_one_or_in_bulk(): void
    {
        $this->be(\App\Models\User::create(['name' => 'Admin', 'role' => 'admin', 'mac_address' => 'AA:AA:AA:AA:AA:01']));
        $controller = app(\App\Http\Controllers\Admin\DiscountControlController::class);

        $ativo = $this->granted();
        $outras = collect(range(2, 4))->map(fn ($n) => $this->proposal(['mac_address' => "AA:AA:AA:AA:AA:0{$n}", 'cpf' => str_repeat((string) $n, 11)]));
        $this->assertNotNull(DiscountLead::activeDiscountFor(self::MAC));

        // Uma só: o desconto ativo dela deixa de valer.
        $controller->destroy($ativo);
        $this->assertNull(DiscountLead::find($ativo->id));
        $this->assertNull(DiscountLead::activeDiscountFor(self::MAC));
        $this->assertFalse(DiscountLead::earnedRecently(30, self::MAC), 'O histórico do passageiro também sai.');

        // Em lote: só as marcadas.
        $controller->destroyMany(Request::create('/admin/descontos', 'DELETE', ['lead_ids' => [$outras[0]->id, $outras[1]->id]]));
        $this->assertSame([$outras[2]->id], DiscountLead::pluck('id')->all());
    }

    public function test_deleting_proposals_is_only_for_admins(): void
    {
        $lead = $this->proposal();
        $this->be(\App\Models\User::create(['name' => 'Operador', 'role' => 'user', 'mac_address' => 'AA:AA:AA:AA:AA:09']));
        $controller = app(\App\Http\Controllers\Admin\DiscountControlController::class);

        foreach ([
            fn () => $controller->destroy($lead),
            fn () => $controller->destroyMany(Request::create('/admin/descontos', 'DELETE', ['lead_ids' => [$lead->id]])),
        ] as $tentativa) {
            try {
                $tentativa();
                $this->fail('Quem não é admin não pode excluir.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $this->assertNotNull(DiscountLead::find($lead->id));
    }

    public function test_admin_screen_is_only_for_admins(): void
    {
        $this->be(\App\Models\User::create(['name' => 'Operador', 'role' => 'user', 'mac_address' => 'AA:AA:AA:AA:AA:09']));

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(\App\Http\Controllers\Admin\DiscountControlController::class)->index(Request::create('/admin/descontos', 'GET'));
    }

    public function test_return_from_the_partner_app_is_resumed_on_reload(): void
    {
        $lead = $this->proposal();

        $this->at(40);
        $this->assertSame($lead->id, DiscountLead::pendingReturnFor(self::MAC)?->id);
    }
}
