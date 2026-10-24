<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Leads enviados ao Cash Cash no lugar do vídeo de desconto.
 *
 * Guarda a evidência do consentimento (exigida pelo parceiro) e o handoff_id,
 * que é o identificador pedido em qualquer chamado de suporte deles.
 *
 * NUNCA guardar aqui a url do handoff nem o token: enquanto não expira,
 * a url dá acesso à análise de crédito daquele CPF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_cash_leads', function (Blueprint $table) {
            $table->id();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cpf', 11);
            $table->string('phone', 20)->nullable();
            $table->string('mac_address', 32)->nullable();

            // Evidência do consentimento enviada ao parceiro
            $table->timestamp('consent_accepted_at');
            $table->string('consent_ip', 45);
            $table->string('consent_user_agent', 1024);
            $table->decimal('consent_latitude', 10, 7);
            $table->decimal('consent_longitude', 10, 7);
            $table->string('consent_location_source', 8)->default('ip');

            // Resultado da chamada
            $table->string('status', 24)->default('pending');
            $table->uuid('handoff_id')->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('link_expires_at')->nullable();

            // Permanência no app (substitui o "assistiu o vídeo até o fim")
            $table->timestamp('link_opened_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->unsignedInteger('dwell_seconds')->nullable();

            // Desconto concedido
            $table->timestamp('discount_granted_at')->nullable();
            $table->decimal('discount_amount', 8, 2)->nullable();

            $table->timestamps();

            $table->index(['mac_address', 'discount_granted_at']);
            $table->index('handoff_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_cash_leads');
    }
};
