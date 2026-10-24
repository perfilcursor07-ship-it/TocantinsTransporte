<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\MikrotikMacReport;
use Symfony\Component\HttpFoundation\IpUtils;

class PortalController extends Controller
{
    /**
     * Exibe a página inicial do portal cativo
     */
    public function index(Request $request)
    {
        $clientIp = $request->ip();
        
        // Se veio do MikroTik ou tem parâmetros de captive portal, mostrar portal
        if ($request->has('from_mikrotik') || $request->has('from_splash') || $request->has('skip_login') ||
            $request->has('dst') || $request->has('mac') || $request->has('ip') ||
            $request->has('source') || $request->has('captive')) {
            $request->session()->put('mikrotik_context_verified', true);
            return $this->showPortal($request);
        }
        
        // Se sessão já verificada, mostrar portal
        if ($request->session()->get('mikrotik_context_verified')) {
            return $this->showPortal($request);
        }
        
        // Se IP é da rede do hotspot (10.5.50.x), já passou pelo MikroTik
        if ($this->ipMatchesHotspotSubnets($clientIp)) {
            Log::info('📱 IP do hotspot detectado, mostrando portal', ['ip' => $clientIp]);
            $request->session()->put('mikrotik_context_verified', true);
            return $this->showPortal($request);
        }
        
        // Verificar se usuário já tem cadastro (ativo ou expirado)
        $existingUser = $this->findConnectedUserByIp($clientIp);
        if ($existingUser) {
            $request->session()->put('mikrotik_context_verified', true);
            return $this->showPortal($request, $existingUser);
        }
        
        // Usuário de fora da rede: mostrar portal direto (não tem como redirecionar para MikroTik)
        Log::info('🌐 Acesso externo, mostrando portal', ['ip' => $clientIp]);
        $request->session()->put('mikrotik_context_verified', true);
        return $this->showPortal($request);
    }
    
    /**
     * Exibe o portal com informações do usuário
     */
    private function showPortal(Request $request, ?User $existingUser = null)
    {
        $clientIp = $request->ip();
        
        // Redirecionar usuários autenticados para dashboard
        if (auth()->check() && ! in_array(auth()->user()->role, ['admin', 'manager'], true) && ! $request->boolean('choose_plan')) {
            return redirect()->route('portal.dashboard');
        }
        
        $clientInfo = $existingUser ? [
            'ip_address' => $clientIp,
            'mac_address' => $existingUser->mac_address,
            'user_agent' => $request->userAgent(),
            'device_type' => $this->detectDeviceType($request->userAgent()),
        ] : $this->getClientInfo($request);
        
        $priceInfo = \App\Helpers\SettingsHelper::getPriceInfo();

        // 🌟 Prova social dinâmica (puxada do banco): média de avaliação + nº de passageiros.
        $reviewStats = \App\Models\ServiceReview::ratingStats();
        $passengers30d = \Illuminate\Support\Facades\Cache::remember('portal_passengers_30d', 1800, function () {
            return (int) \App\Models\Payment::where('status', 'completed')
                ->where('paid_at', '>=', now()->subDays(30))
                ->distinct('user_id')
                ->count('user_id');
        });

        // Detectar se o usuário está REALMENTE no WiFi do ônibus.
        // Usa os MESMOS sinais que já funcionam no fluxo (não muda a detecção):
        //  1. Parâmetros na URL vindos do MikroTik (mac, captive, source, etc.)
        //  2. Sessão já verificada como contexto MikroTik
        //  3. IP da faixa do hotspot
        //  4. Usuário existente vinculado (cookie/pagamento)
        $onHotspot = $existingUser !== null
            || $request->session()->get('mikrotik_context_verified', false)
            || $request->hasAny(['mac', 'mikrotik_mac', 'client_mac', 'from_mikrotik', 'from_splash', 'dst', 'source', 'captive', 'from_login', 'from_router'])
            || $this->ipMatchesHotspotSubnets($clientIp);

        // Desconto da proposta Cash Cash já concedido para este aparelho?
        // Sobrevive a refresh da página e à volta do app do parceiro.
        $discountMac = $existingUser->mac_address ?? $request->input('mac');
        $grantedDiscount = \App\Models\DiscountLead::activeDiscountFor($discountMac);

        // Voltou do app do parceiro: o portal retoma a conferência no carregamento.
        $pendingDiscount = $grantedDiscount
            ? null
            : \App\Models\DiscountLead::pendingReturnFor($discountMac);

        return view('portal.index', [
            'client_info' => $clientInfo,
            'company_name' => config('app.company_name', 'WiFi Tocantins Express'),
            'price' => $priceInfo['current_price'],
            'original_price' => $priceInfo['original_price'],
            'discount_percentage' => $priceInfo['discount_percentage'],
            'savings' => $priceInfo['savings'],
            'speed' => '100+ Mbps',
            'session_duration' => \App\Helpers\SettingsHelper::getSessionDuration(),
            'session_duration_short' => \App\Helpers\SettingsHelper::getSessionDurationShort(),
            'wifi_price_short' => \App\Helpers\SettingsHelper::getWifiPrice(),
            'wifi_price_full' => \App\Helpers\SettingsHelper::getWifiPriceFull(),
            'plan_short_enabled' => \App\Helpers\SettingsHelper::isPlanShortCurrentlyActive(),
            'plan_full_enabled' => (bool) \App\Models\SystemSetting::getValue('plan_full_enabled', '1'),
            'interval_plan' => \App\Services\IntervalPlanService::settings(),
            // Oferta de desconto: só aparece se o admin habilitou E o parceiro está configurado.
            'discount_offer_enabled' => \App\Helpers\SettingsHelper::isVideoDiscountEnabled()
                && app(\App\Services\CashCashService::class)->isEnabled(),
            'video_discount_amount' => \App\Helpers\SettingsHelper::getVideoDiscountAmount(),
            'cashcash_min_seconds' => \App\Helpers\SettingsHelper::getCashCashMinSeconds(),
            // Desconto já ganho antes de um refresh — restaura sem refazer a proposta.
            'discount_already_granted' => (bool) $grantedDiscount,
            'discount_already_granted_amount' => (float) ($grantedDiscount->discount_amount ?? 0),
            'discount_pending_lead_id' => $pendingDiscount->id ?? null,
            'connected_user' => $existingUser,
            'on_hotspot' => $onHotspot,
            'review_average' => $reviewStats['average'],
            'review_count' => $reviewStats['count'],
            'passengers_30d' => $passengers30d,
        ]);
    }
    
    /**
     * Exibe a splash screen com iframe do MikroTik em background
     */
    private function showSplashScreen(Request $request)
    {
        $clientIp = $request->ip();
        
        // Sempre redirecionar para MikroTik capturar MAC
        $loginUrl = 'http://10.5.50.1/login';
        
        // URL de destino: onde o MikroTik deve redirecionar após capturar MAC/IP
        $portalUrl = config('wifi.server_url', config('app.url'));
        
        // Construir URL de retorno com parâmetros que serão preenchidos pelo MikroTik
        $returnUrl = $portalUrl . '?source=mikrotik&captive=true&from_mikrotik=1';
        
        $query = [
            'dst' => $returnUrl,
            'from_portal' => 1,
        ];
        
        if ($request->has('device')) {
            $query['device'] = $request->get('device');
        }
        
        $glue = Str::contains($loginUrl, '?') ? '&' : '?';
        $mikrotikUrl = $loginUrl . $glue . http_build_query($query);
        
        Log::info('🎬 Exibindo splash screen com MikroTik em background', [
            'mikrotik_url' => $mikrotikUrl,
            'return_url' => $returnUrl,
            'ip' => $clientIp,
            'user_agent' => $request->userAgent(),
        ]);
        
        return view('portal.splash', [
            'mikrotik_url' => $mikrotikUrl,
        ]);
    }

    private function shouldForceMikrotikRedirect(Request $request): bool
    {
        $mikrotikConfig = config('wifi.mikrotik', []);

        if (!($mikrotikConfig['enabled'] ?? false)) {
            return false;
        }

        if (!($mikrotikConfig['force_login_redirect'] ?? false)) {
            return false;
        }

        if ($request->has('skip_login') || $request->boolean('skip_login')) {
            return false;
        }
        
        // Se veio do MikroTik (from_mikrotik ou from_splash), não mostrar splash novamente
        if ($request->has('from_mikrotik') || $request->has('from_splash')) {
            return false;
        }

        if (app()->environment('local') && !($mikrotikConfig['force_login_redirect_local'] ?? false)) {
            return false;
        }

        if ($request->expectsJson() || $request->wantsJson() || $request->ajax()) {
            return false;
        }

        if ($this->requestHasMikrotikContext($request)) {
            return false;
        }

        if ($request->session()->get('mikrotik_context_verified')) {
            return false;
        }

        if (!$this->ipMatchesHotspotSubnets($request->ip())) {
            if (!($mikrotikConfig['force_login_redirect_outside_hotspot'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    private function redirectToMikrotikLogin(Request $request)
    {
        $loginUrl = config('wifi.mikrotik.login_url', 'http://10.5.50.1/login');

        $portalUrl = config('wifi.server_url', config('app.url'));
        $desiredUrl = $request->fullUrl();
        $destination = $portalUrl ?: $desiredUrl;

        $query = [
            'dst' => $destination,
            'return_url' => $desiredUrl,
            'from_portal' => 1,
        ];

        if ($request->has('device')) {
            $query['device'] = $request->get('device');
        }

        Log::info('🔁 Redirecionando usuário para login do MikroTik para capturar MAC/IP', [
            'login_url' => $loginUrl,
            'query' => $query,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $glue = Str::contains($loginUrl, '?') ? '&' : '?';

        return redirect()->away($loginUrl . $glue . http_build_query($query));
    }

    /**
     * API para detectar dispositivo.
     *
     * Nunca retorna MAC MOCK. Se o MikroTik ainda não reportou o MAC real
     * deste IP, devolve mac_address=null + needs_retry=true para o
     * mac-detector.js insistir. Isso evita que um MAC fictício seja gravado
     * no cadastro e, depois, sincronizado ao MikroTik sem efeito — causa
     * principal de "paguei e não liberou".
     */
    public function detectDevice(Request $request)
    {
        $ip = $request->ip();
        $realMac = $this->tryDetectRealMac($request, $ip);

        return response()->json([
            'success' => true,
            'mac_address' => $realMac,
            'needs_retry' => $realMac === null,
            'ip_address' => $ip,
            'user_agent' => $request->userAgent(),
        ]);
    }

    /**
     * Verifica se o visitante está atrás de um roteador de ônibus.
     * Substitui o probe JS a http://10.5.50.1, que é bloqueado como
     * mixed content quando o portal é servido via HTTPS.
     *
     * Sinal principal: IP público do visitante == IP público de um MikroTik
     * (cache mikrotik_ip_{ip}, renovado a cada sync de 15s).
     */
    public function connectionCheck(Request $request)
    {
        $ip = $request->ip();

        $viaBusRouter = cache()->has('mikrotik_ip_' . $ip);

        $sessionVerified = false;
        if ($request->hasSession()) {
            $sessionVerified = (bool) $request->session()->get('mikrotik_context_verified', false);
        }

        $onHotspot = $viaBusRouter
            || $sessionVerified
            || $this->ipMatchesHotspotSubnets($ip);

        if ($viaBusRouter && $request->hasSession()) {
            $this->markMikrotikContextVerified($request);
        }

        return response()->json([
            'on_hotspot' => $onHotspot,
            'via_bus_router' => $viaBusRouter,
            'mikrotik_id' => $viaBusRouter ? cache()->get('mikrotik_ip_' . $ip) : null,
        ]);
    }

    /**
     * Tenta todas as fontes confiáveis de MAC (report, URL, header, ARP).
     * Retorna null se nenhuma produzir MAC real — sem gerar MOCK.
     */
    private function tryDetectRealMac(Request $request, string $ip): ?string
    {
        $internalIp = $this->getInternalIpFromHeaders($request);

        // 1. MAC reportado pelo MikroTik (script registrarMacs a cada 1min)
        try {
            $reportedMac = MikrotikMacReport::getLatestMacForIp($internalIp);
            if (!$reportedMac && $internalIp !== $ip) {
                $reportedMac = MikrotikMacReport::getLatestMacForIp($ip);
            }
            if ($reportedMac) {
                $cleanMac = strtoupper($reportedMac->mac_address);
                if (!$this->isLikelyMockMac($cleanMac)) {
                    $this->markMikrotikContextVerified($request);
                    return $cleanMac;
                }
            }
        } catch (\Exception $e) {
            Log::error('Erro ao consultar MACs reportados', ['error' => $e->getMessage()]);
        }

        // 2. MAC via URL (MikroTik redirect) ou corpo JSON (portal.js)
        $macViaUrl = $request->get('mac') ?: $request->get('mikrotik_mac') ?: $request->get('client_mac');
        if (! $macViaUrl && $request->isJson()) {
            $macViaUrl = $request->input('mac_address') ?: $request->input('mac');
        }
        if ($macViaUrl && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macViaUrl)) {
            $cleanMac = strtoupper(str_replace('-', ':', $macViaUrl));
            if (!$this->isLikelyMockMac($cleanMac)) {
                $this->markMikrotikContextVerified($request);
                return $cleanMac;
            }
        }

        // 3. MAC via headers do MikroTik
        $mikrotikMac = $request->header('X-Real-MAC')
            ?: $request->header('X-Mikrotik-MAC')
            ?: $request->header('X-Client-MAC');
        if ($mikrotikMac && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mikrotikMac)) {
            $cleanMac = strtoupper(str_replace('-', ':', $mikrotikMac));
            if (!$this->isLikelyMockMac($cleanMac)) {
                $this->markMikrotikContextVerified($request);
                return $cleanMac;
            }
        }

        // 4. Consultar ARP do MikroTik (só se hotspot estiver configurado)
        $macFromArp = $this->queryMacByIpFromMikrotik($internalIp ?: $ip);
        if ($macFromArp && !$this->isLikelyMockMac($macFromArp)) {
            $this->markMikrotikContextVerified($request);
            return strtoupper($macFromArp);
        }

        return null;
    }

    /**
     * Obtém informações do cliente/dispositivo
     */
    private function getClientInfo(Request $request)
    {
        $ip = $request->ip();
        $userAgent = $request->userAgent();

        // PRODUÇÃO: Em hotspot MikroTik, o MAC vem via headers especiais
        $macAddress = $this->getMacAddressFromMikrotik($request, $ip);

        return [
            'ip_address' => $ip,
            'mac_address' => $macAddress,
            'user_agent' => $userAgent,
            'device_type' => $this->detectDeviceType($userAgent)
        ];
    }

    /**
     * Obtém MAC address real do MikroTik ou gera baseado no IP
     */
    private function getMacAddressFromMikrotik(Request $request, $ip)
    {
        if (config('app.debug')) {
            Log::debug('Iniciando deteccao de MAC', [
                'ip' => $ip,
                'user_agent' => $request->userAgent(),
            ]);
        }

        $internalIp = $this->getInternalIpFromHeaders($request);

        // 0. 🔥 PRIORIDADE MÁXIMA: CONSULTAR MACS REPORTADOS PELO MIKROTIK
        try {
            // Verificar se temos MAC reportado para este IP (interno ou externo)
            $reportedMac = MikrotikMacReport::getLatestMacForIp($internalIp);
            if (!$reportedMac && $internalIp !== $ip) {
                $reportedMac = MikrotikMacReport::getLatestMacForIp($ip);
            }

            if ($reportedMac) {
                $cleanMac = strtoupper($reportedMac->mac_address);

                if ($this->isLikelyMockMac($cleanMac)) {
                    Log::warning('🚨 MAC virtual/mock reportado - continuando busca', [
                        'mac_virtual' => $cleanMac,
                        'ip_externo' => $ip,
                        'ip_interno' => $internalIp,
                    ]);
                } else {
                    Log::info('🚀 MAC REAL obtido via REPORT do MikroTik', [
                        'mac' => $cleanMac,
                        'ip_externo' => $ip,
                        'ip_interno' => $internalIp,
                        'reportado_em' => $reportedMac->reported_at?->format('Y-m-d H:i:s')
                    ]);

                    $this->markMikrotikContextVerified($request);

                    return $cleanMac;
                }
            }
        } catch (\Exception $e) {
            Log::error('Erro ao consultar MACs reportados', ['error' => $e->getMessage()]);
        }

        // 1. PRIORIDADE: MAC VIA PARÂMETROS URL (MikroTik redirect)
        // 1. PRIORIDADE: MAC VIA PARÂMETROS URL (MikroTik redirect) - FILTRAR MOCKS
        $macViaUrl = $request->get('mac') ?: 
                    $request->get('mikrotik_mac') ?: 
                    $request->get('client_mac');

        if ($macViaUrl && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $macViaUrl)) {
            $cleanMac = strtoupper(str_replace('-', ':', $macViaUrl));

            if ($this->isLikelyMockMac($cleanMac)) {
                Log::warning('🚨 MAC virtual/mock via URL - ignorado', [
                    'mac_virtual' => $cleanMac,
                    'ip' => $ip,
                ]);
            } else {
                Log::info('🎯 MAC REAL capturado via URL do MikroTik', [
                    'mac' => $cleanMac,
                    'ip' => $ip
                ]);

                $this->markMikrotikContextVerified($request);

                return $cleanMac;
            }
        }

        // 2. TENTAR OBTER MAC DE HEADERS DO MIKROTIK
        $mikrotikMac = $request->header('X-Real-MAC') ?: 
                      $request->header('X-Mikrotik-MAC') ?: 
                      $request->header('X-Client-MAC') ?:
                      $request->header('HTTP_X_REAL_MAC') ?:
                      $request->header('HTTP_X_MIKROTIK_MAC');

        if ($mikrotikMac && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mikrotikMac)) {
            $cleanMac = strtoupper(str_replace('-', ':', $mikrotikMac));

            if ($this->isLikelyMockMac($cleanMac)) {
                Log::warning('🚨 MAC virtual/mock recebido via header MikroTik', [
                    'mac_virtual' => $cleanMac,
                    'ip' => $ip,
                ]);
            } else {
                Log::info('✅ MAC REAL obtido via header MikroTik', ['mac' => $cleanMac, 'ip' => $ip]);

                $this->markMikrotikContextVerified($request);

                return $cleanMac;
            }
        }

        // 3. TENTAR CONSULTAR DIRETAMENTE NO MIKROTIK POR IP
        $macFromMikrotik = $this->queryMacByIpFromMikrotik($internalIp ?: $ip);
        if ($macFromMikrotik && $macFromMikrotik !== null) {
            if ($this->isLikelyMockMac($macFromMikrotik)) {
                Log::warning('🚨 MAC virtual/mock retornado pela consulta ARP MikroTik', [
                    'mac_virtual' => $macFromMikrotik,
                    'ip' => $internalIp ?: $ip,
                ]);
            } else {
                Log::info('✅ MAC REAL obtido consultando MikroTik ARP', ['mac' => $macFromMikrotik, 'ip' => $internalIp ?: $ip]);

                $this->markMikrotikContextVerified($request);

                return strtoupper($macFromMikrotik);
            }
        }

        // 4. ÚLTIMO RECURSO: GERAR MAC CONSISTENTE BASEADO NO IP 
        $macAddress = $this->generateMacFromIp($ip);
        Log::warning('⚠️ MAC MOCK gerado como fallback', [
            'mac_mock' => $macAddress, 
            'ip' => $ip,
            'nota' => 'MikroTik não enviou MAC real nem respondeu consulta ARP'
        ]);

        return $macAddress;
    }

    /**
     * Consulta MAC address no MikroTik baseado no IP
     */
    private function queryMacByIpFromMikrotik($ip)
    {
        try {
            if (!$ip || !config('wifi.mikrotik.enabled', false)) {
                return null;
            }

            if (!$this->ipMatchesHotspotSubnets($ip)) {
                return null;
            }

            $cacheKey = 'mikrotik:arp_lookup:'.$ip;
            $cachedResult = Cache::get($cacheKey);

            if (is_array($cachedResult) && array_key_exists('mac', $cachedResult)) {
                return $cachedResult['mac'] ?: null;
            }

            // Consultar ARP table do MikroTik para obter MAC por IP
            $mikrotikController = new \App\Http\Controllers\MikrotikController();
            $macAddress = $mikrotikController->getMacByIp($ip);

            Cache::put($cacheKey, ['mac' => $macAddress ?: null], now()->addSeconds(30));

            return $macAddress;
        } catch (\Exception $e) {
            Cache::put('mikrotik:arp_lookup:'.$ip, ['mac' => null], now()->addSeconds(15));
            Log::error('Erro ao consultar MAC no MikroTik', [
                'ip' => $ip,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Gera MAC fictício baseado no IP (para desenvolvimento)
     */
    private function generateMacFromIp($ip)
    {
        // Converter IP em MAC fictício para testes.
        // ⚠️ Prefixo exclusivo 02:FA:CE — NUNCA usar apenas "02:" como marcador de mock,
        // pois MACs randomizados REAIS de Android/iOS também podem começar com 02:.
        $parts = explode('.', $ip);
        if (count($parts) === 4) {
            $mac = sprintf(
                '02:FA:CE:%02x:%02x:%02x',
                $parts[1] % 256,
                $parts[2] % 256,
                $parts[3] % 256
            );
            return strtoupper($mac);
        }

        // Fallback para MAC aleatório
        return sprintf(
            '02:FA:CE:%02X:%02X:%02X',
            rand(0, 255),
            rand(0, 255),
            rand(0, 255)
        );
    }

    /**
     * Extrai IP interno do hotspot dos headers (10.10.10.x)
     */
    private function getInternalIpFromHeaders(Request $request)
    {
        // O MikroTik envia o IP interno via X-Forwarded-For
        $forwardedFor = $request->header('X-Forwarded-For');

        if ($forwardedFor) {
            $ips = array_map('trim', explode(',', $forwardedFor));

            foreach ($ips as $candidateIp) {
                if ($this->ipMatchesHotspotSubnets($candidateIp)) {
                    return $candidateIp;
                }
            }
        }

        // Fallback: verificar se o IP atual já é interno
        $currentIp = $request->ip();
        if ($this->ipMatchesHotspotSubnets($currentIp)) {
            return $currentIp;
        }

        // Se não encontrou IP interno, retornar o IP atual
        return $currentIp;
    }

    private function requestHasMikrotikContext(Request $request): bool
    {
        if ($request->session()->get('mikrotik_context_verified')) {
            return true;
        }

        $macParams = array_filter([
            $request->get('mac'),
            $request->get('mikrotik_mac'),
            $request->get('client_mac'),
        ]);

        foreach ($macParams as $macCandidate) {
            $normalized = strtoupper(str_replace('-', ':', (string) $macCandidate));
            if (preg_match('/^([0-9A-F]{2}[:-]){5}([0-9A-F]{2})$/', $normalized) && !$this->isLikelyMockMac($normalized)) {
                return true;
            }
        }

        if ($request->boolean('captive') || $request->boolean('from_login') || $request->boolean('from_router')) {
            return true;
        }

        $source = Str::lower((string) $request->query('source', ''));
        if (in_array($source, ['mikrotik', 'captive-portal', 'hotspot'], true)) {
            return true;
        }

        $headers = [
            $request->header('X-Real-MAC'),
            $request->header('X-Mikrotik-MAC'),
            $request->header('X-Client-MAC'),
            $request->header('HTTP_X_REAL_MAC'),
            $request->header('HTTP_X_MIKROTIK_MAC'),
        ];

        foreach ($headers as $headerMac) {
            if ($headerMac && preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $headerMac)) {
                $normalized = strtoupper(str_replace('-', ':', $headerMac));
                if (!$this->isLikelyMockMac($normalized)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function ipMatchesHotspotSubnets(?string $ip): bool
    {
        if (!$ip) {
            return false;
        }

        $subnets = config('wifi.mikrotik.hotspot_subnets', []);

        foreach ($subnets as $subnet) {
            if (IpUtils::checkIp($ip, $subnet)) {
                return true;
            }
        }

        return false;
    }

    private function markMikrotikContextVerified(Request $request): void
    {
        if (! $request->hasSession()) {
            return;
        }

        if (! $request->session()->get('mikrotik_context_verified')) {
            $request->session()->put('mikrotik_context_verified', true);
        }
    }

    private function isLikelyMockMac(string $mac): bool
    {
        // ⚠️ NÃO filtrar todo MAC "02:" — dispositivos Android/iOS com MAC
        // randomizado (padrão de fábrica) podem ter esse prefixo e são REAIS.
        // Só são mocks: o prefixo exclusivo 02:FA:CE (gerado por generateMacFromIp)
        // e os placeholders inválidos.
        return (bool) preg_match('/^(02:FA:CE|00:00:00|FF:FF:FF)/i', $mac);
    }

    /**
     * Busca usuário conectado pelo IP ou qualquer usuário recente na rede
     * Isso evita redirecionar para login.tocantinswifi.local quando usuário já tem cadastro
     */
    private function findConnectedUserByIp(string $ip): ?User
    {
        // 0. 🍪 Prioridade: cookie persistente setado pós-pagamento.
        // Resolve o caso do MAC randomizado que mudou entre sessões (iOS/Android).
        // Se o cookie aponta para um usuário pago com tempo, reaproveita a compra
        // atualizando o MAC/IP para os atuais — evita cobrança duplicada.
        $cookieUserId = request()->cookie('wt_user');
        if ($cookieUserId && is_numeric($cookieUserId)) {
            $cookieUser = User::where('id', (int) $cookieUserId)
                ->whereIn('status', ['connected', 'active'])
                ->where('expires_at', '>', now())
                ->first();

            if ($cookieUser) {
                // Se o dispositivo voltou com MAC diferente (randomização), atualiza
                // o cadastro e marca o MAC antigo como órfão para ser removido do
                // MikroTik no próximo sync. Assim o novo MAC é liberado automaticamente.
                try {
                    $currentMac = $this->tryDetectRealMac(request(), $ip);
                    if ($currentMac && \App\Support\HotspotIdentity::shouldReplaceMac($cookieUser->mac_address, $currentMac)) {
                        User::where('mac_address', $currentMac)
                            ->where('id', '!=', $cookieUser->id)
                            ->update(['mac_address' => null]);
                        \App\Support\HotspotIdentity::markOrphanedMac($cookieUser->mac_address);
                        $cookieUser->update([
                            'mac_address' => $currentMac,
                            'ip_address' => $ip,
                        ]);
                        // Forçar próximo sync (≤15s) a já enviar o MAC novo no broadcast
                        \Illuminate\Support\Facades\Cache::forget('mikrotik_sync_lists_all');
                        Log::info('🍪 Cookie reaproveitado com MAC novo', [
                            'user_id' => $cookieUser->id,
                            'new_mac' => $currentMac,
                        ]);
                    }

                    // 🚌 Reatribuir o ônibus atual se for diferente do gravado.
                    // Cobre o caso "paguei no ônibus 1, hoje estou no ônibus 3":
                    // descobre o serial do MikroTik atual via cache do IP público
                    // (gravado em MikrotikApiController quando cada ônibus sincroniza),
                    // atualiza o last_mikrotik_id do usuário e invalida a lista global
                    // de MACs para o próximo sync (≤15s) já pegar no ônibus certo.
                    $publicIp = request()->ip();
                    $currentMikrotikId = \Illuminate\Support\Facades\Cache::get('mikrotik_ip_' . $publicIp);
                    if ($currentMikrotikId && $cookieUser->last_mikrotik_id !== $currentMikrotikId) {
                        $cookieUser->update(['last_mikrotik_id' => $currentMikrotikId]);
                        \Illuminate\Support\Facades\Cache::forget('mikrotik_sync_lists_all');
                        Log::info('🚌 Usuário reassociado a novo ônibus via cookie', [
                            'user_id' => $cookieUser->id,
                            'old_mikrotik_id' => $cookieUser->getOriginal('last_mikrotik_id'),
                            'new_mikrotik_id' => $currentMikrotikId,
                            'public_ip' => $publicIp,
                        ]);
                    }
                } catch (\Exception $e) {
                    Log::warning('Cookie reuse: erro ao atualizar MAC/ônibus', ['error' => $e->getMessage()]);
                }

                return $cookieUser;
            }
        }

        // 1. Buscar usuário ATIVO com este IP
        $activeUser = User::where('ip_address', $ip)
            ->where('status', 'connected')
            ->where('expires_at', '>', now())
            ->orderBy('connected_at', 'desc')
            ->first();

        if ($activeUser) {
            return $activeUser;
        }
        
        // 2. Buscar usuário com este IP nas últimas 24h (expirado mas cadastrado)
        $recentUser = User::where('ip_address', $ip)
            ->where('connected_at', '>', now()->subHours(24))
            ->orderBy('connected_at', 'desc')
            ->first();
        
        if ($recentUser) {
            return $recentUser;
        }
        
        // 3. Se IP é da rede do hotspot (10.5.50.x), buscar por MAC reportado
        if ($this->ipMatchesHotspotSubnets($ip)) {
            $macReport = MikrotikMacReport::where('ip_address', $ip)
                ->orderBy('reported_at', 'desc')
                ->first();
            
            if ($macReport) {
                $userByMac = User::where('mac_address', $macReport->mac_address)
                    ->orderBy('connected_at', 'desc')
                    ->first();
                
                if ($userByMac) {
                    return $userByMac;
                }
            }
        }
        
        return null;
    }

    /**
     * Detecta tipo do dispositivo baseado no User-Agent
     */
    private function detectDeviceType($userAgent)
    {
        if (preg_match('/Mobile|Android|iPhone|iPad/', $userAgent)) {
            if (preg_match('/iPad/', $userAgent)) {
                return 'tablet';
            }
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Endpoint legado de cortesia via Instagram.
     *
     * A oferta atual do passageiro e paga: Plano por Hora ou Viagem Completa.
     * Mantemos a rota respondendo de forma explicita para clientes antigos,
     * mas sem liberar acesso gratuito fora da estrategia comercial atual.
     */
    public function instagramFreeAccess(Request $request)
    {
        return response()->json([
            'success' => false,
            'message' => 'Esta promocao nao esta disponivel. Escolha Plano por Hora ou Viagem Completa no portal.',
        ], 410);
    }
    /**
     * Valida e ativa voucher de motorista
     */
    public function validateVoucher(Request $request)
    {
        try {
            $request->validate([
                'voucher_code' => 'required|string',
            ]);

            $voucherCode = strtoupper(trim($request->voucher_code));
            
            // Busca voucher
            $voucher = \App\Models\Voucher::where('code', $voucherCode)->first();

            if (!$voucher) {
                return response()->json([
                    'success' => false,
                    'message' => 'Voucher inválido. Verifique o código e tente novamente.'
                ], 404);
            }

            // Valida voucher
            if (!$voucher->isValid()) {
                $reason = !$voucher->is_active ? 'Voucher desativado' : 
                         ($voucher->expires_at && $voucher->expires_at->isPast() ? 'Voucher expirado' : 
                         'Limite de horas diárias atingido');
                
                return response()->json([
                    'success' => false,
                    'message' => $reason
                ], 400);
            }

            // Obtém informações do cliente
            $clientInfo = $this->getClientInfo($request);
            $macAddress = $clientInfo['mac_address'];
            $ipAddress = $clientInfo['ip_address'];

            Log::info('🎫 Validando voucher de motorista', [
                'voucher' => $voucherCode,
                'driver' => $voucher->driver_name,
                'mac' => $macAddress,
                'ip' => $ipAddress,
                'type' => $voucher->voucher_type,
                'daily_hours' => $voucher->daily_hours,
                'hours_used' => $voucher->daily_hours_used,
            ]);

            // Calcular tempo de expiração baseado nas horas do voucher
            $hoursAvailable = $voucher->getRemainingHoursToday();
            $expiresAt = now()->addHours($hoursAvailable);

            // Para vouchers limitados, nunca passar de hoje às 23:59
            if ($voucher->voucher_type === 'limited') {
                $endOfDay = now()->endOfDay();
                if ($expiresAt->gt($endOfDay)) {
                    $expiresAt = $endOfDay;
                }
            }

            // Cria ou atualiza usuário com os campos do voucher
            $user = User::updateOrCreate(
                ['mac_address' => $macAddress],
                [
                    'name' => $voucher->driver_name,
                    'ip_address' => $ipAddress,
                    'device_name' => $clientInfo['device_type'],
                    'status' => 'connected',
                    'connected_at' => now(),
                    'expires_at' => $expiresAt,
                    'voucher_id' => $voucher->id,
                    'voucher_activated_at' => now(),
                    'voucher_last_connection' => now(),
                    'voucher_daily_minutes_used' => 0,
                ]
            );

            // Registra uso do voucher (apenas marca como usado, não incrementa horas)
            $voucher->recordUsage($hoursAvailable);

            // Registrar MAC na tabela Mikrotik
            \App\Models\MikrotikMacReport::updateOrCreate(
                [
                    'ip_address' => $ipAddress,
                    'mac_address' => $macAddress,
                ],
                [
                    'transaction_id' => 'VOUCHER_' . $user->id,
                    'mikrotik_ip' => null,
                    'reported_at' => now(),
                ]
            );

            // Libera acesso no Mikrotik
            $this->liberarAcessoMikrotik($macAddress, $ipAddress, $hoursAvailable);

            // Cria sessão WiFi
            $session = \App\Models\Session::create([
                'user_id' => $user->id,
                'payment_id' => null,
                'started_at' => now(),
                'session_status' => 'active'
            ]);

            Log::info('✅ Voucher validado e acesso liberado', [
                'voucher' => $voucherCode,
                'driver' => $voucher->driver_name,
                'hours_granted' => $hoursAvailable,
                'expires_at' => $user->expires_at,
            ]);

            return response()->json([
                'success' => true,
                'message' => "Bem-vindo, {$voucher->driver_name}! Acesso liberado.",
                'driver_name' => $voucher->driver_name,
                'hours_granted' => $hoursAvailable,
                'voucher_type' => $voucher->voucher_type,
                'expires_at' => $user->expires_at->format('Y-m-d H:i:s'),
                'remaining_hours_today' => $voucher->getRemainingHoursToday(),
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Código do voucher é obrigatório.'
            ], 422);
        } catch (\Exception $e) {
            Log::error('❌ Erro ao validar voucher', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro ao processar voucher. Tente novamente.'
            ], 500);
        }
    }

    /**
     * Libera acesso no Mikrotik (reutiliza lógica existente)
     */
    private function liberarAcessoMikrotik($macAddress, $ipAddress, $hours = 24)
    {
        try {
            // Buscar usuário pelo MAC
            $user = User::where('mac_address', $macAddress)->first();
            
            if (!$user) {
                Log::warning('⚠️ Usuário não encontrado para liberação Mikrotik', [
                    'mac' => $macAddress
                ]);
                return false;
            }

            // Tentar liberar via webhook service primeiro
            if (class_exists('\App\Services\MikrotikWebhookService')) {
                $webhookService = new \App\Services\MikrotikWebhookService;
                $liberado = $webhookService->liberarMacAddress($macAddress);
                
                if ($liberado) {
                    Log::info('🎉 Acesso liberado no Mikrotik via webhook (Voucher)', [
                        'user_id' => $user->id,
                        'mac' => $macAddress,
                        'hours' => $hours
                    ]);
                    return true;
                }
            }

            // Fallback: tentar controller MikrotikLiberacao
            if (class_exists('\App\Http\Controllers\MikrotikLiberacaoController')) {
                $mikrotikController = new \App\Http\Controllers\MikrotikLiberacaoController();
                $resultado = $mikrotikController->liberarAcessoImediato($user->id);
                
                if ($resultado) {
                    Log::info('✅ Acesso liberado no Mikrotik via controller (Voucher)', [
                        'user_id' => $user->id,
                        'mac' => $macAddress,
                        'hours' => $hours
                    ]);
                    return true;
                }
            }

            Log::info('ℹ️ Liberação será feita via sync automático do Mikrotik', [
                'mac' => $macAddress,
                'note' => 'Será liberado no próximo sync (10s)'
            ]);
            
            return true;

        } catch (\Exception $e) {
            Log::warning('⚠️ Erro ao tentar liberar no Mikrotik, mas acesso será liberado no próximo sync', [
                'mac' => $macAddress,
                'error' => $e->getMessage()
            ]);
            // Não lançar exceção - o acesso será liberado via sync automático
            return true;
        }
    }
}
