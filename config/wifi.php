<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WiFi Portal Configuration
    |--------------------------------------------------------------------------
    */

    'default_redirect_url' => env('WIFI_DEFAULT_REDIRECT_URL', 'https://www.google.com'),
    
    /*
    |--------------------------------------------------------------------------
    | MikroTik Configuration
    |--------------------------------------------------------------------------
    */
    
    'mikrotik' => [
        'host' => env('MIKROTIK_HOST', '192.168.88.1'),
        'username' => env('MIKROTIK_USERNAME', 'admin'),
        'password' => env('MIKROTIK_PASSWORD', ''),
        'port' => env('MIKROTIK_PORT', 8728),
        'ssh_port' => env('MIKROTIK_SSH_PORT', 22),
        'timeout' => env('MIKROTIK_TIMEOUT', 10),
        'enabled' => env('MIKROTIK_ENABLED', true),
        'sync_token' => env('MIKROTIK_SYNC_TOKEN', 'mikrotik-sync-2024'),
        // IMPORTANTE: Usar IP interno do hotspot, NÃO domínio .local
        // O domínio .local não resolve quando o tráfego sai pela internet
        'login_url' => env('MIKROTIK_LOGIN_URL', 'http://10.5.50.1/login'),
        'force_login_redirect' => filter_var(env('MIKROTIK_FORCE_LOGIN_REDIRECT', true), FILTER_VALIDATE_BOOL),
        'force_login_redirect_local' => filter_var(env('MIKROTIK_FORCE_LOGIN_REDIRECT_LOCAL', false), FILTER_VALIDATE_BOOL),
        'force_login_redirect_outside_hotspot' => filter_var(env('MIKROTIK_FORCE_LOGIN_REDIRECT_OUTSIDE_HOTSPOT', true), FILTER_VALIDATE_BOOL),
        'hotspot_subnets' => array_filter(array_map('trim', explode(',', env('MIKROTIK_HOTSPOT_SUBNETS', '10.5.50.0/24,10.10.10.0/24')))),
    ],
    
    'mikrotik_sync_token' => env('MIKROTIK_SYNC_TOKEN', 'mikrotik-sync-2024'),
    'server_url' => env('APP_URL', 'https://www.tocantinstransportewifi.com.br'),

    /*
    |--------------------------------------------------------------------------
    | Pricing Configuration
    |--------------------------------------------------------------------------
    */
    
    'pricing' => [
        'default_price' => env('WIFI_DEFAULT_PRICE', 6.99),
        'session_duration_hours' => env('WIFI_SESSION_DURATION_HOURS', 12),
        'trial_duration_minutes' => env('WIFI_TRIAL_DURATION_MINUTES', 15),
    ],

    /*
    |--------------------------------------------------------------------------
    | PIX Configuration
    |--------------------------------------------------------------------------
    */
    
    'pix' => [
        'key' => env('PIX_KEY', 'pix@tocantinstransportewifi.com.br'),
        'merchant_name' => env('PIX_MERCHANT_NAME', 'TocantinsTransportWiFi'),
        'merchant_city' => env('PIX_MERCHANT_CITY', 'Palmas'),
        'base_url' => env('PIX_BASE_URL', 'pix.tocantins.com.br'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Configuration
    |--------------------------------------------------------------------------
    */
    
    'payment_gateways' => [
        'pix' => [
            'enabled' => env('PIX_ENABLED', true),
            'gateway' => env('PIX_GATEWAY', 'santander'),
            
            // Configurações Woovi
            'woovi_app_id' => env('WOOVI_APP_ID', ''),
            'woovi_app_secret' => env('WOOVI_APP_SECRET', ''),
            
            // Configurações PagBank (PagSeguro)
            'pagbank_token' => env('PAGBANK_TOKEN', ''),
            'pagbank_email' => env('PAGBANK_EMAIL', ''),
            
            // Configurações Santander PIX
            'client_id' => env('SANTANDER_CLIENT_ID', ''),
            'client_secret' => env('SANTANDER_CLIENT_SECRET', ''),
            'workspace_id' => env('SANTANDER_WORKSPACE_ID', ''),
            'certificate_path' => env('SANTANDER_CERTIFICATE_PATH', 'certificates/santander.pem'),
            'certificate_password' => env('SANTANDER_CERTIFICATE_PASSWORD', ''),
            'station_code' => env('SANTANDER_STATION_CODE', ''), // Código de Estação
            'use_jws' => filter_var(env('SANTANDER_USE_JWS', true), FILTER_VALIDATE_BOOL), // JWS OBRIGATÓRIO para Santander PIX
            
            'environment' => env('PIX_ENVIRONMENT', 'production'), // sandbox ou production
        ],
        'card' => [
            'enabled' => env('CARD_ENABLED', true),
            'gateway' => env('CARD_GATEWAY', 'stripe'), // stripe, mercadopago, pagseguro
            'key' => env('CARD_API_KEY', ''),
            'secret' => env('CARD_API_SECRET', ''),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment Gateway Configuration (Alternative)
    |--------------------------------------------------------------------------
    */
    
    'payment' => [
        'default_gateway' => env('PIX_GATEWAY', 'santander'),
        'enabled_gateways' => [
            'woovi' => env('PIX_ENABLED', true),
            'santander' => env('SANTANDER_PIX_ENABLED', true),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Instagram Free Access Configuration
    |--------------------------------------------------------------------------
    */
    
    'instagram' => [
        'enabled' => env('INSTAGRAM_FREE_ENABLED', true),
        'required_time_seconds' => env('INSTAGRAM_REQUIRED_TIME', 120),
        'free_duration_minutes' => env('INSTAGRAM_FREE_DURATION', 30),
        'questions' => [
            'Qual a cor do ônibus?' => 'azul',
            'Qual o nome da empresa?' => 'tocantins',
            'Quantas rodas tem o ônibus?' => '6',
        ]
    ],

    /*
    |--------------------------------------------------------------------------
    | UI Configuration
    |--------------------------------------------------------------------------
    */
    
    'ui' => [
        'brand_name' => env('WIFI_BRAND_NAME', 'WiFi Tocantins'),
        'brand_color' => env('WIFI_BRAND_COLOR', '#007bff'),
        'logo_url' => env('WIFI_LOGO_URL', '/images/logo.png'),
        'welcome_message' => env('WIFI_WELCOME_MESSAGE', 'Bem-vindo ao WiFi Tocantins Express!'),
    ]
];


