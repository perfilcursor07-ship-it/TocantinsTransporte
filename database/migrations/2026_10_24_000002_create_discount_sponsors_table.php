<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Patrocinadores do desconto.
 *
 * O portal tem UM espaço publicitário antes do pagamento: o passageiro vê o
 * conteúdo do patrocinador e ganha desconto no plano. Quem ocupa esse espaço
 * é configurável, sem mexer em código:
 *
 *   video  — um MP4 nosso ou do parceiro. Sem integração nenhuma.
 *   iframe — uma página do parceiro embutida num modal. Exige que ele libere
 *            frame-ancestors para o nosso domínio.
 *   api    — handoff: nosso servidor chama a API do parceiro, recebe um link
 *            e leva o passageiro até lá. É o caso da Cash Cash.
 *
 * Só um patrocinador fica ativo por vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discount_sponsors', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug', 64)->unique();
            $table->string('type', 16); // video | iframe | api
            $table->boolean('is_active')->default(false);

            // Texto do botão no portal. Vazio usa o padrão.
            $table->string('headline')->nullable();
            $table->string('subheadline')->nullable();

            // Sobrescrevem as configurações globais quando preenchidos.
            $table->decimal('discount_amount', 8, 2)->nullable();
            $table->unsignedSmallInteger('min_seconds')->nullable();

            // Pede CPF antes de abrir o conteúdo (exigido por parceiros de crédito).
            $table->boolean('collects_cpf')->default(false);

            // Configuração específica do tipo. Credenciais vão daqui para o
            // .env por referência (nunca o segredo em si — ver driver).
            $table->json('config')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discount_sponsors');
    }
};
