<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generaliza os leads: deixam de ser "da Cash Cash" e passam a ser
 * "do patrocinador ativo", qualquer que seja ele.
 *
 * O CPF vira opcional — patrocinador de vídeo ou iframe não coleta CPF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('cash_cash_leads', 'discount_leads');

        Schema::table('discount_leads', function (Blueprint $table) {
            $table->foreignId('sponsor_id')->nullable()->after('id')
                ->constrained('discount_sponsors')->nullOnDelete();
        });

        // Campos que só fazem sentido para patrocinador de crédito.
        // doctrine/dbal não é garantido aqui, então vai em SQL cru.
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            \DB::statement('ALTER TABLE discount_leads MODIFY cpf VARCHAR(11) NULL');
            \DB::statement('ALTER TABLE discount_leads MODIFY consent_ip VARCHAR(45) NULL');
            \DB::statement('ALTER TABLE discount_leads MODIFY consent_user_agent VARCHAR(1024) NULL');
            \DB::statement('ALTER TABLE discount_leads MODIFY consent_latitude DECIMAL(10,7) NULL');
            \DB::statement('ALTER TABLE discount_leads MODIFY consent_longitude DECIMAL(10,7) NULL');
            \DB::statement('ALTER TABLE discount_leads MODIFY consent_accepted_at TIMESTAMP NULL');
        }
    }

    public function down(): void
    {
        Schema::table('discount_leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sponsor_id');
        });

        Schema::rename('discount_leads', 'cash_cash_leads');
    }
};
