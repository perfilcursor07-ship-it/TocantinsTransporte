<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turismo: o sistema calcula o valor (passageiros x diárias de 24h, com
     * desconto de até 10% quando cobre todos os dias) e gera um link de
     * pagamento PIX PagBank para o responsável. Fica separado da tabela
     * payments — não mexe no fluxo de pagamento do portal.
     */
    public function up(): void
    {
        Schema::table('tourism_trips', function (Blueprint $table) {
            $table->decimal('price_24h', 8, 2)->nullable()->after('agreed_amount');        // preço da diária no cadastro
            $table->decimal('subtotal_amount', 10, 2)->nullable()->after('price_24h');     // antes do desconto
            $table->decimal('discount_percent', 4, 2)->default(0)->after('subtotal_amount');
            $table->string('payment_token', 40)->nullable()->unique()->after('discount_percent');
            $table->string('payment_status', 20)->nullable()->after('payment_token');     // pending | paid
            $table->string('pagbank_order_id', 64)->nullable()->after('payment_status');
            $table->string('pagbank_reference_id', 64)->nullable()->after('pagbank_order_id');
            $table->text('pix_code')->nullable()->after('pagbank_reference_id');
            $table->decimal('pix_amount', 10, 2)->nullable()->after('pix_code');
            $table->dateTime('pix_expires_at')->nullable()->after('pix_amount');
            $table->dateTime('paid_at')->nullable()->after('pix_expires_at');
            $table->decimal('paid_amount', 10, 2)->nullable()->after('paid_at');
            $table->index('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('tourism_trips', function (Blueprint $table) {
            $table->dropIndex(['payment_status']);
            $table->dropUnique(['payment_token']);
            $table->dropColumn(['price_24h', 'subtotal_amount', 'discount_percent', 'payment_token', 'payment_status',
                'pagbank_order_id', 'pagbank_reference_id', 'pix_code', 'pix_amount', 'pix_expires_at', 'paid_at', 'paid_amount']);
        });
    }
};
