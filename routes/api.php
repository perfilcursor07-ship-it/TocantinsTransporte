<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\PortalController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\VoucherController;
use App\Http\Controllers\MikrotikController;
use App\Http\Controllers\MikrotikSyncController;
use App\Http\Controllers\MikrotikApiController;
use App\Http\Controllers\RegistrationController;
use App\Http\Controllers\Admin\WhatsappController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    return $request->user();
});

// Detectar dispositivo
Route::post('/detect-device', [PortalController::class, 'detectDevice']);
Route::get('/connection-check', [PortalController::class, 'connectionCheck']);

// Registro de usuários (landing) - legado
Route::post('/register', [RegistrationController::class, 'register']);
Route::post('/register-for-payment', [RegistrationController::class, 'registerForPayment']);
Route::post('/check-email', [RegistrationController::class, 'checkEmail']);
Route::post('/check-user', [RegistrationController::class, 'checkUser']);

// Verificar usuário por MAC
Route::get('/user/check-mac/{mac}', [RegistrationController::class, 'checkMacAddress']);
Route::post('/interval/connect', [\App\Http\Controllers\IntervalAccessController::class, 'connect'])->middleware('throttle:30,1');

// Pagamentos
Route::prefix('payment')->group(function () {
    Route::post('/pix', [PaymentController::class, 'processPix']);
    Route::post('/pix/generate-qr', [PaymentController::class, 'generatePixQRCode']);
    Route::get('/pix/status', [PaymentController::class, 'checkPixStatus']);
    Route::post('/pix/temp-bypass', [PaymentController::class, 'activateTempBypass']);
    Route::post('/pix/send-email', [PaymentController::class, 'sendPixEmail']);
    Route::post('/card', [PaymentController::class, 'processCard']);
    Route::post('/process', [PaymentController::class, 'process']);
    Route::post('/webhook', [PaymentController::class, 'webhook']);
    Route::post('/webhook/santander', [PaymentController::class, 'santanderWebhook']);
    Route::post('/webhook/woovi', [PaymentController::class, 'wooviWebhook']);
    Route::post('/webhook/woovi/created', [PaymentController::class, 'wooviWebhookCreated']);
    Route::post('/webhook/woovi/expired', [PaymentController::class, 'wooviWebhookExpired']);
    Route::post('/webhook/woovi/transaction', [PaymentController::class, 'wooviWebhookTransaction']);
    Route::post('/webhook/woovi/different-payer', [PaymentController::class, 'wooviWebhookDifferentPayer']);
    Route::post('/webhook/pagbank', [PaymentController::class, 'pagbankWebhook']);
    Route::get('/test-santander', [PaymentController::class, 'testSantanderConnection']);
    Route::get('/test-woovi', [PaymentController::class, 'testWooviConnection']);
    Route::get('/test-pagbank', [PaymentController::class, 'testPagBankConnection']);
    Route::get('/export-pagbank-logs', [PaymentController::class, 'exportPagBankLogs']);
});

// Turismo: webhook PagBank próprio (não passa pelo webhook do portal)
Route::post('/tourism/webhook/pagbank', [\App\Http\Controllers\TourismPaymentController::class, 'webhook'])
    ->middleware('throttle:60,1');

// 🔧 Reativar acesso (usuário pagou mas não conectou)
Route::post('/reativar-acesso', [PaymentController::class, 'reactivateAccess']);

// Vouchers
Route::prefix('voucher')->group(function () {
    Route::post('/apply', [VoucherController::class, 'apply']);
    Route::get('/validate/{code}', [VoucherController::class, 'validate']);
    Route::post('/validate', [PortalController::class, 'validateVoucher']); // Novo endpoint para motoristas
});

// Legacy Instagram promotion endpoint (disabled; paid plans are the current offer)
Route::post('/instagram/free-access', [PortalController::class, 'instagramFreeAccess']);

// WireGuard Sync (Secure Tunnel)
Route::prefix('mikrotik-sync')->group(function () {
    Route::post('/real-macs', [App\Http\Controllers\WireGuardSyncController::class, 'receiveRealMacs']);
    Route::post('/new-client', [App\Http\Controllers\WireGuardSyncController::class, 'newClient']);
    Route::post('/heartbeat', [App\Http\Controllers\WireGuardSyncController::class, 'heartbeat']);
});

// MikroTik Integration (Legacy - Direct API)
Route::prefix('mikrotik')->group(function () {
    Route::get('/status/{mac}', [MikrotikController::class, 'getStatus']);
    Route::post('/allow', [MikrotikController::class, 'allowDevice']);
    Route::post('/block', [MikrotikController::class, 'blockDevice']);
    Route::get('/usage/{mac}', [MikrotikController::class, 'getUsage']);
    
    // 🚀 NOVOS ENDPOINTS PARA AUTOMAÇÃO MIKROTIK
    Route::match(['GET', 'POST'], '/check-paid-users', [MikrotikApiController::class, 'checkPaidUsers']);
    Route::get('/check-paid-users-lite', [MikrotikApiController::class, 'checkPaidUsersLite']); // Ultra-leve para hAP ac²
    Route::get('/clean-expired', [MikrotikApiController::class, 'cleanExpiredUsers']); // Limpar expirados antigos
    Route::post('/report-mac', [MikrotikApiController::class, 'reportMacAddress']);
    Route::post('/confirm-liberation', [MikrotikApiController::class, 'confirmMacLiberation']);
    Route::get('/register-mac', [MikrotikApiController::class, 'registerMac']);
    
    // 🔧 ENDPOINTS DE DIAGNÓSTICO E DEBUG
    Route::get('/check-mac', [MikrotikApiController::class, 'checkMacStatus']); // Verificar status de um MAC
    Route::match(['GET', 'POST'], '/force-liberate', [MikrotikApiController::class, 'forceLiberate']); // Forçar liberação
    Route::get('/diagnostics', [MikrotikApiController::class, 'diagnostics']); // Diagnóstico geral
    
    // 🎛️ REMOTE ADMIN PANEL - Command Queue
    Route::get('/get-commands', [MikrotikApiController::class, 'getCommands']); // Buscar comandos pendentes
    Route::post('/command-result', [MikrotikApiController::class, 'commandResult']); // Reportar resultado de comando
    
    // 🏦 WALLED GARDEN - BANCOS BRASILEIROS
    Route::get('/walled-garden/domains', [\App\Http\Controllers\WalledGardenController::class, 'getDomains']);
    Route::get('/walled-garden/ip-ranges', [\App\Http\Controllers\WalledGardenController::class, 'getIpRanges']);
    Route::get('/walled-garden/all', [\App\Http\Controllers\WalledGardenController::class, 'getAll']);
    Route::get('/walled-garden/script', [\App\Http\Controllers\WalledGardenController::class, 'getRouterOSScript']);
});

// MikroTik Sync (New - HTTP Polling)
Route::prefix('mikrotik-sync')->group(function () {
    Route::get('/ping', [MikrotikSyncController::class, 'ping']);
    Route::match(['GET', 'POST'], '/pending-users', [MikrotikSyncController::class, 'getPendingUsers']);
    Route::post('/check-access', [MikrotikSyncController::class, 'checkUserAccess']);
    Route::post('/report-status', [MikrotikSyncController::class, 'reportUserStatus']);
    Route::post('/report-real-mac', [MikrotikSyncController::class, 'reportRealMac']);
    Route::get('/stats', [MikrotikSyncController::class, 'getStats']);
});

// WhatsApp Webhook (recebe notificações do servidor Baileys)
Route::post('/whatsapp/webhook', [WhatsappController::class, 'webhook']);

// Chat API (Atendimento Online)
Route::prefix('chat')->group(function () {
    Route::post('/start', [App\Http\Controllers\ChatApiController::class, 'startConversation']);
    Route::post('/send', [App\Http\Controllers\ChatApiController::class, 'sendMessage']);
    Route::post('/upload-receipt', [App\Http\Controllers\ChatApiController::class, 'uploadReceipt']);
    Route::post('/upload-mac', [App\Http\Controllers\ChatApiController::class, 'uploadMac']);
    Route::get('/messages', [App\Http\Controllers\ChatApiController::class, 'getMessages']);
    Route::get('/check', [App\Http\Controllers\ChatApiController::class, 'checkNewMessages']);
});
