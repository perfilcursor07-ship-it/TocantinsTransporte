<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WalledGardenController extends Controller
{
    /**
     * Lista de domínios para Walled Garden do Hotspot
     * Estes domínios são liberados para acesso sem autenticação
     * para que os usuários possam pagar via PIX
     * 
     * ATUALIZADO: 2026-01-11
     */
    private function getBankDomains(): array
    {
        return [
            // ========== PORTAL DE PAGAMENTO ==========
            'tocantinstransportewifi.com.br',
            '*.tocantinstransportewifi.com.br',
            '10.5.50.1',
            
            // ========== PARCEIRO CASH CASH (proposta de crédito) ==========
            // Só o APP precisa ser liberado: a API (gw-*.cashcash.com.br) é
            // chamada servidor-a-servidor e não passa pelo MikroTik.
            'app-pwa-cash-dev.rafatella.workers.dev',  // homologação
            'app.cashcash.com.br',                      // produção
            '*.cashcash.com.br',

            // ========== GATEWAYS DE PAGAMENTO ==========
            '*.woovi.com',
            '*.openpix.com.br',
            '*.pagseguro.com.br',
            '*.pagseguro.uol.com.br',
            
            // ========== BANCOS DIGITAIS ==========
            // Nubank
            '*.nubank.com.br',
            '*.nubank.com',
            '*.nubankstatic.com',
            '*.nubank.io',
            
            // PicPay
            '*.picpay.com',
            
            // Inter
            '*.bancointer.com.br',
            '*.inter.co',
            
            // C6 Bank
            '*.c6bank.com.br',
            '*.c6bank.com',
            
            // Neon
            '*.neon.com.br',
            
            // Next (Bradesco)
            '*.next.me',
            
            // Will Bank
            '*.willbank.com.br',
            
            // Original
            '*.original.com.br',
            
            // Mercado Pago
            '*.mercadopago.com.br',
            '*.mercadopago.com',
            '*.mercadolivre.com.br',
            '*.mercadolibre.com',
            '*.mlstatic.com',
            
            // ========== BANCOS TRADICIONAIS ==========
            // Caixa Econômica Federal
            '*.caixa.gov.br',
            '*.caixa.com.br',
            '*.caixaeconomica.com.br',
            
            // Banco do Brasil
            '*.bb.com.br',
            '*.bancodobrasil.com.br',
            
            // Itaú
            '*.itau.com.br',
            '*.itau-unibanco.com.br',
            
            // Bradesco
            '*.bradesco.com.br',
            '*.banco.bradesco',
            
            // Santander
            '*.santander.com.br',
            '*.santandernet.com.br',
            
            // BRB (Banco de Brasília)
            '*.brb.com.br',
            
            // Banco da Amazônia
            '*.bancoamazonia.com.br',
            '*.basa.com.br',
            
            // Sicoob
            '*.sicoob.com.br',
            
            // Sicredi
            '*.sicredi.com.br',
            
            // Banrisul
            '*.banrisul.com.br',
            
            // Banco Central (PIX)
            '*.bcb.gov.br',
            
            // ========== CDNs E CLOUD ==========
            // AWS
            '*.cloudfront.net',
            '*.amazonaws.com',
            '*.amazon.com',
            '*.amazontrust.com',
            '*.awstrust.com',
            
            // Akamai
            '*.akamaihd.net',
            '*.akamaiedge.net',
            '*.akamaitechnologies.com',
            '*.edgekey.net',
            '*.edgesuite.net',
            
            // Azure
            '*.azureedge.net',
            '*.msecnd.net',
            
            // Google
            '*.googleapis.com',
            '*.gstatic.com',
            '*.firebaseio.com',
            '*.firebase.google.com',
            '*.pki.goog',
            
            // Apple
            '*.apple.com',
            '*.icloud.com',
            '*.mzstatic.com',
            
            // Azion (usado pela Caixa)
            '*.azioncdn.net',
            '*.azion.net',
            
            // ========== CERTIFICADOS SSL (OCSP/CRL) ==========
            '*.ocsp.*',
            'ocsp.*',
            '*.crl.*',
            'crl.*',
            '*.pki.*',
            '*.trust.*',
            '*.digicert.com',
            '*.globalsign.com',
            '*.letsencrypt.org',
            'r3.o.lencr.org',
            'x1.c.lencr.org',
            '*.verisign.com',
            '*.symantec.com',
            '*.thawte.com',
            '*.geotrust.com',
            '*.rapidssl.com',
            '*.usertrust.com',
            '*.comodoca.com',
            '*.sectigo.com',
            '*.entrust.net',
            '*.identrust.com',
            
            // ========== ANALYTICS E TRACKING (usados pelos apps) ==========
            'sentry.io',
            '*.sentry.io',
            '*.segment.io',
            '*.segment.com',
            '*.branch.io',
            '*.app.link',
            '*.adjust.com',
            '*.appsflyer.com',
            '*.crashlytics.com',
            '*.fabric.io',
            '*.newrelic.com',
            '*.nr-data.net',
            '*.datadoghq.com',
            '*.mixpanel.com',
            '*.amplitude.com',
            '*.intercom.io',
            '*.zendesk.com',
            
            // ========== FACEBOOK/META (usado por alguns apps) ==========
            '*.facebook.com',
            '*.fbcdn.net',
            '*.connect.facebook.net',
        ];
    }

    /**
     * Lista de ranges de IP para Walled Garden
     */
    private function getBankIpRanges(): array
    {
        return [
            // ========== PORTAL ==========
            ['range' => '104.248.185.39/32', 'comment' => 'Portal'],
            ['range' => '10.5.50.1/32', 'comment' => 'Gateway'],
            
            // ========== AWS BRASIL ==========
            ['range' => '18.228.0.0/14', 'comment' => 'AWS-BR-1'],
            ['range' => '18.228.0.0/16', 'comment' => 'AWS-BR-2'],
            ['range' => '18.229.0.0/16', 'comment' => 'AWS-BR-3'],
            ['range' => '18.230.0.0/16', 'comment' => 'AWS-BR-4'],
            ['range' => '18.231.0.0/16', 'comment' => 'AWS-BR-5'],
            ['range' => '52.67.0.0/16', 'comment' => 'AWS-BR-6'],
            ['range' => '54.207.0.0/16', 'comment' => 'AWS-BR-7'],
            ['range' => '54.232.0.0/16', 'comment' => 'AWS-BR-8'],
            ['range' => '54.233.0.0/16', 'comment' => 'AWS-BR-9'],
            ['range' => '177.71.128.0/17', 'comment' => 'AWS-BR-10'],
            ['range' => '15.228.0.0/16', 'comment' => 'AWS-BR-11'],
            ['range' => '15.229.0.0/16', 'comment' => 'AWS-BR-12'],
            ['range' => '54.94.0.0/16', 'comment' => 'AWS-SA'],
            ['range' => '52.94.0.0/16', 'comment' => 'AWS-SA2'],
            ['range' => '99.77.0.0/16', 'comment' => 'AWS-Global'],
            ['range' => '99.78.0.0/16', 'comment' => 'AWS-Global2'],
            
            // ========== CLOUDFRONT ==========
            ['range' => '13.32.0.0/15', 'comment' => 'CloudFront-1'],
            ['range' => '13.35.0.0/16', 'comment' => 'CloudFront-2'],
            ['range' => '52.84.0.0/15', 'comment' => 'CloudFront-3'],
            ['range' => '54.182.0.0/16', 'comment' => 'CloudFront-4'],
            ['range' => '54.192.0.0/16', 'comment' => 'CloudFront-5'],
            ['range' => '54.230.0.0/16', 'comment' => 'CloudFront-6'],
            ['range' => '54.239.128.0/18', 'comment' => 'CloudFront-7'],
            ['range' => '64.252.64.0/18', 'comment' => 'CloudFront-8'],
            ['range' => '143.204.0.0/16', 'comment' => 'CloudFront-9'],
            ['range' => '204.246.164.0/22', 'comment' => 'CloudFront-10'],
            ['range' => '205.251.200.0/21', 'comment' => 'CloudFront-11'],
            ['range' => '216.137.32.0/19', 'comment' => 'CloudFront-12'],
            
            // ========== CLOUDFLARE ==========
            ['range' => '104.16.0.0/12', 'comment' => 'CF-1'],
            ['range' => '172.64.0.0/13', 'comment' => 'CF-2'],
            ['range' => '103.21.244.0/22', 'comment' => 'CF-3'],
            ['range' => '103.22.200.0/22', 'comment' => 'CF-4'],
            ['range' => '103.31.4.0/22', 'comment' => 'CF-5'],
            ['range' => '141.101.64.0/18', 'comment' => 'CF-6'],
            ['range' => '108.162.192.0/18', 'comment' => 'CF-7'],
            ['range' => '190.93.240.0/20', 'comment' => 'CF-8'],
            ['range' => '188.114.96.0/20', 'comment' => 'CF-9'],
            ['range' => '197.234.240.0/22', 'comment' => 'CF-10'],
            ['range' => '198.41.128.0/17', 'comment' => 'CF-11'],
            ['range' => '162.158.0.0/15', 'comment' => 'CF-12'],
            ['range' => '131.0.72.0/22', 'comment' => 'CF-13'],
            
            // ========== AKAMAI ==========
            ['range' => '23.0.0.0/12', 'comment' => 'Akamai-1'],
            ['range' => '104.64.0.0/10', 'comment' => 'Akamai-2'],
            ['range' => '184.24.0.0/13', 'comment' => 'Akamai-3'],
            ['range' => '184.50.0.0/15', 'comment' => 'Akamai-4'],
            ['range' => '23.45.0.0/16', 'comment' => 'Akamai-OCSP'],
            
            // ========== AZURE BRASIL ==========
            ['range' => '191.232.0.0/13', 'comment' => 'Azure-BR-1'],
            ['range' => '191.234.0.0/15', 'comment' => 'Azure-BR-2'],
            ['range' => '20.195.0.0/16', 'comment' => 'Azure-BR-3'],
            ['range' => '20.197.0.0/16', 'comment' => 'Azure-BR-4'],
            ['range' => '20.201.0.0/16', 'comment' => 'Azure-BR-5'],
            ['range' => '20.206.0.0/16', 'comment' => 'Azure-BR-6'],
            
            // ========== GOOGLE CLOUD BRASIL ==========
            ['range' => '35.198.0.0/16', 'comment' => 'GCP-BR-1'],
            ['range' => '35.199.0.0/16', 'comment' => 'GCP-BR-2'],
            ['range' => '35.247.0.0/16', 'comment' => 'GCP-BR-3'],
            
            // ========== BANCOS ESPECÍFICOS ==========
            // Caixa
            ['range' => '200.201.0.0/16', 'comment' => 'CEF-1'],
            ['range' => '200.201.160.0/20', 'comment' => 'CEF-2'],
            ['range' => '161.148.0.0/16', 'comment' => 'CEF-CDN'],
            
            // Banco do Brasil
            ['range' => '170.66.0.0/16', 'comment' => 'BB-1'],
            ['range' => '201.33.144.0/21', 'comment' => 'BB-2'],
            ['range' => '198.184.161.0/24', 'comment' => 'BB-3'],
            
            // Itaú
            ['range' => '200.196.144.0/20', 'comment' => 'Itau-1'],
            ['range' => '138.59.160.0/22', 'comment' => 'Itau-2'],
            ['range' => '200.186.244.0/24', 'comment' => 'Itau-3'],
            
            // Bradesco
            ['range' => '200.155.80.0/20', 'comment' => 'Bradesco-1'],
            ['range' => '177.92.208.0/20', 'comment' => 'Bradesco-2'],
            
            // Santander
            ['range' => '200.220.176.0/20', 'comment' => 'Santander-1'],
            ['range' => '200.232.64.0/19', 'comment' => 'Santander-2'],
            
            // BRB
            ['range' => '200.11.16.0/20', 'comment' => 'BRB'],
            
            // Banco da Amazônia
            ['range' => '45.5.204.0/22', 'comment' => 'BancoAmazonia'],
            
            // Mercado Pago/Livre
            ['range' => '216.33.197.0/24', 'comment' => 'MercadoLivre-1'],
            ['range' => '209.225.49.0/24', 'comment' => 'MercadoLivre-2'],
            
            // ========== AZION (CDN Caixa) ==========
            ['range' => '179.191.160.0/20', 'comment' => 'Azion-1'],
            ['range' => '179.191.176.0/20', 'comment' => 'Azion-2'],
            ['range' => '186.195.64.0/20', 'comment' => 'Azion-3'],
            
            // ========== FASTLY ==========
            ['range' => '151.101.0.0/16', 'comment' => 'Fastly-1'],
            ['range' => '199.232.0.0/16', 'comment' => 'Fastly-2'],
            
            // ========== OCSP/CRL SERVERS ==========
            ['range' => '93.184.220.0/24', 'comment' => 'OCSP-1'],
            ['range' => '72.21.91.0/24', 'comment' => 'OCSP-2'],
            ['range' => '117.18.237.0/24', 'comment' => 'OCSP-3'],
            
            // ========== DNS ==========
            ['range' => '1.1.1.1/32', 'comment' => 'DNS-Cloudflare'],
            ['range' => '1.0.0.1/32', 'comment' => 'DNS-Cloudflare-2'],
            ['range' => '8.8.8.8/32', 'comment' => 'DNS-Google'],
            ['range' => '8.8.4.4/32', 'comment' => 'DNS-Google-2'],
        ];
    }

    /**
     * Endpoint para MikroTik buscar lista de domínios
     */
    public function getDomains(Request $request)
    {
        $token = $request->get('token');
        $expectedToken = config('wifi.mikrotik_sync_token', 'mikrotik-sync-2024');
        
        if ($token !== $expectedToken) {
            return response('ERROR:AUTH', 401)->header('Content-Type', 'text/plain');
        }

        $domains = $this->getBankDomains();
        $output = implode("\n", $domains);
        
        return response($output, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'max-age=3600');
    }

    /**
     * Endpoint para MikroTik buscar lista de IPs
     */
    public function getIpRanges(Request $request)
    {
        $token = $request->get('token');
        $expectedToken = config('wifi.mikrotik_sync_token', 'mikrotik-sync-2024');
        
        if ($token !== $expectedToken) {
            return response('ERROR:AUTH', 401)->header('Content-Type', 'text/plain');
        }

        $ranges = $this->getBankIpRanges();
        $lines = [];
        
        foreach ($ranges as $range) {
            $lines[] = $range['range'] . '|' . $range['comment'];
        }
        
        $output = implode("\n", $lines);
        
        return response($output, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Cache-Control', 'max-age=3600');
    }

    /**
     * Endpoint JSON com todas as informações
     */
    public function getAll(Request $request)
    {
        $token = $request->get('token');
        $expectedToken = config('wifi.mikrotik_sync_token', 'mikrotik-sync-2024');
        
        if ($token !== $expectedToken) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return response()->json([
            'success' => true,
            'domains' => $this->getBankDomains(),
            'ip_ranges' => $this->getBankIpRanges(),
            'total_domains' => count($this->getBankDomains()),
            'total_ip_ranges' => count($this->getBankIpRanges()),
            'updated_at' => '2026-01-11',
            'note' => 'Domínios e IPs de bancos brasileiros para Walled Garden'
        ]);
    }

    /**
     * Gera script RouterOS para configurar Walled Garden
     */
    public function getRouterOSScript(Request $request)
    {
        $token = $request->get('token');
        $expectedToken = config('wifi.mikrotik_sync_token', 'mikrotik-sync-2024');
        
        if ($token !== $expectedToken) {
            return response('# ERROR: AUTH', 401)->header('Content-Type', 'text/plain');
        }

        $script = "# WALLED GARDEN - BANCOS BRASILEIROS\n";
        $script .= "# Gerado em " . now()->format('Y-m-d H:i:s') . "\n\n";
        
        $script .= "/ip hotspot walled-garden\n";
        foreach ($this->getBankDomains() as $domain) {
            $comment = explode('.', str_replace('*', '', $domain))[0] ?: 'Domain';
            $script .= "add dst-host=\"{$domain}\" comment=\"{$comment}\"\n";
        }
        
        $script .= "\n/ip hotspot walled-garden ip\n";
        foreach ($this->getBankIpRanges() as $range) {
            $script .= "add action=accept dst-address={$range['range']} comment=\"{$range['comment']}\"\n";
        }
        
        return response($script, 200)
            ->header('Content-Type', 'text/plain')
            ->header('Content-Disposition', 'attachment; filename="walled-garden.rsc"');
    }
}
