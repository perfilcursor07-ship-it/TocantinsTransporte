<?php

namespace Tests\Feature;

use App\Http\Controllers\ReportsController;
use App\Models\Bus;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportsPaymentEditTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__ . '/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array',
            'session.driver' => 'array',
            'logging.default' => 'null',
        ]);
        DB::purge('sqlite');

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('status')->default('pending');
            $table->string('role')->default('user');
            $table->string('last_mikrotik_id')->nullable();
            $table->dateTime('connected_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->string('mikrotik_serial')->unique();
            $table->string('name');
            $table->string('plate')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->decimal('amount', 10, 2);
            $table->string('payment_type');
            $table->string('status');
            $table->string('bus_mikrotik_id')->nullable();
            $table->json('payment_data')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wifi_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->dateTime('started_at')->nullable();
            $table->string('session_status')->default('ended');
            $table->timestamps();
        });
    }

    public function test_admin_can_change_amount_and_report_totals_use_new_value(): void
    {
        $admin = User::create([
            'name' => 'Administrador',
            'email' => 'admin@example.test',
            'password' => 'secret',
            'role' => 'admin',
        ]);
        $passenger = User::create([
            'name' => 'Passageiro',
            'last_mikrotik_id' => '5041',
        ]);
        Bus::create(['mikrotik_serial' => '5041', 'name' => '5041 - HH50A914NK5']);
        Bus::create(['mikrotik_serial' => '5033', 'name' => '5033 - HH50A2ER2JB']);
        $payment = Payment::create([
            'user_id' => $passenger->id,
            'amount' => 6.99,
            'payment_type' => 'pix',
            'status' => 'completed',
            'paid_at' => now(),
            'payment_data' => [],
        ]);
        $this->actingAs($admin);

        $request = Request::create('/admin/reports/payments/' . $payment->id, 'PATCH', [
            'amount' => '5.99',
            'mikrotik_serial' => '5033',
        ]);
        $controller = app(ReportsController::class);
        $controller->updatePaymentRecord($request, $payment);

        $payment->refresh();
        $this->assertSame('5.99', $payment->amount);
        $this->assertSame('5033', data_get($payment->payment_data, 'transferred_mikrotik_id'));
        $this->assertSame(6.99, data_get($payment->payment_data, 'amount_edited_from'));
        $this->assertSame($admin->id, data_get($payment->payment_data, 'amount_edited_by'));

        $method = new \ReflectionMethod(ReportsController::class, 'getGeneralStats');
        $stats = $method->invoke(
            $controller,
            now()->subDay()->format('Y-m-d H:i:s'),
            now()->addDay()->format('Y-m-d H:i:s'),
            'all',
            'all',
            'all'
        );

        $this->assertSame(5.99, $stats['total_revenue']);
        $this->assertSame(5.99, $stats['completed_revenue']);
        $this->assertSame(5.99, $stats['avg_payment']);
    }
}
