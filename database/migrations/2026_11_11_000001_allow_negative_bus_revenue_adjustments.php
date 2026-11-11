<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permite ajuste de receita com quantidade negativa.
 *
 * A conciliação de um período com um relatório já emitido às vezes precisa
 * TIRAR pagamentos da conta de um ônibus sem apagar o pagamento real do
 * passageiro. O total já aceitava negativo; a quantidade era unsigned.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('bus_revenue_adjustments')) {
            return;
        }

        Schema::table('bus_revenue_adjustments', function (Blueprint $table) {
            $table->integer('quantity')->change();
        });
    }

    public function down(): void
    {
        // Voltar para unsigned falharia se já houver ajuste negativo gravado.
    }
};
