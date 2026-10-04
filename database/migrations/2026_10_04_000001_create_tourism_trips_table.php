<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Viagens de turismo cadastradas pela equipe. Por enquanto é só o registro:
     * o administrador decide depois como configurar o ônibus (nada muda no MikroTik).
     */
    public function up(): void
    {
        Schema::create('tourism_trips', function (Blueprint $table) {
            $table->id();
            $table->string('title', 120);
            $table->foreignId('bus_id')->constrained('buses')->restrictOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->string('payment_mode', 20);          // individual | group
            $table->string('plan', 30);                  // viagem_completa | varios_dias | personalizado
            $table->string('responsible_name', 120)->nullable();
            $table->string('responsible_phone', 20)->nullable();
            $table->decimal('agreed_amount', 10, 2)->nullable();
            $table->unsignedSmallInteger('passengers_count')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('pending'); // pending | configured | cancelled
            $table->text('admin_notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bus_id', 'starts_at', 'ends_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tourism_trips');
    }
};
