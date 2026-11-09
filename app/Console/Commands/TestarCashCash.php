<?php

namespace App\Console\Commands;

use App\Services\CashCashService;
use Illuminate\Console\Command;

/**
 * Testa a integração com a Cash Cash usando o MESMO código do portal,
 * etapa por etapa: configuração → token → envio (CPF) → link.
 *
 * Nunca mostra client_secret, token nem o link completo (regra do guia do
 * parceiro). Não cria lead na tabela discount_leads.
 *
 *   php artisan cashcash:testar                  só config + token
 *   php artisan cashcash:testar --cpf=00000000000 também cria um envio
 *
 * Em produção, o envio inicia uma análise de crédito real daquele CPF:
 * use o seu. E evite repetir com credencial errada — o parceiro bloqueia
 * (429) depois de muitas tentativas recusadas.
 */
class TestarCashCash extends Command
{
    protected $signature = 'cashcash:testar
                            {--cpf= : CPF para testar também o envio e o link (inicia análise real em produção)}
                            {--mostrar-link : Mostra o link completo para abrir no celular (uso único, vale 5 min)}';

    protected $description = 'Testa a integração Cash Cash: configuração, token, envio e link';

    public function handle(CashCashService $cashCash): int
    {
        // --cpf com texto em vez de número (ex.: --cpf=SEUCPF) não pode virar
        // "sem CPF" calado: avisa antes de gastar um pedido de token.
        $cpfInformado = $this->option('cpf');
        if ($cpfInformado !== null && preg_replace('/\D/', '', $cpfInformado) === '') {
            $this->newLine();
            $this->error("--cpf={$cpfInformado} não tem número de CPF. Use os 11 dígitos, ex.: --cpf=12345678909");
            $this->newLine();

            return self::FAILURE;
        }

        $this->newLine();
        $this->line('<options=bold>0. Configuração que o portal está usando</>');
        $secret = (string) config('services.cashcash.client_secret');
        $this->line('   URL da API ......: ' . $cashCash->apiUrl());
        $this->line('   client_id .......: ' . (config('services.cashcash.client_id') ?: '<fg=red>(VAZIO)</>'));
        $this->line('   client_secret ...: ' . ($secret !== '' ? 'preenchido (' . strlen($secret) . ' caracteres)' : '<fg=red>(VAZIO)</>'));
        $this->line('   oferta ligada ...: ' . ($cashCash->isEnabled() ? 'sim' : '<fg=red>NÃO</>'));
        $this->line('   como abre .......: ' . $cashCash->openIn());

        $this->newLine();
        $this->line('<options=bold>1. Token (login na Cash Cash)</>');
        $token = $cashCash->testarToken();

        if (!$token['ok']) {
            $this->line(sprintf('   <fg=red>✗ HTTP %s — %s — %s</>',
                $token['http_status'], $token['code'] ?? '-', $token['message'] ?? '-'));
            $this->newLine();
            $this->line('   Parou na etapa 1. Sem token não existe envio nem link.');
            $this->line('   401 = credencial recusada pela Cash Cash · 403 = integração suspensa · 429 = bloqueio por tentativas');
            $this->newLine();

            return self::FAILURE;
        }

        $this->line(sprintf('   <fg=green>✓ HTTP %s — token recebido (válido por %ss)</>',
            $token['http_status'], (int) $token['expires_in']));

        $cpf = preg_replace('/\D/', '', (string) $this->option('cpf'));
        if ($cpf === '') {
            $this->newLine();
            $this->line('   Login OK. Para testar também o envio e o link: --cpf=SEUCPF');
            $this->verificarAppDaCashCash();

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('<options=bold>2. Envio (CPF + consentimento) e link</>');

        if (!CashCashService::isValidCpf($cpf)) {
            $this->line('   <fg=red>✗ CPF inválido — nada foi enviado.</>');
            $this->newLine();

            return self::FAILURE;
        }

        $envio = $cashCash->createHandoff([
            'accepted_at' => now()->toIso8601ZuluString('millisecond'),
            'ip_address' => '104.248.185.39',
            'user_agent' => 'Teste do servidor WiFi Tocantins (cashcash:testar)',
            'latitude' => (float) config('services.cashcash.default_latitude'),
            'longitude' => (float) config('services.cashcash.default_longitude'),
            'location_source' => 'ip',
        ], $cpf);

        if (!$envio['ok']) {
            $this->line(sprintf('   <fg=red>✗ HTTP %s — %s</>', $envio['http_status'], $envio['code'] ?? '-'));
            foreach ((array) ($envio['fields'] ?? []) as $campo) {
                $this->line('      campo ' . ($campo['field'] ?? '?') . ': ' . ($campo['issue'] ?? '?'));
            }
            $this->newLine();
            $this->line('   Parou na etapa 2.');
            $this->line('   400 = campo errado no envio · 422 CPF_UNAVAILABLE = integração OK, só este CPF sem análise agora');
            $this->newLine();

            return self::FAILURE;
        }

        // O link completo dá acesso à análise daquele CPF: mostra só o destino.
        $destino = parse_url((string) $envio['url']);
        $this->line(sprintf('   <fg=green>✓ HTTP %s — envio criado</>', $envio['http_status']));
        $this->line('   handoff_id ......: ' . $envio['handoff_id'] . '   (é este que a Cash Cash pede no suporte)');
        $this->line('   link leva para ..: ' . ($destino['scheme'] ?? '?') . '://' . ($destino['host'] ?? '?') . ($destino['path'] ?? ''));
        $this->line('   link vale até ...: ' . $envio['expires_at']);

        if ($this->option('mostrar-link')) {
            // Só com a opção explícita: o link dá acesso à análise daquele CPF.
            $this->newLine();
            $this->line('   <fg=yellow>Link completo — abra no celular AGORA (uso único, vale 5 minutos).</>');
            $this->line('   <fg=yellow>Não compartilhe: ele abre a análise deste CPF.</>');
            $this->line('   ' . $envio['url']);
        }

        $this->newLine();
        $this->line('<options=bold>3. Redirecionamento</>');
        $this->line('   O servidor está pronto. Falta testar num ônibus, ANTES de pagar: se o app');
        $this->line('   não abrir, o domínio acima precisa entrar no walled garden dos MikroTiks.');

        $this->verificarAppDaCashCash();

        return self::SUCCESS;
    }

    /**
     * O lado da Cash Cash: depois que recebe o link, o app de produção abre a
     * avaliação chamando a API DELE. Descobre qual é, lendo o código público do
     * app (o mesmo que o navegador baixa), e confere se ela existe e responde.
     */
    private function verificarAppDaCashCash(): void
    {
        $this->newLine();
        $this->line('<options=bold>4. App da Cash Cash (o lado deles)</>');

        $app = rtrim((string) config('services.cashcash.app_url'), '/');
        $this->line('   app .............: ' . ($app ?: '<fg=red>(CASHCASH_APP_URL vazio)</>'));
        if (!$app) {
            $this->newLine();

            return;
        }

        try {
            $html = \Illuminate\Support\Facades\Http::timeout(15)->get($app . '/')->body();
            if (!preg_match('#/assets/index-[A-Za-z0-9_-]+\.js#', $html, $arquivo)) {
                $this->line('   <fg=yellow>? não achei o código do app para conferir</>');
                $this->newLine();

                return;
            }

            $js = \Illuminate\Support\Facades\Http::timeout(30)->get($app . $arquivo[0])->body();
            if (!preg_match('#baseURL:"(https?://[^"]+)"#', $js, $base)) {
                $this->line('   <fg=yellow>? não achei no app o endereço da API dele</>');
                $this->newLine();

                return;
            }
        } catch (\Throwable $e) {
            $this->line('   <fg=red>✗ não consegui baixar o app: ' . $e->getMessage() . '</>');
            $this->newLine();

            return;
        }

        $api = rtrim($base[1], '/');
        $host = (string) parse_url($api, PHP_URL_HOST);
        $this->line('   API que o app usa: ' . $api);

        $dns = @dns_get_record($host, DNS_A + DNS_AAAA + DNS_CNAME) ?: [];
        if (!$dns) {
            $this->line('   <fg=red>✗ esse domínio NÃO EXISTE no DNS</>');
            $this->newLine();
            $this->line('   O app recebe o link, mas não consegue abrir a avaliação: mostra');
            $this->line('   "Sem conexão" para qualquer pessoa, em qualquer rede. É com a Cash Cash.');
            $this->newLine();

            return;
        }

        try {
            $status = \Illuminate\Support\Facades\Http::timeout(10)->get($api . '/')->status();
            $this->line('   <fg=green>✓ a API existe e respondeu (HTTP ' . $status . ')</>');
            $this->line('   O app consegue abrir a avaliação. Se der erro no ônibus, é o walled garden.');
        } catch (\Throwable $e) {
            $this->line('   <fg=red>✗ a API existe no DNS mas não respondeu</>');
        }
        $this->newLine();
    }
}
