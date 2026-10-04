<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\TourismTripController;
use App\Http\Middleware\CheckModule;
use App\Models\Bus;
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
        (require base_path('database/migrations/2026_04_03_000001_create_buses_table.php'))->up();
        (require base_path('database/migrations/2026_10_04_000001_create_tourism_trips_table.php'))->up();
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
            'title' => 'Excursão Jalapão', 'bus_id' => $bus->id,
            'starts_at' => '2026-10-10T06:00', 'ends_at' => '2026-10-12T20:00',
            'payment_mode' => 'individual', 'plan' => 'varios_dias', 'passengers_count' => 40,
        ], $overrides);
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

    public function test_tourism_is_an_access_module_and_admin_sees_everything(): void
    {
        $this->assertSame('Turismo', User::AVAILABLE_MODULES['tourism']);
        $this->assertTrue($this->user('admin')->hasModule('tourism'));
        $this->assertTrue($this->user('manager', ['tourism'])->hasModule('tourism'));
        $this->assertFalse($this->user('manager', ['vouchers'])->hasModule('tourism'));

        $middleware = new CheckModule();
        $manager = $this->user('manager', ['vouchers']);
        auth()->setUser($manager);
        try {
            $middleware->handle(Request::create('/admin/turismo'), fn () => response('ok'), 'tourism');
            $this->fail('Gerente sem o módulo entrou no Turismo.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        auth()->setUser($this->user('manager', ['tourism']));
        $this->assertSame('ok', $middleware->handle(Request::create('/admin/turismo'), fn () => response('ok'), 'tourism')->getContent());
    }

    public function test_employee_registers_trip_as_pending_for_the_administrator(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $this->store($employee, $this->payload($this->bus()));

        $trip = TourismTrip::firstOrFail();
        $this->assertSame('pending', $trip->status);
        $this->assertSame($employee->id, $trip->created_by);
        $this->assertSame('2026-10-10 06:00:00', $trip->starts_at->toDateTimeString());
        $this->assertSame('2 dias e 14h', $trip->durationLabel());
    }

    public function test_group_payment_requires_who_pays_and_individual_clears_it(): void
    {
        $employee = $this->user('manager', ['tourism']);
        $bus = $this->bus();
        try {
            $this->store($employee, $this->payload($bus, ['payment_mode' => 'group']));
            $this->fail('Pagamento em grupo sem responsável foi aceito.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('responsible_name', $e->errors());
            $this->assertArrayHasKey('responsible_phone', $e->errors());
        }

        $this->store($employee, $this->payload($bus, ['payment_mode' => 'group',
            'responsible_name' => 'Maria', 'responsible_phone' => '63981013050', 'agreed_amount' => '350.00']));
        $this->assertSame('Maria', TourismTrip::first()->responsible_name);

        $this->store($employee, $this->payload($this->bus('OUTRO'), ['payment_mode' => 'individual',
            'responsible_name' => 'Ignorar', 'agreed_amount' => '10']));
        $individual = TourismTrip::where('payment_mode', 'individual')->firstOrFail();
        $this->assertNull($individual->responsible_name);
        $this->assertNull($individual->agreed_amount);
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
        $this->store($employee, $this->payload($this->bus(), ['payment_mode' => 'group',
            'responsible_name' => 'Maria Souza', 'responsible_phone' => '63981013050', 'notes' => 'Saída 6h']));
        $trip = TourismTrip::firstOrFail();
        $controller = app(TourismTripController::class);
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        auth()->setUser($employee);
        $list = $controller->index(Request::create('/admin/turismo'))->render();
        $this->assertStringContainsString('Excursão Jalapão', $list);
        $this->assertStringContainsString('Maria Souza', $list);
        $this->assertStringContainsString('Aguardando administrador', $list);
        $form = $controller->create()->render();
        $this->assertStringContainsString('Ônibus HH60A2NSBE7 — ABC1D23', $form);
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
