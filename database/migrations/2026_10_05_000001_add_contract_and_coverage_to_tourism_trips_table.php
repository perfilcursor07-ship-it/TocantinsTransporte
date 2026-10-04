<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turismo: número do contrato e, quando uma pessoa paga tudo, se a internet
     * cobre todos os dias ou só a ida e a volta (com os horários de cada trecho)
     * e a descrição de como o plano será feito.
     */
    public function up(): void
    {
        Schema::table('tourism_trips', function (Blueprint $table) {
            $table->string('contract_number', 30)->nullable()->after('id');
            $table->string('coverage', 20)->nullable()->after('payment_mode');      // all_days | round_trip
            $table->dateTime('outbound_arrives_at')->nullable()->after('starts_at'); // chegada da ida
            $table->dateTime('return_departs_at')->nullable()->after('outbound_arrives_at'); // saída da volta
            $table->text('plan_details')->nullable()->after('plan');
            $table->index('contract_number');
        });

        // Passageiro que paga o próprio plano escolhe no portal: plano deixa de ser obrigatório.
        Schema::table('tourism_trips', function (Blueprint $table) {
            $table->string('plan', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('tourism_trips', function (Blueprint $table) {
            $table->dropIndex(['contract_number']);
            $table->dropColumn(['contract_number', 'coverage', 'outbound_arrives_at', 'return_departs_at', 'plan_details']);
        });
    }
};
