<?php

namespace Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntervalTestDatabase
{
    public static function create(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array', 'logging.default' => 'null']);
        DB::purge('sqlite');
        Schema::create('system_settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps();
        });
        Schema::create('users', function (Blueprint $t) {
            $t->id(); $t->string('name')->nullable(); $t->string('email')->nullable();
            $t->string('phone')->nullable(); $t->string('password')->nullable();
            $t->string('mac_address')->nullable()->unique(); $t->string('ip_address')->nullable();
            $t->string('status')->default('pending'); $t->string('role')->default('user');
            $t->string('last_mikrotik_id')->nullable(); $t->dateTime('connected_at')->nullable();
            $t->dateTime('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->decimal('amount', 10, 2);
            $t->string('payment_type'); $t->string('status'); $t->string('transaction_id')->nullable();
            $t->string('gateway_payment_id')->nullable(); $t->text('pix_emv_string')->nullable();
            $t->text('pix_location')->nullable(); $t->json('payment_data')->nullable();
            // O model grava este campo em todo pagamento criado (ônibus congelado).
            $t->string('bus_mikrotik_id')->nullable();
            $t->dateTime('paid_at')->nullable(); $t->timestamps();
        });
        Schema::create('temp_bypass_logs', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id'); $t->foreignId('payment_id');
            $t->string('mac_address'); $t->boolean('was_denied')->default(false);
            $t->string('phone')->nullable(); $t->string('ip_address')->nullable();
            $t->integer('bypass_number')->default(0); $t->string('deny_reason')->nullable();
            $t->dateTime('expires_at')->nullable(); $t->timestamps();
        });
        Schema::create('wifi_sessions', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id'); $t->foreignId('payment_id');
            $t->dateTime('started_at'); $t->dateTime('ended_at')->nullable();
            $t->string('session_status'); $t->timestamps();
        });
        Schema::create('mikrotik_mac_reports', function (Blueprint $t) {
            $t->id(); $t->string('mac_address'); $t->string('ip_address');
            $t->string('mikrotik_id')->nullable(); $t->string('mikrotik_ip')->nullable();
            $t->string('transaction_id')->nullable(); $t->dateTime('reported_at')->nullable();
            $t->dateTime('last_seen')->nullable(); $t->timestamps();
        });
        Schema::create('buses', function (Blueprint $t) {
            $t->id(); $t->string('mikrotik_serial')->unique(); $t->string('name');
            $t->string('last_public_ip')->nullable(); $t->dateTime('last_sync_at')->nullable(); $t->timestamps();
        });
        Schema::create('service_tickets', function (Blueprint $t) {
            $t->id(); $t->string('status');
        });
        (require base_path('database/migrations/2026_09_10_000001_create_interval_access_days_table.php'))->up();
    }
}
