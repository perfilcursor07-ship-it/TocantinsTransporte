<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Congela o ônibus no próprio pagamento.
 *
 * Antes, o relatório descobria o ônibus na hora de rodar, por
 * users.last_mikrotik_id — o ÚLTIMO ônibus em que o passageiro se conectou.
 * Quando ele viajava de novo em outro carro, todos os pagamentos passados dele
 * migravam retroativamente, e a receita de um mês fechado mudava de dono.
 *
 * Coluna anulável de propósito: enquanto estiver nula, o relatório continua
 * caindo no last_mikrotik_id, que é exatamente o comportamento atual. Assim
 * nada regride se o preenchimento falhar.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('payments', 'bus_mikrotik_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->string('bus_mikrotik_id', 100)->nullable()->after('payment_data');
            $table->index('bus_mikrotik_id');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('payments', 'bus_mikrotik_id')) {
            return;
        }

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['bus_mikrotik_id']);
            $table->dropColumn('bus_mikrotik_id');
        });
    }
};
