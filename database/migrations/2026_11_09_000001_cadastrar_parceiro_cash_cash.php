<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastra a Cash Cash (produção) em /admin/parceiros.
 *
 * O portal já fala com a Cash Cash pelo .env; o cadastro serve para o texto
 * de autorização dizer PARA QUEM o CPF vai e com qual finalidade — sem ele o
 * passageiro vê "Autorizo o envio dos meus dados para o parceiro", que não
 * informa nada (LGPD: o consentimento tem de ser informado).
 *
 * Nenhum segredo aqui: o cadastro guarda só o NOME das variáveis. Os valores
 * de CASHCASH_CLIENT_ID e CASHCASH_CLIENT_SECRET ficam apenas no .env.
 *
 * Rodar de novo é seguro: atualiza o cadastro existente em vez de duplicar.
 */
return new class extends Migration
{
    private const SLUG = 'cash-cash';

    public function up(): void
    {
        if (!Schema::hasTable('discount_sponsors')) {
            return;
        }

        $dados = [
            'name' => 'Cash Cash',
            'type' => 'api',
            'collects_cpf' => true,
            // O contrato da Cash Cash recusa telefone (400): só o CPF sai.
            'shares_phone' => false,
            // O envio do CPF já inicia uma análise de crédito no app deles —
            // o passageiro precisa saber disso ao autorizar.
            'data_purpose' => 'fazer uma análise de crédito e me apresentar ofertas',
            'config' => json_encode([
                'base_url' => 'https://gw-694e.cashcash.com.br/leads',
                'token_path' => '/partner/token',
                'session_path' => '/partner/handoffs',
                'style' => 'cashcash',
                // O app recusa iframe (guia v1.0 e confirmado por e-mail em 09/11/2026).
                'open_in' => 'redirect',
                'requires_location' => true,
                'client_id_env' => 'CASHCASH_CLIENT_ID',
                'client_secret_env' => 'CASHCASH_CLIENT_SECRET',
            ]),
            'notes' => "Produção desde 09/11/2026. Contato: Bruna Queiroz (Cash Cash).\n"
                . "App não permite iframe — manter Redirect.\n"
                . 'Teste no servidor: php artisan cashcash:testar',
            'updated_at' => now(),
        ];

        // Reaproveita um cadastro Cash Cash que já exista (ex.: o da homologação).
        $existente = DB::table('discount_sponsors')->where('slug', self::SLUG)->first()
            ?? DB::table('discount_sponsors')->where('type', 'api')->get()->first(
                fn ($s) => (json_decode((string) $s->config, true)['style'] ?? null) === 'cashcash'
            );

        if ($existente) {
            DB::table('discount_sponsors')->where('id', $existente->id)->update($dados);
            $id = $existente->id;
        } else {
            $id = DB::table('discount_sponsors')->insertGetId($dados + [
                'slug' => self::SLUG,
                'is_active' => false,
                'created_at' => now(),
            ]);
        }

        // Só um parceiro ocupa o espaço por vez. Ativa a Cash Cash apenas se o
        // espaço estiver livre: nunca desliga outro parceiro que esteja no ar.
        $outroAtivo = DB::table('discount_sponsors')
            ->where('is_active', true)
            ->where('id', '!=', $id)
            ->exists();

        if (!$outroAtivo) {
            DB::table('discount_sponsors')->where('id', $id)->update(['is_active' => true]);
        }

        // O portal guarda o parceiro ativo em cache por 5 minutos.
        Cache::forget('discount_sponsor_active');
    }

    public function down(): void
    {
        if (!Schema::hasTable('discount_sponsors')) {
            return;
        }

        // Remove só o cadastro com o slug desta migration. Os leads continuam
        // (sponsor_id vira nulo).
        DB::table('discount_sponsors')->where('slug', self::SLUG)->delete();
        Cache::forget('discount_sponsor_active');
    }
};
