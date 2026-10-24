<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quais dados do passageiro são compartilhados com o patrocinador.
 *
 * O texto de autorização no portal é montado a partir destes campos: o
 * passageiro precisa ver exatamente quais dados saem e para quem, senão
 * o consentimento não cobre o envio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discount_sponsors', function (Blueprint $table) {
            $table->boolean('shares_phone')->default(false)->after('collects_cpf');
            // Nome do parceiro como aparece na autorização, quando diferente do
            // nome interno (ex.: razão social exigida em texto de consentimento).
            $table->string('legal_name')->nullable()->after('name');
            // Finalidade declarada ao passageiro ("consultar uma proposta de crédito").
            $table->string('data_purpose')->nullable()->after('shares_phone');
        });
    }

    public function down(): void
    {
        Schema::table('discount_sponsors', function (Blueprint $table) {
            $table->dropColumn(['shares_phone', 'legal_name', 'data_purpose']);
        });
    }
};
