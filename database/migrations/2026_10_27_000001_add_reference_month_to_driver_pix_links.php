<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mês de competência do link de cadastro.
 *
 * Permite gerar link para um mês passado quando o pagamento atrasou: o
 * motorista que abrir aquele link confirma os dados daquele mês, não do atual.
 *
 * NULL = mês corrente no momento do acesso — é como os links já existentes
 * se comportam hoje, então nada muda para eles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('driver_pix_registration_links', function (Blueprint $table) {
            $table->date('reference_month')->nullable()->after('label');
        });
    }

    public function down(): void
    {
        Schema::table('driver_pix_registration_links', function (Blueprint $table) {
            $table->dropColumn('reference_month');
        });
    }
};
