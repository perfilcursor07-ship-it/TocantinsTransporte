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
                            {--cpf= : CPF para testar também o envio e o link (inicia análise real em produção)}';

    protected $description = 'Testa a integração Cash Cash: configuração, token, envio e link';

    public function handle(CashCashService $cashCash): int
    {
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
            $this->newLine();

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

        $this->newLine();
        $this->line('<options=bold>3. Redirecionamento</>');
        $this->line('   O servidor está pronto. Falta testar num ônibus, ANTES de pagar: se o app');
        $this->line('   não abrir, o domínio acima precisa entrar no walled garden dos MikroTiks.');
        $this->newLine();

        return self::SUCCESS;
    }
}
