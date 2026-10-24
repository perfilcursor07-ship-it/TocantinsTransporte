<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Ntfy.sh - Push Notifications Gratuitas
    |--------------------------------------------------------------------------
    | Serviço gratuito de push notifications para celular.
    | Instale o app "ntfy" no celular e inscreva-se no tópico configurado.
    | https://ntfy.sh
    */
    'ntfy' => [
        'enabled' => env('NTFY_ENABLED', false),
        'server_url' => env('NTFY_SERVER_URL', 'https://ntfy.sh'),
        'topic' => env('NTFY_TOPIC', ''),
        'topic_starlink' => env('NTFY_TOPIC_STARLINK', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Together.ai — IA que responde no chat antes de escalar para humano
    |--------------------------------------------------------------------------
    */
    'together' => [
        'enabled' => env('CHAT_AI_ENABLED', false),
        'api_key' => env('TOGETHER_API_KEY'),
        'api_url' => env('TOGETHER_API_URL', 'https://api.together.xyz/v1/chat/completions'),
        'model' => env('CHAT_AI_MODEL', 'deepseek-ai/DeepSeek-V3'),
        'timeout' => env('CHAT_AI_TIMEOUT', 15),
        'max_turns' => env('CHAT_AI_MAX_TURNS', 10),
        'verify_ssl' => env('CHAT_AI_VERIFY_SSL', true),
        // Modelo com visão usado para ler o MAC na foto enviada no chat.
        // Vazio desativa a leitura (a foto vai direto pro atendente, como antes).
        'vision_model' => env('CHAT_AI_VISION_MODEL', 'meta-llama/Llama-4-Maverick-17B-128E-Instruct-FP8'),
        'vision_api_url' => env('CHAT_AI_VISION_API_URL'),
        'vision_timeout' => env('CHAT_AI_VISION_TIMEOUT', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cash Cash — proposta de crédito exibida no lugar do vídeo de desconto
    |--------------------------------------------------------------------------
    | O client_secret e o token NUNCA podem ir para log, código ou analytics.
    | A API é chamada só servidor-a-servidor (nunca pelo navegador do passageiro).
    */
    'cashcash' => [
        'enabled' => env('CASHCASH_ENABLED', false),
        'api_url' => rtrim(env('CASHCASH_API_URL', 'https://gw-30ff.cashcash.com.br/leads'), '/'),
        'app_url' => rtrim(env('CASHCASH_APP_URL', 'https://app-pwa-cash-dev.rafatella.workers.dev'), '/'),
        'client_id' => env('CASHCASH_CLIENT_ID'),
        'client_secret' => env('CASHCASH_CLIENT_SECRET'),
        'timeout' => (int) env('CASHCASH_TIMEOUT', 20),
        'token_timeout' => (int) env('CASHCASH_TOKEN_TIMEOUT', 10),

        // Último recurso para latitude/longitude (campos obrigatórios do parceiro).
        // Só entra em cena quando não há GPS do aparelho, o IP é privado e nenhum
        // ônibus tem coordenada conhecida. Padrão: Palmas/TO, região de operação.
        'default_latitude' => (float) env('CASHCASH_DEFAULT_LAT', -10.1689),
        'default_longitude' => (float) env('CASHCASH_DEFAULT_LNG', -48.3317),

        // "Plano B" do guia: em 502/503, mandar o passageiro para a tela inicial
        // do app, onde ele digita o CPF na mão. Desligado por padrão — sem o
        // handoff o CPF não vai preenchido e a experiência confunde mais do que ajuda.
        'fallback_to_app' => (bool) env('CASHCASH_FALLBACK_TO_APP', false),
    ],

];
