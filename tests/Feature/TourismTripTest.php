<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TourismTripController;
use App\Http\Middleware\CheckModule;
use App\Models\Bus;
use App\Models\SystemSetting;
use App\Models\TourismTrip;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class TourismTripTest extends TestCase
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
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array', 'logging.default' => 'null',
            'app.timezone' => 'America/Araguaina']);
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
        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        SystemSetting::setValue('wifi_price_full', '6.99');
        SystemSetting::setValue('session_duration', '12');
        SystemSetting::setValue('plan_interval_price_24h', '13.98');
        Carbon::setTestNow(Carbon::parse('2026-10-04 09:00:00', 'America/Araguaina'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role, array $modules = []): User
    {
        return User::create(['name' => ucfirst($role), 'role' => $role, 'allowed_modules' => $modules]);
    }

    private function bus(string $serial = 'HH60A2NSBE7'): Bus
    {
        return Bus::create(['mikrotik_serial' => $serial, 'name' => "Ônibus {$serial}", 'plate' => 'ABC1D23']);
    }

    private function payload(Bus $bus, array $overrides = []): array
    {
        return array_merge([
            'contract_number' => '01/2026', 'title' => 'Excursão Jalapão', 'bus_id' => $bus->id,
            'starts_at' => '2026-10-10T06:00', 'ends_at' => '2026-10-12T20:00',
            'payment_mode' => 'individual', 'passengers_count' => 40,
        ], $overrides);
    }

    /** Uma pessoa paga tudo, com as datas do contrato 01/2026 (ida 04/01 → volta 16/01). */
    private function groupPayload(Bus $bus, array $overrides = []): array
    {
        return $this->payload($bus, array_merge([
            'title' => 'Sônia Maria — Campos do Jordão / Gramado', 'passengers_count' => 53,
            'starts_at' => '2028-01-04T06:00', 'ends_at' => '2028-01-16T21:00',
            'payment_mode' => 'group', 'responsible_name' => 'Sônia Maria', 'responsible_phone' => '(63) 9 8488-5877',
            'coverage' => 'all_days', 'plan_details' => 'Sônia paga a internet de todos.',
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

    public function test_each_passenger_pays_needs_no_plan_and_ignores_hidden_group_fields(): void
    {
        $employee = $this->user('manager', ['tourism']);
        // Campos do grupo que ficaram preenchidos/escondidos na tela não podem atrapalhar.
        $this->store($employee, $this->payload($this->bus(), [
            'coverage' => 'round_trip', 'responsible_name' => 'Ignorar', 'agreed_amount' => '10', 'plan_details' => 'x',
        ]));

        $trip = TourismTrip::firstOrFail();
        $this->assertSame(['pending', $employee->id, '01/2026'], [$trip->status, $trip->created_by, $trip->contract_number]);
        $this->assertNull($trip->plan);
        $this->assertNull($trip->coverage);
        $this->assertNull($trip->responsible_name);
        $this->assertNull($trip->agreed_amount);
        $this->assertNull($trip->plan_details);
        $this->assertSame('Cada passageiro escolhe no portal', $trip->planLabel());
        $this->assertSame('2 dias e 14h', $trip->durationLabel());
    }

    public function test_one_person_pays_must_say_who_what_it_covers_and_how(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $bus = $this->bus();
        $this->assertRejected(fn () => $this->store($employee, $this->payload($bus, ['payment_mode' => 'group'])),
            ['responsible_name', 'responsible_phone', 'coverage', 'plan_details']);

        $this->store($employee, $this->groupPayload($bus, ['agreed_amount' => '9640.00']));
        $trip = TourismTrip::firstOrFail();
        $this->assertSame(['all_days', 'Sônia Maria'], [$trip->coverage, $trip->responsible_name]);
        $this->assertNull($trip->outbound_arrives_at);
        // 04/01 06:00 → 16/01 21:00 = 303h = 13 diárias de 24h.
        $this->assertSame(181.74, $trip->estimate()['per_passenger']);
        $this->assertSame('13 diárias de 24h', $trip->estimate()['lines'][0]['plan']);
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

    public function test_short_leg_uses_12_hour_plan(): void
    {
        $windows = [['label' => 'Ida', 'start' => Carbon::parse('2026-10-10 06:00'), 'end' => Carbon::parse('2026-10-10 17:30')]];
        $estimate = TourismTrip::estimateWindows($windows, TourismTrip::pricing());
        $this->assertSame(['Ida', 12, 'plano de 12 horas', 6.99], array_values($estimate['lines'][0]));
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
                     'R$ 55,92/passageiro', 'Liberar só na ida e na volta.', 'Aguardando administrador'] as $text) {
            $this->assertStringContainsString($text, $list);
        }
        $form = $controller->create()->render();
        $this->assertStringContainsString('Nº do contrato', $form);
        $this->assertStringContainsString('Ônibus HH60A2NSBE7 — ABC1D23', $form);
        $this->assertStringContainsString('data-price-day="13.98"', $form);
        $this->assertStringNotContainsString('Área do administrador', $controller->edit($this->request($employee, [], 'GET'), $trip)->render());

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
