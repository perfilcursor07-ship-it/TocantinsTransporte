<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TourismTripController;
use App\Http\Controllers\TourismPaymentController;
use App\Http\Middleware\CheckModule;
use App\Models\Bus;
use App\Models\SystemSetting;
use App\Models\TourismTrip;
use App\Models\User;
use App\Services\TourismPaymentService;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TourismTripTest extends TestCase
{
    /** Pedidos criados no PagBank falso: order_id => [reference_id, valor em centavos, status]. */
    private array $orders = [];

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array', 'logging.default' => 'null',
            'logging.channels.pagbank' => ['driver' => 'monolog', 'handler' => \Monolog\Handler\NullHandler::class],
            'app.timezone' => 'America/Araguaina', 'app.url' => 'https://wifi.test']);
        DB::purge('sqlite');
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable(); $t->string('password')->nullable();
            $t->string('phone')->nullable(); $t->string('mac_address')->nullable(); $t->string('status')->default('pending');
            $t->string('role')->default('user'); $t->json('allowed_modules')->nullable(); $t->timestamps();
        });
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps();
        });
        (require base_path('database/migrations/2026_04_03_000001_create_buses_table.php'))->up();
        (require base_path('database/migrations/2026_10_04_000001_create_tourism_trips_table.php'))->up();
        (require base_path('database/migrations/2026_10_05_000001_add_contract_and_coverage_to_tourism_trips_table.php'))->up();
        (require base_path('database/migrations/2026_10_09_000001_add_payment_to_tourism_trips_table.php'))->up();
        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        SystemSetting::setValue('wifi_price_full', '6.99');
        SystemSetting::setValue('session_duration', '12');
        SystemSetting::setValue('plan_interval_price_24h', '13.98');
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00', 'America/Araguaina'));
        $this->fakePagBank();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** PagBank falso: cria pedidos e responde a consulta com o status guardado em $this->orders. */
    private function fakePagBank(): void
    {
        Http::fake(function (HttpRequest $request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/orders')) {
                $id = 'ORDE_'.(count($this->orders) + 1);
                $cents = $request['qr_codes'][0]['amount']['value'];
                $this->orders[$id] = ['ref' => $request['reference_id'], 'cents' => $cents, 'status' => 'WAITING',
                    'notify' => $request['notification_urls'][0]];

                return Http::response(['id' => $id, 'qr_codes' => [['id' => 'QRCO_1', 'text' => "PIXCODE-{$id}",
                    'expiration_date' => now()->addDay()->toIso8601String()]]], 201);
            }
            if ($request->method() === 'GET' && preg_match('#/orders/(ORDE_\d+)$#', $request->url(), $m)) {
                $order = $this->orders[$m[1]];
                $charges = $order['status'] === 'PAID'
                    ? [['id' => 'CHAR_1', 'status' => 'PAID', 'amount' => ['value' => $order['cents']], 'paid_at' => now()->toIso8601String()]]
                    : [];

                return Http::response(['id' => $m[1], 'reference_id' => $order['ref'], 'charges' => $charges]);
            }

            return Http::response([], 404);
        });
    }

    private function user(string $role, array $modules = []): User
    {
        return User::create(['name' => ucfirst($role), 'role' => $role, 'allowed_modules' => $modules]);
    }

    private function bus(string $serial = 'HH60A2NSBE7'): Bus
    {
        return Bus::create(['mikrotik_serial' => $serial, 'name' => "Ônibus {$serial}", 'plate' => 'ABC1D23']);
    }

    /** Uma pessoa paga tudo: 10/10 06:00 → 12/10 20:00 (62h = 3 diárias), 40 passageiros. */
    private function payload(Bus $bus, array $overrides = []): array
    {
        return array_merge([
            'contract_number' => '01/2026', 'title' => 'Excursão Jalapão', 'bus_id' => $bus->id,
            'starts_at' => '2026-10-10T06:00', 'ends_at' => '2026-10-12T20:00', 'passengers_count' => 40,
            'responsible_name' => 'Sônia Maria', 'responsible_phone' => '(63) 9 8488-5877', 'coverage' => 'all_days',
        ], $overrides);
    }

    /** Datas do contrato 01/2026 (ida 04/01 → volta 16/01), 53 passageiros. */
    private function groupPayload(Bus $bus, array $overrides = []): array
    {
        return $this->payload($bus, array_merge([
            'title' => 'Sônia Maria — Campos do Jordão / Gramado', 'passengers_count' => 53,
            'starts_at' => '2028-01-04T06:00', 'ends_at' => '2028-01-16T21:00',
            'plan_details' => 'Sônia paga a internet de todos.',
        ], $overrides));
    }

    private function request(User $user, array $data, string $method = 'POST'): Request
    {
        $request = Request::create('/', $method, $data);
        $request->setUserResolver(fn () => $user);
        return $request;
    }

    private function store(User $user, array $data)
    {
        return app(TourismTripController::class)->store($this->request($user, $data));
    }

    private function assertRejected(callable $attempt, array $fields): void
    {
        try {
            $attempt();
            $this->fail('Dados inválidos foram aceitos.');
        } catch (ValidationException $e) {
            foreach ($fields as $field) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        }
    }

    private function payments(): TourismPaymentService
    {
        return app(TourismPaymentService::class);
    }

    public function test_tourism_is_an_access_module_and_admin_sees_everything(): void
    {
        $this->assertSame('Turismo', User::AVAILABLE_MODULES['tourism']);
        $this->assertTrue($this->user('admin')->hasModule('tourism'));
        $this->assertTrue($this->user('manager', ['tourism'])->hasModule('tourism'));
        $this->assertFalse($this->user('manager', ['vouchers'])->hasModule('tourism'));

        $middleware = new CheckModule();
        auth()->setUser($this->user('manager', ['vouchers']));
        try {
            $middleware->handle(Request::create('/admin/turismo'), fn () => response('ok'), 'tourism');
            $this->fail('Gerente sem o módulo entrou no Turismo.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        auth()->setUser($this->user('manager', ['tourism']));
        $this->assertSame('ok', $middleware->handle(Request::create('/admin/turismo'), fn () => response('ok'), 'tourism')->getContent());
    }

    public function test_each_passenger_pays_is_no_longer_an_option(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $bus = $this->bus();
        // Sem quem paga, o que cobre e passageiros: recusado, mesmo pedindo "individual".
        $this->assertRejected(fn () => $this->store($employee, ['title' => 'X', 'bus_id' => $bus->id,
            'starts_at' => '2026-10-10T06:00', 'ends_at' => '2026-10-12T20:00', 'payment_mode' => 'individual']),
            ['responsible_name', 'responsible_phone', 'coverage', 'passengers_count']);

        $this->store($employee, $this->payload($bus, ['payment_mode' => 'individual']));
        $trip = TourismTrip::firstOrFail();
        $this->assertSame(['group', 'pending', $employee->id], [$trip->payment_mode, $trip->status, $trip->created_by]);
        $this->assertNull($trip->plan_details); // agora é opcional
        $this->assertSame('2 dias e 14h', $trip->durationLabel());
    }

    public function test_total_is_24h_days_times_passengers(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $this->store($employee, $this->groupPayload($this->bus(), ['agreed_amount' => '1.00']));
        $trip = TourismTrip::firstOrFail();
        $this->assertSame(['all_days', 'Sônia Maria'], [$trip->coverage, $trip->responsible_name]);
        $this->assertNull($trip->outbound_arrives_at);
        // 04/01 06:00 → 16/01 21:00 = 303h = 13 diárias de 24h x 13,98 = 181,74 por passageiro.
        $this->assertSame(181.74, $trip->estimate()['per_passenger']);
        $this->assertSame('13 diárias de 24h', $trip->estimate()['lines'][0]['plan']);
        // 53 passageiros = 9.632,22 — o "valor combinado" enviado é ignorado: quem calcula é o sistema.
        $this->assertSame(['13.98', '9632.22', '9632.22', '0.00'],
            [$trip->price_24h, $trip->subtotal_amount, $trip->agreed_amount, $trip->discount_percent]);
    }

    public function test_discount_up_to_10_percent_only_for_all_days(): void
    {
        $admin = $this->user('admin');
        $bus = $this->bus();
        $this->assertRejected(fn () => $this->store($admin, $this->payload($bus, ['discount_percent' => '10.5'])), ['discount_percent']);

        // 3 diárias x 13,98 = 41,94 x 40 = 1.677,60; 10% = 167,76 → 1.509,84.
        $this->store($admin, $this->payload($bus, ['discount_percent' => '10']));
        $trip = TourismTrip::firstOrFail();
        $this->assertSame(['1677.60', '10.00', '1509.84'], [$trip->subtotal_amount, $trip->discount_percent, $trip->agreed_amount]);
        $this->assertSame(167.76, $trip->quote()['discount']);

        // Ida e volta: desconto ignorado (campo escondido na tela).
        $trip->delete();
        $this->store($admin, $this->payload($bus, ['coverage' => 'round_trip', 'discount_percent' => '10',
            'outbound_arrives_at' => '2026-10-10T18:00', 'return_departs_at' => '2026-10-12T08:00']));
        $trip = TourismTrip::firstOrFail();
        // Ida 12h e volta 12h: 1 diária de 24h cada (nunca o plano de 12h) = 27,96 x 40 = 1.118,40.
        $this->assertSame(['0.00', '1118.40'], [$trip->discount_percent, $trip->agreed_amount]);
        $this->assertSame('1 diária de 24h', $trip->estimate()['lines'][0]['plan']);
    }

    public function test_round_trip_uses_contract_legs_in_order(): void
    {
        $admin = $this->user('admin');
        $bus = $this->bus();
        $this->assertRejected(fn () => $this->store($admin, $this->groupPayload($bus, ['coverage' => 'round_trip'])),
            ['outbound_arrives_at', 'return_departs_at']);
        $this->assertRejected(fn () => $this->store($admin, $this->groupPayload($bus, ['coverage' => 'round_trip',
            'outbound_arrives_at' => '2028-01-15T10:00', 'return_departs_at' => '2028-01-15T09:00'])), ['return_departs_at']);

        // Contrato: ida 04/01 06:00 → 05/01 09:00 (27h); volta 15/01 09:00 → 16/01 21:00 (36h).
        $this->store($admin, $this->groupPayload($bus, ['coverage' => 'round_trip',
            'outbound_arrives_at' => '2028-01-05T09:00', 'return_departs_at' => '2028-01-15T09:00']));
        $estimate = TourismTrip::firstOrFail()->estimate();
        $this->assertSame(['Ida', 27, '2 diárias de 24h', 27.96], array_values($estimate['lines'][0]));
        $this->assertSame(['Volta', 36, '2 diárias de 24h', 27.96], array_values($estimate['lines'][1]));
        $this->assertSame(55.92, $estimate['per_passenger']);
    }

    public function test_end_must_be_after_start(): void
    {
        $this->expectException(ValidationException::class);
        $this->store($this->user('admin'), $this->payload($this->bus(), ['ends_at' => '2026-10-10T05:00']));
    }

    public function test_same_bus_cannot_have_two_trips_at_the_same_time(): void
    {
        $admin = $this->user('admin');
        $bus = $this->bus();
        $this->store($admin, $this->payload($bus));

        try {
            $this->store($admin, $this->payload($bus, ['title' => 'Outro grupo',
                'starts_at' => '2026-10-12T10:00', 'ends_at' => '2026-10-14T10:00']));
            $this->fail('Viagens sobrepostas no mesmo ônibus foram aceitas.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Excursão Jalapão', $e->errors()['bus_id'][0]);
        }

        // Logo depois do fim, outro ônibus ou viagem cancelada: tudo bem.
        $this->store($admin, $this->payload($bus, ['starts_at' => '2026-10-12T20:00', 'ends_at' => '2026-10-13T20:00']));
        $this->store($admin, $this->payload($this->bus('OUTRO')));
        TourismTrip::where('title', 'Excursão Jalapão')->first()->update(['status' => 'cancelled']);
        $this->store($admin, $this->payload($bus, ['title' => 'Novo grupo', 'starts_at' => '2026-10-10T08:00', 'ends_at' => '2026-10-11T08:00']));
        $this->assertSame(4, TourismTrip::count());
    }

    public function test_payment_link_creates_pagbank_pix_and_webhook_marks_paid(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $redirect = $this->store($employee, $this->payload($this->bus(), ['discount_percent' => '10']));
        $trip = TourismTrip::firstOrFail();
        $this->assertStringEndsWith("/admin/turismo/{$trip->id}/editar", $redirect->getTargetUrl());

        app(TourismTripController::class)->generatePaymentLink($trip, $this->payments());
        $trip->refresh();
        $this->assertSame('pending', $trip->payment_status);
        $this->assertSame(32, strlen($trip->payment_token));
        $this->assertSame('PIXCODE-ORDE_1', $trip->pix_code);
        $this->assertSame("/turismo/pagamento/{$trip->payment_token}", parse_url($trip->paymentUrl(), PHP_URL_PATH));
        // Valor total com desconto, webhook próprio do turismo (não o do portal).
        $this->assertSame(150984, $this->orders['ORDE_1']['cents']);
        $this->assertSame('https://wifi.test/api/tourism/webhook/pagbank', $this->orders['ORDE_1']['notify']);
        $this->assertStringStartsWith("TUR{$trip->id}_", $this->orders['ORDE_1']['ref']);

        // Webhook falso dizendo "PAID" não basta: o PagBank ainda diz WAITING.
        $webhook = ['id' => 'ORDE_1', 'reference_id' => $this->orders['ORDE_1']['ref'], 'charges' => [['status' => 'PAID']]];
        app(TourismPaymentController::class)->webhook(Request::create('/', 'POST', $webhook), $this->payments());
        $this->assertFalse($trip->fresh()->isPaid());

        $this->orders['ORDE_1']['status'] = 'PAID';
        $response = app(TourismPaymentController::class)->webhook(Request::create('/', 'POST', $webhook), $this->payments());
        $this->assertTrue($response->getData(true)['confirmed']);
        $trip->refresh();
        $this->assertSame(['paid', '1509.84', 'ORDE_1'], [$trip->payment_status, $trip->paid_amount, $trip->pagbank_order_id]);
        $this->assertNotNull($trip->paid_at);

        // Pedido de outra origem (portal) não mexe no turismo.
        $this->assertFalse($this->payments()->handleWebhook(['id' => 'ORDE_9', 'reference_id' => 'WIFI_123']));
    }

    public function test_public_page_shows_pix_and_regenerates_when_amount_changes(): void
    {
        $admin = $this->user('admin');
        $this->store($admin, $this->payload($this->bus()));
        $trip = TourismTrip::firstOrFail();
        $this->payments()->createLink($trip);
        $controller = app(TourismPaymentController::class);

        $page = $controller->show($trip->payment_token, $this->payments())->render();
        foreach (['Excursão Jalapão', 'Sônia Maria', '40 passageiros × R$ 41,94', 'R$ 1.677,60', 'PIXCODE-ORDE_1', 'Aguardando pagamento'] as $text) {
            $this->assertStringContainsString($text, $page);
        }
        $this->assertCount(1, $this->orders);

        // Mudou o número de passageiros antes de pagar: o mesmo link cobra o novo valor.
        app(TourismTripController::class)->update($this->request($admin, $this->payload($trip->bus, [
            'passengers_count' => 30, 'status' => 'pending']), 'PUT'), $trip->fresh());
        $page = $controller->show($trip->payment_token, $this->payments())->render();
        $this->assertStringContainsString('PIXCODE-ORDE_2', $page);
        $this->assertSame(125820, $this->orders['ORDE_2']['cents']);

        // Pagou: a página consulta o PagBank e mostra a confirmação.
        $this->orders['ORDE_2']['status'] = 'PAID';
        Carbon::setTestNow(now()->addSeconds(11)); // consulta ao PagBank é limitada a cada 10s
        $status = $controller->status($trip->payment_token, $this->payments())->getData(true);
        $this->assertTrue($status['paid']);
        $this->assertStringContainsString('Pagamento confirmado!', $controller->show($trip->payment_token, $this->payments())->render());

        // Depois de pago, o valor não pode mudar.
        $this->assertRejected(fn () => app(TourismTripController::class)->update($this->request($admin,
            $this->payload($trip->bus, ['passengers_count' => 31, 'status' => 'pending']), 'PUT'), $trip->fresh()), ['passengers_count']);
    }

    public function test_old_trip_without_calculated_value_cannot_get_a_link(): void
    {
        $this->store($this->user('admin'), $this->payload($this->bus()));
        $trip = TourismTrip::firstOrFail();
        $trip->forceFill(['price_24h' => null])->save();

        $this->expectException(\RuntimeException::class);
        $this->payments()->createLink($trip);
    }

    public function test_screens_render_for_employee_and_administrator(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $admin = $this->user('admin');
        $this->store($employee, $this->groupPayload($this->bus(), ['coverage' => 'round_trip',
            'outbound_arrives_at' => '2028-01-05T09:00', 'return_departs_at' => '2028-01-15T09:00',
            'plan_details' => 'Liberar só na ida e na volta.']));
        $trip = TourismTrip::firstOrFail();
        $controller = app(TourismTripController::class);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        auth()->setUser($employee);
        $list = $controller->index(Request::create('/admin/turismo'))->render();
        foreach (['Contrato 01/2026', 'Sônia Maria', 'Somente ida e volta', '2 diárias de 24h + 2 diárias de 24h',
                     'R$ 55,92/passageiro', 'R$ 2.963,76', 'Gerar link de pagamento', 'Liberar só na ida e na volta.', 'Aguardando administrador'] as $text) {
            $this->assertStringContainsString($text, $list);
        }
        $form = $controller->create()->render();
        $this->assertStringContainsString('Nº do contrato', $form);
        $this->assertStringContainsString('Ônibus HH60A2NSBE7 — ABC1D23', $form);
        $this->assertStringContainsString('data-price-day="13.98"', $form);
        $this->assertStringNotContainsString('Cada passageiro paga o seu', $form);
        $this->assertStringContainsString('(até 10%)</span>', $form);
        $edit = $controller->edit($this->request($employee, [], 'GET'), $trip)->render();
        $this->assertStringNotContainsString('Área do administrador', $edit);
        $this->assertStringContainsString('Gerar link de pagamento (PIX)', $edit);

        $this->payments()->createLink($trip);
        $edit = $controller->edit($this->request($employee, [], 'GET'), $trip->fresh())->render();
        $this->assertStringContainsString('Enviar no WhatsApp', $edit);
        $this->assertStringContainsString('wa.me/5563984885877', $edit);

        auth()->setUser($admin);
        $this->assertStringContainsString('Área do administrador', $controller->edit($this->request($admin, [], 'GET'), $trip)->render());
    }

    public function test_only_administrator_changes_status_and_treated_trips_are_locked_for_employees(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $admin = $this->user('admin');
        $this->store($employee, $this->payload($this->bus()));
        $trip = TourismTrip::firstOrFail();
        $controller = app(TourismTripController::class);

        // Funcionária edita enquanto aguarda, mas não consegue mudar o status.
        $controller->update($this->request($employee, $this->payload($trip->bus, ['title' => 'Editado', 'status' => 'configured']), 'PUT'), $trip);
        $this->assertSame(['Editado', 'pending'], [$trip->fresh()->title, $trip->fresh()->status]);

        $controller->update($this->request($admin, $this->payload($trip->bus, ['status' => 'configured',
            'admin_notes' => 'Ônibus liberado para o grupo.']), 'PUT'), $trip->fresh());
        $this->assertSame('configured', $trip->fresh()->status);
        $this->assertSame('Ônibus liberado para o grupo.', $trip->fresh()->admin_notes);

        foreach ([fn () => $controller->edit($this->request($employee, [], 'GET'), $trip->fresh()),
                  fn () => $controller->destroy($this->request($employee, [], 'DELETE'), $trip->fresh())] as $attempt) {
            try {
                $attempt();
                $this->fail('Funcionária alterou viagem já tratada pelo administrador.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }

        $controller->destroy($this->request($admin, [], 'DELETE'), $trip->fresh());
        $this->assertSame(0, TourismTrip::count());
    }
}
