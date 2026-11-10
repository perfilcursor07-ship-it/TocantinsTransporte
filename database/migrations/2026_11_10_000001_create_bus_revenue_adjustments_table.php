<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acréscimos manuais na receita de um ônibus, lançados pelo admin no relatório.
 *
 * Tabela própria de propósito: um acréscimo NÃO é um pagamento. Se fosse uma
 * linha em payments, o MikroTik (autoHealPaidUsers) poderia tratar o dono como
 * pagante e liberar internet. Aqui ele só entra nas somas de receita.
 *
 * reference_at é a data em que o acréscimo conta. Ela fica sempre dentro do
 * período que o admin estava vendo, então meses fora dele não mudam.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bus_revenue_adjustments')) {
            return;
        }

        Schema::create('bus_revenue_adjustments', function (Blueprint $table) {
            $table->id();
            $table->string('bus_mikrotik_id', 100);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_amount', 10, 2);
            $table->decimal('total', 10, 2);
            $table->dateTime('reference_at');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bus_mikrotik_id', 'reference_at']);
            $table->index('reference_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bus_revenue_adjustments');
    }
};
