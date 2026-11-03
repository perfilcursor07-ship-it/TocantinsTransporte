<?php

namespace App\Http\Controllers;

use App\Models\MikrotikMacReport;
use App\Models\Payment;
use App\Models\Session;
use App\Models\SystemSetting;
use App\Models\User;
use App\Services\PixQRCodeService;
use App\Services\SantanderPixService;
use App\Services\WooviPixService;
use App\Support\HotspotIdentity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class PaymentController extends Controller
{
    private function whatsappHttpClient()
    {
        $client = \Illuminate\Support\Facades\Http::connectTimeout(2)->timeout(10);

        // 🔐 Anexa a API key do servidor Baileys, se configurada.
        if ($key = \App\Services\WhatsappClient::apiKey()) {
            $client = $client->withHeaders(['X-API-Key' => $key]);
        }

        return $client;
    }

    /**
     * Gera QR Code PIX para pagamento
     */
    public function generatePixQRCode(Request $request)
    {
        $intervalQuote = null;
        if ($request->input('plan_type') === 'interval') {
            try {
                $intervalQuote = app(\App\Services\IntervalPlanService::class)->quote($request->all());
            } catch (\Illuminate\Validation\ValidationException $e) {
                return response()->json(['success' => false, 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
            }
            $request->merge([
                'amount' => $intervalQuote['interval']['total_cents'] / 100,
                'plan_duration' => $intervalQuote['duration_hours'],
                'plan_name' => $intervalQuote['plan_name'],
                'plan_suffix' => $intervalQuote['plan_suffix'],
            ]);
        }
        // Validação dos dados
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.05',
            'mac_address' => 'required|string',
            'user_id' => 'nullable|exists:users,id',
            'ip_address' => 'nullable|ip',
            'plan_duration' => 'nullable|numeric|min:0.1|max:168',
            'plan_name' => 'nullable|string|max:80',
            'plan_suffix' => 'nullable|string|max:20',
            'whatsapp_payment_opt_in' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            DB::beginTransaction();

            // 🔥 FORÇAR report de MAC real antes de criar pagamento
            $this->forceMacReport($request);

            // 🎯 BUSCAR OU CRIAR USUÁRIO COM MAC
            $clientIp = HotspotIdentity::resolveClientIp($request);
            $macAddress = HotspotIdentity::resolveRealMac($request->input('mac_address'), $clientIp);

            if (! $macAddress) {
                Log::warning('⚠️ MAC inválido ou ausente no request', [
                    'mac_address' => $request->input('mac_address'),
                    'ip' => $clientIp,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Não foi possível identificar o dispositivo. Tente reconectar ao Wi-Fi.',
                ], 422);
            }

            // 🔧 FIX: Se frontend enviou user_id (do registerForPayment), priorizar esse usuário
            // Isso evita que o sistema troque o usuário quando IPs coincidem entre ônibus
            $user = null;
            
            if ($request->user_id) {
                $user = User::find($request->user_id);
                if ($user) {
                    // Atualizar MAC e IP se necessário
                    $userUpdates = [];
                    if ($macAddress && HotspotIdentity::shouldReplaceMac($user->mac_address, $macAddress)) {
                        // Limpar MAC de outros registros que possam ter este mesmo MAC (constraint unique)
                        User::where('mac_address', $macAddress)
                            ->where('id', '!=', $user->id)
                            ->update(['mac_address' => null]);
                        
                        // 🗑️ Marcar MAC antigo para remoção no Mikrotik
                        HotspotIdentity::markOrphanedMac($user->mac_address);
                        
                        Log::info('🔄 MAC ATUALIZADO no pagamento (user_id)', [
                            'user_id' => $user->id,
                            'old_mac' => $user->mac_address,
                            'new_mac' => $macAddress,
                        ]);
                        
                        $userUpdates['mac_address'] = $macAddress;
                    }
                    if ($clientIp && $user->ip_address !== $clientIp) {
                        $userUpdates['ip_address'] = $clientIp;
                    }
                    if (!empty($userUpdates)) {
                        $user->update($userUpdates);
                    }

                    Log::info('🔄 Usando usuário do registro (user_id)', [
                        'user_id' => $user->id,
                        'mac_address' => $user->mac_address,
                    ]);
                }
            }

            if (!$user) {
            // Verificar se já existe usuário com este MAC
            $existingUserByMac = User::where('mac_address', $macAddress)->first();
            
            if ($existingUserByMac) {
                // Usuário com este MAC já existe - usar ele
                $user = $existingUserByMac;
                
                // Atualizar IP se necessário
                if ($clientIp && $user->ip_address !== $clientIp) {
                    $user->update(['ip_address' => $clientIp]);
                }
                
                Log::info('🔄 Reutilizando usuário existente pelo MAC', [
                    'user_id' => $user->id,
                    'mac_address' => $macAddress,
                ]);
            } else {
                // Buscar ou criar usuário pelo MAC
                $user = $this->findOrCreateUser($macAddress, $clientIp);
            }
            }

            if ($intervalQuote) {
                // Plano de vários dias ainda em uso: só compra de novo depois que expirar.
                $activeInterval = \App\Models\IntervalAccessDay::where('user_id', $user->id)
                    ->where('expires_at', '>', now())
                    ->whereHas('payment', fn ($q) => $q->where('status', 'completed'))
                    ->orderByDesc('expires_at')->first();
                if ($activeInterval) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Você já tem internet ativa até '
                        .$activeInterval->expires_at->format('d/m/Y H:i').'. Compre de novo quando ela terminar.'], 422);
                }

                $newStart = $intervalQuote['interval']['start'];
                $newEnd = $intervalQuote['interval']['end'];
                $overlaps = $user->payments()->where('status', 'completed')
                    ->where('payment_data->plan_type', 'interval')
                    ->where(function ($q) use ($newStart, $newEnd) {
                        // Plano contínuo pago e ainda não iniciado, com datas sobrepostas.
                        $q->where(function ($c) use ($newStart, $newEnd) {
                            $c->where('payment_data->interval->mode', \App\Services\IntervalPlanService::MODE_CONTINUOUS)
                                ->whereNotIn('id', \App\Models\IntervalAccessDay::query()->select('payment_id'))
                                ->where('payment_data->interval->start', '<', $newEnd)
                                ->where('payment_data->interval->end', '>', $newStart);
                        })->orWhere(function ($l) use ($newStart, $newEnd) {
                            // Pagamento antigo (uma diária por data), regra de antes.
                            $l->whereNull('payment_data->interval->mode')
                                ->where('payment_data->interval->start', '<=', $newEnd)
                                ->where('payment_data->interval->end', '>=', $newStart);
                        });
                    })->exists();
                if ($overlaps) {
                    DB::rollBack();
                    return response()->json(['success' => false, 'message' => 'Você já possui um plano pago nessas datas. Escolha outras datas.'], 422);
                }
            }

            // O passageiro pode autorizar atualizações na própria etapa de
            // pagamento. O consentimento fica vinculado ao telefone atual.
            if ($request->boolean('whatsapp_payment_opt_in') && $user->phone) {
                $cleanPhone = preg_replace('/\D/', '', $user->phone);
                $user->update([
                    'whatsapp_payment_opt_in_at' => now(),
                    'whatsapp_payment_opt_in_phone' => $cleanPhone,
                    'whatsapp_payment_opt_in_source' => 'portal_payment',
                ]);
                \App\Models\WhatsappOptOut::optIn($cleanPhone);
            }

            // Verificar qual gateway usar
            $gateway = SystemSetting::getValue('pix_gateway', config('wifi.payment_gateways.pix.gateway'));

            $planDuration = max((float) $request->input('plan_duration', \App\Helpers\SettingsHelper::getSessionDuration()), 0.1);
            $planName = $request->input('plan_name', 'Viagem completa');
            $planSuffix = $request->input('plan_suffix', '/ viagem');

            // Criar registro de pagamento
            $payment = Payment::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'payment_type' => 'pix',
                'status' => 'pending',
                'transaction_id' => $this->generateTransactionId(),
                'payment_data' => $intervalQuote ?? [
                    'plan_name' => $planName,
                    'plan_suffix' => $planSuffix,
                    'duration_hours' => $planDuration,
                ],
            ]);

            // 🔧 FIX: Usar SettingsHelper para ler tokens do banco de dados (painel admin)
            // config() lê do .env que pode estar vazio mesmo com token configurado no painel
            $pagbankTokenCheck = \App\Helpers\SettingsHelper::getPagBankToken();

            if ($gateway === 'woovi' && config('wifi.payment_gateways.pix.woovi_app_id')) {
                // Usar API da Woovi
                $wooviService = new WooviPixService;
                $qrData = $wooviService->createPixPayment(
                    $request->amount,
                    'WiFi Tocantins Express - Internet Premium',
                    $payment->transaction_id
                );

                if (! $qrData['success']) {
                    throw new \Exception($qrData['message']);
                }

                // Atualizar payment com dados da Woovi
                $payment->update([
                    'pix_emv_string' => $qrData['qr_code_text'],
                    'pix_location' => $qrData['correlation_id'],
                    'gateway_payment_id' => $qrData['woovi_id'],
                ]);

                // Gerar image_url baseado no tipo retornado pela Woovi
                $imageUrl = '';
                if (! empty($qrData['qr_code_image'])) {
                    if (! empty($qrData['qr_code_is_url']) && $qrData['qr_code_is_url']) {
                        // Woovi retornou uma URL direta para a imagem
                        $imageUrl = $qrData['qr_code_image'];
                        Log::info('Usando URL da Woovi para QR Code: '.$imageUrl);
                    } else {
                        // Woovi retornou base64
                        $imageUrl = 'data:image/png;base64,'.$qrData['qr_code_image'];
                        Log::info('Usando base64 da Woovi para QR Code');
                    }
                } else {
                    // Fallback: usar API externa para gerar QR Code
                    $encodedEmv = urlencode($qrData['qr_code_text']);
                    $imageUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encodedEmv}";
                    Log::info('Usando fallback para QR Code image: '.$imageUrl);
                }

                $response = [
                    'emv_string' => $qrData['qr_code_text'],
                    'image_url' => $imageUrl,
                    'amount' => number_format($qrData['amount'], 2, '.', ''),
                    'transaction_id' => $qrData['correlation_id'],
                    'payment_id' => $qrData['woovi_id'],
                    'payment_link' => $qrData['payment_link'],
                    'expires_at' => $qrData['expires_at'],
                ];

            } elseif ($gateway === 'pagbank' && $pagbankTokenCheck) {
                // Usar API do PagBank
                $pagbankService = new \App\Services\PagBankPixService;
                $qrData = $pagbankService->createPixPayment(
                    $request->amount,
                    'WiFi Tocantins Express - Internet Premium',
                    $payment->transaction_id
                );

                if (! $qrData['success']) {
                    throw new \Exception($qrData['message'] ?? 'Erro ao criar pagamento PagBank');
                }

                Log::info('✅ QR Code PagBank gerado', [
                    'order_id' => $qrData['order_id'] ?? null,
                    'reference_id' => $qrData['reference_id'] ?? null,
                ]);

                // Atualizar payment com dados do PagBank
                $payment->update([
                    'pix_emv_string' => $qrData['qr_code_text'],
                    'pix_location' => $qrData['reference_id'],
                    'gateway_payment_id' => $qrData['order_id'],
                ]);

                $response = [
                    'emv_string' => $qrData['qr_code_text'],
                    'image_url' => $qrData['qr_code_image'],
                    'amount' => number_format($qrData['amount'], 2, '.', ''),
                    'transaction_id' => $qrData['reference_id'],
                    'payment_id' => $qrData['order_id'],
                    'expires_at' => $qrData['expires_at'] ?? null,
                ];

            } elseif ($gateway === 'santander' && config('wifi.payment_gateways.pix.client_id')) {
                // Usar API do Santander PIX
                $santanderService = new SantanderPixService;
                $qrData = $santanderService->createPixPayment(
                    $request->amount,
                    'WiFi Tocantins Express - Internet Premium',
                    $payment->transaction_id // Usar o TXId gerado pelo sistema (já tem 30 caracteres)
                );

                if (!$qrData['success']) {
                    throw new \Exception($qrData['message'] ?? 'Erro ao criar pagamento Santander');
                }

                // Atualizar transaction_id com o TXId do Santander
                $payment->transaction_id = $qrData['txid'];
                $payment->save();

                Log::info('✅ QR Code Santander gerado', [
                    'txid' => $qrData['txid'] ?? null,
                    'location' => $qrData['location'] ?? null,
                    'status' => $qrData['status'] ?? null,
                ]);

                // Atualizar payment com dados do Santander
                $payment->update([
                    'pix_emv_string' => $qrData['qr_code_text'],
                    'pix_location' => $qrData['location'],
                    'gateway_payment_id' => $qrData['txid'], // TXId é o identificador único
                ]);

                $response = [
                    'emv_string' => $qrData['qr_code_text'],
                    'image_url' => $qrData['qr_code_image'] ?? $santanderService->generateQRCodeImageUrl($qrData['qr_code_text']),
                    'amount' => number_format($qrData['amount'], 2, '.', ''),
                    'transaction_id' => $payment->transaction_id,
                    'payment_id' => $payment->id,
                    'txid' => $qrData['txid'], // TXId Santander
                    'location' => $qrData['location'], // Location Santander
                    'expires_in' => $qrData['expiration'] ?? 900, // Segundos até expirar
                ];

            } else {
                // Fallback: Usar gerador EMV manual
                $pixService = new PixQRCodeService;
                $qrData = $pixService->generatePixQRCode($request->amount, $payment->transaction_id);

                // Atualizar payment com dados do PIX
                $payment->update([
                    'pix_emv_string' => $qrData['emv_string'],
                    'pix_location' => $qrData['location'],
                ]);

                $response = [
                    'emv_string' => $qrData['emv_string'],
                    'image_url' => $pixService->generateQRCodeImageUrl($qrData['emv_string']),
                    'amount' => $qrData['amount'],
                    'transaction_id' => $qrData['transaction_id'],
                ];
            }

            DB::commit();

            // 🚌 Detectar ônibus pelo IP público (MikroTik cacheia IP→serial a cada sync)
            // SEMPRE atualiza quando o IP atual aponta para um ônibus: o usuário pode
            // ter viajado em outro ônibus antes (last_mikrotik_id antigo) e o pagamento
            // seria atribuído/sincronizado ao ônibus errado.
            $publicIp = $request->ip();
            $mikrotikId = cache()->get('mikrotik_ip_' . $publicIp);
            if ($mikrotikId && $user->last_mikrotik_id !== $mikrotikId) {
                $user->update(['last_mikrotik_id' => $mikrotikId]);
                cache()->forget('mikrotik_sync_lists_all');
                Log::info('🚌 Usuário reassociado ao ônibus atual no pagamento', [
                    'user_id' => $user->id,
                    'old_mikrotik_id' => $user->getOriginal('last_mikrotik_id'),
                    'new_mikrotik_id' => $mikrotikId,
                    'public_ip' => $publicIp,
                ]);
            }

            // Este é o ponto em que o ônibus é conhecido com certeza (pelo IP
            // público). Congela no pagamento para o relatório do mês não mudar
            // de dono depois, quando o passageiro viajar em outro carro.
            //
            // Roda em terminating(), ou seja DEPOIS que a resposta já foi
            // entregue ao usuário. Entre o DB::commit() acima e o return de
            // sucesso existe um catch que responderia erro: se este código
            // estivesse ali e falhasse, o usuário veria erro mesmo com o QR Code
            // pronto. Daqui, nada que aconteça aqui alcança a resposta.
            // O try/catch (e o do log) são a segunda camada.
            if ($mikrotikId) {
                app()->terminating(function () use ($payment, $mikrotikId) {
                    try {
                        $payment->forceFill(['bus_mikrotik_id' => $mikrotikId])->saveQuietly();
                    } catch (\Throwable $e) {
                        try {
                            Log::warning('Não foi possível congelar o ônibus do pagamento', [
                                'payment_id' => $payment->id,
                                'mikrotik_id' => $mikrotikId,
                                'erro' => $e->getMessage(),
                            ]);
                        } catch (\Throwable) {
                            // Nem falha de log pode escapar daqui.
                        }
                    }
                });
            }

            // 🎯 LOG COMPLETO DO PAGAMENTO CRIADO
            Log::info('💳 PAGAMENTO PIX CRIADO COM SUCESSO', [
                'payment_id' => $payment->id,
                'user_id' => $user->id,
                'mac_address' => $user->mac_address,
                'ip_address' => $user->ip_address,
                'amount' => $request->amount,
                'gateway' => $gateway,
                'transaction_id' => $payment->transaction_id,
            ]);

            // 📱 NÃO enviar PIX por WhatsApp na geração do QR Code.
            // O envio acontece apenas no lembrete (após 5 min sem pagar),
            // via comando payments:send-unpaid-reminders, evitando spam de
            // mensagens toda vez que o usuário gera um QR Code.

            // 📧 EMAIL será enviado quando o usuário copiar o código (via JS)

            return response()->json([
                'success' => true,
                'message' => 'QR Code PIX gerado com sucesso!',
                'payment_id' => $payment->id,
                'gateway' => $gateway,
                'qr_code' => $response,
                'interval' => $intervalQuote['interval'] ?? null,
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Erro ao gerar QR Code PIX: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar QR Code PIX: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Processa pagamento PIX (mantido para compatibilidade)
     */
    public function processPix(Request $request)
    {
        // Redirecionar para geração de QR Code
        return $this->generatePixQRCode($request);
    }

    /**
     * 📱 Envia o código PIX via WhatsApp para o usuário
     * Chamado automaticamente após gerar o QR Code
     * Não bloqueia a resposta ao frontend em caso de erro
     */
    private function sendPixViaWhatsapp(User $user, Payment $payment, array $pixData): void
    {
        try {
            if (!$user->phone || strlen($user->phone) < 10) return;
            if (!\App\Models\WhatsappSetting::isConnected()) return;
            if (!$user->hasWhatsappPaymentOptIn()) return;
            if (\App\Models\WhatsappOptOut::isOptedOut($user->phone)) return;

            $phone = \App\Models\WhatsappMessage::formatPhone($user->phone);
            $amount = number_format((float) ($pixData['amount'] ?? $payment->amount), 2, ',', '.');
            $pixCode = $pixData['emv_string'] ?? $payment->pix_emv_string;

            if (!$pixCode) return;

            $nome = $user->name ?? 'Cliente';
            $baileysUrl = env('BAILEYS_SERVER_URL', 'http://localhost:3001');

            // 1ª mensagem: instrução
            $message1 = "🚌 *Tocantins Transporte WiFi*\n\n"
                      . "Olá {$nome}! PIX de *R\$ {$amount}* gerado.\n"
                      . "⏱️ Válido por 3 min.\n\n"
                      . "👇 *Copie o código na próxima mensagem e cole no app do banco.*";

            $msg1 = \App\Models\WhatsappMessage::create([
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'phone' => $phone,
                'message' => $message1,
                'status' => 'pending',
            ]);

            $resp1 = $this->whatsappHttpClient()->post($baileysUrl . '/send', [
                'phone' => $phone,
                'message' => $message1,
                'priority' => true,   // transacional: envia na hora, sem "digitando"
                'skipCheck' => true,  // número já conhecido: não sondar (mais rápido e seguro)
            ]);

            if ($resp1->successful()) {
                $msg1->markAsSent($resp1->json('messageId'));
            } else {
                $msg1->markAsFailed($resp1->body());
                return;
            }

            // Delay para manter ordem
            sleep(2);

            // 2ª mensagem: só o código PIX (fácil de copiar)
            $msg2 = \App\Models\WhatsappMessage::create([
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'phone' => $phone,
                'message' => $pixCode,
                'status' => 'pending',
            ]);

            $resp2 = $this->whatsappHttpClient()->post($baileysUrl . '/send', [
                'phone' => $phone,
                'message' => $pixCode,
                'priority' => true,
                'skipCheck' => true,
            ]);

            if ($resp2->successful()) {
                $msg2->markAsSent($resp2->json('messageId'));
                Log::info('📱 WhatsApp PIX enviado (2 msgs)', ['payment_id' => $payment->id, 'phone' => $phone]);
            } else {
                $msg2->markAsFailed($resp2->body());
            }

        } catch (\Exception $e) {
            Log::error('📱 WhatsApp PIX: Exceção', [
                'payment_id' => $payment->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * � Envia código PIX por e-mail
     */
    private function sendPixViaEmail(User $user, Payment $payment, array $pixData): void
    {
        try {
            if (!$user->email) return;

            $amount = number_format((float) ($pixData['amount'] ?? $payment->amount), 2, ',', '.');
            $pixCode = $pixData['emv_string'] ?? $payment->pix_emv_string;
            if (!$pixCode) return;

            $nome = $user->name ?? 'Cliente';

            \Illuminate\Support\Facades\Mail::to($user->email)->send(
                new \App\Mail\PixCodeMail($nome, $amount, $pixCode)
            );

            Log::info('📧 Email PIX enviado', ['payment_id' => $payment->id, 'email' => $user->email]);
        } catch (\Exception $e) {
            Log::error('📧 Email PIX falhou', ['error' => $e->getMessage(), 'email' => $user->email ?? null]);
        }
    }

    /**
     * Endpoint para enviar PIX por email (chamado pelo JS após copiar código)
     */
    public function sendPixEmail(Request $request)
    {
        try {
            $payment = Payment::find($request->input('payment_id'));
            if (!$payment) return response()->json(['success' => false]);

            $user = User::find($payment->user_id);
            if (!$user || !$user->email) return response()->json(['success' => false, 'message' => 'Sem email']);

            $pixCode = $payment->pix_emv_string;
            if (!$pixCode) return response()->json(['success' => false]);

            $this->sendPixViaEmail($user, $payment, [
                'amount' => $payment->amount,
                'emv_string' => $pixCode,
            ]);

            return response()->json(['success' => true, 'message' => 'Email enviado']);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Envia mensagem WhatsApp confirmando pagamento aprovado
     */
    private function sendPaymentConfirmedWhatsapp(User $user, Payment $payment, float $hours): void
    {
        try {
            if (!$user->phone || strlen($user->phone) < 10) return;
            if (!\App\Models\WhatsappSetting::isConnected()) return;
            if (!$user->hasWhatsappPaymentOptIn()) return;
            if (\App\Models\WhatsappOptOut::isOptedOut($user->phone)) return;

            // Webhooks podem ser repetidos pelo gateway. Uma confirmação por
            // pagamento é suficiente e evita duplicidade para o passageiro.
            $payment->refresh();
            if ($payment->whatsapp_confirmation_sent_at) return;

            $phone = \App\Models\WhatsappMessage::formatPhone($user->phone);
            $nome = $user->name ? trim(explode(' ', $user->name)[0]) : 'Cliente';
            $horasTexto = $hours == (int) $hours ? (int) $hours . ' horas' : $hours . ' horas';
            $amount = number_format((float) $payment->amount, 2, ',', '.');

            $message = "✅ *Pagamento confirmado!*\n\n"
                     . "Oi {$nome}! Recebemos seu PIX de *R\$ {$amount}*.\n\n"
                     . "📶 Sua internet será liberada em até 30 segundos por *{$horasTexto}*.\n\n"
                     . "Se não conectar, abra: " . url('/reativar') . "\n\n"
                     . "_Para parar as atualizações de pagamento, responda *PARAR*._";

            if (\App\Services\IntervalPlanService::isInterval($payment)) {
                $interval = $payment->payment_data['interval'];
                $start = \Carbon\Carbon::parse($interval['start'])->format('d/m/Y');
                $end = \Carbon\Carbon::parse($interval['end'])->format('d/m/Y');
                $message = \App\Services\IntervalPlanService::isContinuous($payment)
                    ? "Pagamento confirmado!\n\nOi {$nome}! Recebemos seu PIX de R$ {$amount}.\n\n"
                        . "Plano de {$interval['days']} dia(s): {$interval['total_hours']} horas seguidas de internet ({$start} a {$end}). "
                        . "Se você já está no ônibus, a internet libera agora e fica ativa direto até o fim do período, sem precisar entrar no portal todo dia. "
                        . "Se comprou para outra data, o plano começa quando você se conectar no Wi-Fi do ônibus.\n\n"
                        . 'Portal: '.url('/')."\n\nPara parar as atualizações de pagamento, responda PARAR."
                    : "Pagamento confirmado!\n\nOi {$nome}! Recebemos seu PIX de R$ {$amount}.\n\n"
                    . "Intervalo: {$start} a {$end}, com {$interval['hours_per_day']}h corridas por dia. "
                    . "Se o pagamento ocorrer nas datas contratadas, a primeira diária começa na confirmação do PIX. "
                    . "As próximas diárias, ou compras antecipadas, começam ao abrir o portal no Wi-Fi do ônibus. Dias não usados não acumulam.\n\n"
                    . 'Portal: '.url('/')."\n\nPara parar as atualizações de pagamento, responda PARAR.";
            }

            $msg = \App\Models\WhatsappMessage::create([
                'user_id' => $user->id,
                'payment_id' => $payment->id,
                'phone' => $phone,
                'message' => $message,
                'status' => 'pending',
            ]);

            $baileysUrl = env('BAILEYS_SERVER_URL', 'http://localhost:3001');
            $resp = $this->whatsappHttpClient()->post($baileysUrl . '/send', [
                'phone' => $phone,
                'message' => $message,
                'priority' => true,
                'skipCheck' => true,
            ]);

            if ($resp->successful()) {
                $msg->markAsSent($resp->json('messageId'));
                $payment->update(['whatsapp_confirmation_sent_at' => now()]);
                Log::info('📱 WhatsApp: Confirmação de pagamento enviada', ['payment_id' => $payment->id]);
            } else {
                $msg->markAsFailed($resp->body());
            }
        } catch (\Exception $e) {
            Log::error('📱 WhatsApp: Erro ao enviar confirmação', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 🏦 Ativa bypass temporário de 3 minutos para o usuário abrir o app do banco
     * Chamado APÓS o usuário copiar o código PIX (não na geração do QR)
     * Isso evita que o captive portal sheet do iOS feche antes do usuário copiar o código
     */
    public function activateTempBypass(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'payment_id' => 'required|integer|exists:payments,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Payment ID inválido'], 422);
        }

        try {
            return DB::transaction(function () use ($request) {
                // Serialize with payment confirmation: a late bypass request must
                // never overwrite the hours that have just been purchased.
                $payment = Payment::whereKey($request->payment_id)->lockForUpdate()->first();
                if (! $payment) {
                    return response()->json(['success' => false, 'message' => 'Pagamento não encontrado'], 404);
                }
                $user = User::whereKey($payment->user_id)->lockForUpdate()->first();
                if (!$user || !$user->mac_address) {
                    return response()->json(['success' => false, 'message' => 'Usuário sem MAC'], 404);
                }

                // Não rebaixar quem já está conectado/ativo
                if (in_array($user->status, ['connected', 'active']) && $user->expires_at?->isFuture()) {
                    return response()->json([
                        'success' => true,
                        'message' => 'Usuário já tem acesso',
                        'already_connected' => true,
                        'expires_in' => (int) now()->diffInSeconds($user->expires_at),
                        'expires_at' => $user->expires_at->toISOString(),
                    ]);
                }

                if ($payment->status !== 'pending') {
                    return response()->json(['success' => false, 'message' => 'Pagamento já processado. Verifique seu acesso pago.'], 409);
                }

                // 🚫 VERIFICAR BLOQUEIO ADMINISTRATIVO DE BYPASS
                // Admin pode bloquear um MAC/telefone de usar bypass por 12 horas
                $macBlocked = \Illuminate\Support\Facades\Cache::get('bypass_blocked_' . strtoupper($user->mac_address));
                $phoneBlocked = null;
                if ($user->phone) {
                    $phoneBlocked = \Illuminate\Support\Facades\Cache::get('bypass_blocked_phone_' . preg_replace('/[^\d]/', '', $user->phone));
                }

                if ($macBlocked || $phoneBlocked) {
                    $blockedInfo = $macBlocked ?: $phoneBlocked;

                    \App\Models\TempBypassLog::create([
                        'user_id' => $user->id,
                        'payment_id' => $payment->id,
                        'mac_address' => $user->mac_address,
                        'phone' => $user->phone,
                        'ip_address' => $request->ip(),
                        'bypass_number' => 0,
                        'was_denied' => true,
                        'deny_reason' => 'Bloqueado pelo administrador',
                    ]);

                    Log::warning('🚫 Bypass BLOQUEADO por admin', [
                        'user_id' => $user->id,
                        'mac_address' => $user->mac_address,
                        'phone' => $user->phone,
                        'blocked_by' => $blockedInfo['blocked_by'] ?? 'Admin',
                        'blocked_at' => $blockedInfo['blocked_at'] ?? null,
                    ]);

                    return response()->json([
                        'success' => false,
                        'message' => 'Seu acesso temporário foi suspenso pelo administrador. Realize o pagamento para continuar navegando.',
                        'blocked' => true,
                    ]);
                }

                // Re-copying an active bypass is idempotent, even on the second
                // allowance. Do not extend its expiry or consume another allowance.
                if ($user->status === 'temp_bypass' && $user->expires_at?->isFuture()) {
                    $usedBypasses = max(
                        (int) Cache::get('bypass_mac_' . strtoupper($user->mac_address), 0),
                        $user->phone ? (int) Cache::get('bypass_phone_' . $user->phone, 0) : 0,
                    );
                    return response()->json([
                        'success' => true,
                        'message' => 'Seu acesso temporário continua ativo.',
                        'already_bypassed' => true,
                        'bypasses_remaining' => max(0, 2 - $usedBypasses),
                        'expires_in' => (int) now()->diffInSeconds($user->expires_at),
                        'expires_at' => $user->expires_at->toISOString(),
                    ]);
                }

                //  ANTI-ABUSO: Máximo 2 bypasses por hora
                // Verificar por MAC (mesmo dispositivo)
                $bypassesByMac = \Illuminate\Support\Facades\Cache::get('bypass_mac_' . strtoupper($user->mac_address), 0);

                // Verificar por telefone (mesmo usuário trocando de rede 2.4/5G)
                $bypassesByPhone = 0;
                if ($user->phone) {
                    $bypassesByPhone = \Illuminate\Support\Facades\Cache::get('bypass_phone_' . $user->phone, 0);
                }

                $totalBypasses = max($bypassesByMac, $bypassesByPhone);

                if ($totalBypasses >= 2) {
                    // 📝 Registrar tentativa NEGADA
                    \App\Models\TempBypassLog::create([
                        'user_id' => $user->id,
                        'payment_id' => $payment->id,
                        'mac_address' => $user->mac_address,
                        'phone' => $user->phone,
                        'ip_address' => $request->ip(),
                        'bypass_number' => $totalBypasses + 1,
                        'was_denied' => true,
                        'deny_reason' => "Limite atingido (MAC: {$bypassesByMac}, Phone: {$bypassesByPhone})",
                    ]);

                    Log::warning('⚠️ Bypass temporário negado - limite anti-abuso (máx 2/hora)', [
                        'user_id' => $user->id,
                        'mac_address' => $user->mac_address,
                        'phone' => $user->phone,
                        'bypasses_mac' => $bypassesByMac,
                        'bypasses_phone' => $bypassesByPhone,
                    ]);
                    return response()->json([
                        'success' => false,
                        'message' => 'Limite de 2 liberações por hora atingido. Pague pelo seu plano de dados ou aguarde.',
                        'limit_reached' => true,
                    ]);
                }

                // Ativar bypass
                $expiresAt = now()->addMinutes(3);
                $user->update([
                    'status' => 'temp_bypass',
                    'expires_at' => $expiresAt,
                ]);

                // 📝 Registrar bypass APROVADO
                \App\Models\TempBypassLog::create([
                    'user_id' => $user->id,
                    'payment_id' => $payment->id,
                    'mac_address' => $user->mac_address,
                    'phone' => $user->phone,
                    'ip_address' => $request->ip(),
                    'bypass_number' => $totalBypasses + 1,
                    'expires_at' => $expiresAt,
                    'was_denied' => false,
                ]);

                // Incrementar contadores (expiram em 1 hora)
                $macKey = 'bypass_mac_' . strtoupper($user->mac_address);
                \Illuminate\Support\Facades\Cache::put($macKey, $bypassesByMac + 1, now()->addHour());

                if ($user->phone) {
                    $phoneKey = 'bypass_phone_' . $user->phone;
                    \Illuminate\Support\Facades\Cache::put($phoneKey, $bypassesByPhone + 1, now()->addHour());
                }

                // Invalidar cache para o MikroTik pegar o MAC no próximo sync (≤15s)
                Cache::forget('mikrotik_sync_lists_all');

                Log::info('🏦 BYPASS TEMPORÁRIO DE 3 MIN ATIVADO', [
                    'user_id' => $user->id,
                    'mac_address' => $user->mac_address,
                    'phone' => $user->phone,
                    'payment_id' => $payment->id,
                    'bypass_count_mac' => $bypassesByMac + 1,
                    'bypass_count_phone' => $bypassesByPhone + 1,
                    'expires_at' => now()->addMinutes(3)->toISOString(),
                ]);

                $remaining = 2 - ($totalBypasses + 1);

                return response()->json([
                    'success' => true,
                    'message' => 'Acesso temporario liberado para abrir o app do banco.',
                    'expires_in' => 180,
                    'expires_at' => $expiresAt->toISOString(),
                    'bypasses_remaining' => $remaining,
                ]);
            }, 3);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao ativar bypass temporário', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno'], 500);
        }
    }

    /**
     * Processa pagamento com cartão
     */
    public function processCard(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.05',
            'mac_address' => 'required|string',
        ]);

        try {
            DB::beginTransaction();

            // Buscar ou criar usuário
            $user = $this->findOrCreateUser($request->mac_address, $request->ip());

            // Criar registro de pagamento
            $payment = Payment::create([
                'user_id' => $user->id,
                'amount' => $request->amount,
                'payment_type' => 'card',
                'status' => 'pending',
                'transaction_id' => $this->generateTransactionId(),
            ]);

            // REMOVIDO: Simulação de cartão que aprovava automaticamente
            // Cartão deve aguardar confirmação real do gateway de pagamento
            
            DB::commit();
            
            return response()->json([
                'success' => true,
                'message' => 'Pagamento de cartão criado. Aguardando confirmação do gateway.',
                'payment_id' => $payment->id,
                'transaction_id' => $payment->transaction_id,
                'status' => 'pending',
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            Log::error('Erro no pagamento cartão: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erro interno. Tente novamente.',
            ], 500);
        }
    }

    /**
     * Processa pagamento genérico
     */
    public function process(Request $request)
    {
        $method = $request->input('method');

        switch ($method) {
            case 'pix':
                return $this->processPix($request);
            case 'card':
                return $this->processCard($request);
            default:
                return response()->json([
                    'success' => false,
                    'message' => 'Método de pagamento inválido.',
                ], 400);
        }
    }

    /**
     * Busca ou cria usuário baseado no MAC address - CORRIGIDO PARA EVITAR DUPLICATAS
     */
    private function findOrCreateUser($macAddress, $ipAddress)
    {
        $normalizedMac = HotspotIdentity::resolveRealMac($macAddress, $ipAddress);

        Log::info('🔍 BUSCAR/CRIAR USUÁRIO', [
            'mac_address' => $normalizedMac,
            'ip_address' => $ipAddress,
        ]);

        // 1. PRIORIDADE: Buscar usuário por MAC address
        $user = User::where('mac_address', $normalizedMac)->first();

        if ($user) {
            // Usuário já existe com este MAC - atualizar IP se necessário
            if ($ipAddress && $user->ip_address !== $ipAddress) {
                $user->update(['ip_address' => $ipAddress]);
            }
            Log::info('✅ Usuário encontrado por MAC', ['user_id' => $user->id, 'name' => $user->name]);

            return $user;
        }

        // 2. SEGUNDA CHANCE: Buscar usuário pendente sem MAC (qualquer IP recente)
        $pendingUser = User::whereNull('mac_address')
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinutes(10))
            ->orderBy('created_at', 'desc')
            ->first();

        // NÃO buscar por IP — múltiplos ônibus usam a mesma faixa 10.5.50.x,
        // então o IP pode pertencer a dispositivos diferentes em ônibus diferentes.
        // Busca por IP causava atribuição de pagamento ao usuário errado.

        if ($pendingUser) {
            // Atualizar usuário existente com o MAC
            $pendingUser->update([
                'mac_address' => $normalizedMac,
                'ip_address' => $ipAddress,
                'status' => 'offline',
            ]);
            Log::info('✅ Usuário pendente atualizado com MAC', [
                'user_id' => $pendingUser->id,
                'name' => $pendingUser->name,
                'mac_added' => $normalizedMac,
            ]);

            return $pendingUser;
        }

        // 4. ÚLTIMA OPÇÃO: Criar novo usuário
        $userData = [
            'mac_address' => $normalizedMac,
            'status' => 'offline',
        ];

        if ($ipAddress) {
            $userData['ip_address'] = $ipAddress;
        }

        $user = User::create($userData);

        Log::info('🆕 Novo usuário criado', ['user_id' => $user->id, 'mac_address' => $normalizedMac]);

        return $user;
    }

    /**
     * Gera ID único para transação
     * Formato: TXN_timestamp_hash (26-35 caracteres para Santander)
     */
    private function generateTransactionId()
    {
        // Gerar ID com 30 caracteres (dentro do range 26-35 exigido pelo Santander)
        // Formato: TXN_1234567890_ABCDEF123456 (30 chars)
        $timestamp = time();
        $hash = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 12));
        
        return 'TXN_' . $timestamp . '_' . $hash;
    }

    /**
     * Força report de MAC real do MikroTik antes do pagamento
     */
    private function forceMacReport(Request $request)
    {
        try {
            // Simular request para MikroTik executar script de report
            $mikrotikUrl = 'http://mikrotik.local/rest/system/script/run';
            $macAddress = $request->mac_address;

            $clientIp = HotspotIdentity::resolveClientIp($request);
            $realMac = HotspotIdentity::resolveRealMac($macAddress, $clientIp);

            Log::info('🔥 FORÇANDO REPORT DE MAC', [
                'mac_address' => $realMac,
                'ip_address' => $clientIp,
                'note' => 'Report forçado antes do pagamento',
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao forçar report MAC: '.$e->getMessage());
            // Não falhar o pagamento por causa disso
        }
    }

    /**
     * Webhook para receber confirmações de pagamento
     */
    public function webhook(Request $request)
    {
        // Implementar lógica do webhook do gateway de pagamento
        $transactionId = $request->input('transaction_id');
        $status = $request->input('status');

        $payment = Payment::where('transaction_id', $transactionId)->first();

        if ($payment && $status === 'approved') {
            $payment->update([
                'status' => 'completed',
                'paid_at' => now(),
            ]);

            // Liberar acesso do usuário
            $this->activateUserAccess($payment);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Verificar status do pagamento PIX
     */
    public function checkPixStatus(Request $request)
    {
        $request->validate([
            'payment_id' => 'required|exists:payments,id',
        ]);

        $payment = Payment::find($request->payment_id);

        // Heal interval confirmations recorded by older deployments, even when
        // their webhook has already been acknowledged and will not be retried.
        if ($payment->status === 'completed' && \App\Services\IntervalPlanService::isInterval($payment)) {
            app(\App\Services\IntervalPlanService::class)->activatePaidCheckout($payment);
        }

        $response = response()->json([
            'success' => true,
            'payment' => [
                'id' => $payment->id,
                'status' => $payment->status,
                'amount' => $payment->amount,
                'created_at' => $payment->created_at,
                'paid_at' => $payment->paid_at,
                'plan_type' => data_get($payment->payment_data, 'plan_type'),
                'interval' => data_get($payment->payment_data, 'interval'),
            ],
        ]);

        // 🍪 Cookie persistente de autenticação pós-pagamento.
        // Permite reaproveitar a compra quando o dispositivo volta com MAC randomizado
        // diferente (iOS/Android geram MAC novo por rede). O PortalController busca
        // por esse cookie e re-associa o MAC atual ao usuário pago.
        if ($payment->status === 'completed' && $payment->user_id) {
            $user = User::find($payment->user_id);
            $expiresMinutes = 60 * 24 * 7; // 7 dias
            if ($user && $user->expires_at && $user->expires_at->isFuture()) {
                $expiresMinutes = max($expiresMinutes, $user->expires_at->diffInMinutes(now()));
            }
            $response->cookie(
                'wt_user',
                (string) $payment->user_id,
                $expiresMinutes,
                '/',       // path
                null,      // domain (atual)
                true,      // secure (HTTPS)
                true,      // httpOnly
                false,
                'Lax'
            );
        }

        return $response;
    }

    /**
     * Webhook específico do Santander PIX
     * Documentação: Portal do Desenvolvedor > Gerenciamento de notificações via Webhook
     */
    public function santanderWebhook(Request $request)
    {
        try {
            Log::info('🔔 Webhook Santander recebido', [
                'timestamp' => now()->toISOString(),
                'ip' => $request->ip(),
                'method' => $request->method(),
                'headers' => $request->headers->all(),
                'body' => $request->all(),
            ]);

            // Santander pode enviar GET para validação da URL
            if ($request->isMethod('get')) {
                Log::info('✅ Validação GET do webhook Santander');
                return response()->json(['success' => true]);
            }

            $webhookData = $request->all();

            $santanderService = new SantanderPixService;
            $result = $santanderService->processWebhook($webhookData);

            if ($result['success'] && ($result['payment_confirmed'] ?? false)) {
                // Buscar pagamento pelo TXId (gateway_payment_id)
                $txid = $result['txid'] ?? null;
                
                if (!$txid) {
                    Log::warning('⚠️ Webhook sem TXId', ['result' => $result]);
                    return response()->json(['success' => true, 'message' => 'TXId não encontrado']);
                }

                $payment = Payment::where('gateway_payment_id', $txid)
                    ->orWhere('transaction_id', $txid)
                    ->first();

                if ($payment) {
                    Log::info('💰 Pagamento Santander confirmado', [
                        'payment_id' => $payment->id,
                        'txid' => $txid,
                        'e2eid' => $result['e2eid'] ?? null,
                        'amount' => $result['amount'] ?? null,
                    ]);

                    $payment->update([
                        'status' => 'completed',
                        'paid_at' => $result['paid_at'] ?? now(),
                    ]);

                    // Liberar acesso do usuário automaticamente
                    $this->activateUserAccess($payment);
                } else {
                    Log::warning('⚠️ Pagamento não encontrado para o TXId', ['txid' => $txid]);
                }
            }

            return response()->json(['success' => true]);

        } catch (\Exception $e) {
            Log::error('❌ Erro no webhook Santander: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            return response()->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Webhook específico da Woovi - MELHORADO
     */
    public function wooviWebhook(Request $request)
    {
        $startTime = microtime(true);

        Log::info('🔔 Webhook Woovi MELHORADO recebido', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        try {
            $webhookData = $request->all();

            // Processar webhook com serviço melhorado
            $wooviService = new WooviPixService;
            $result = $wooviService->processWebhook($webhookData);

            if (! ($result['success'] ?? false)) {
                Log::warning('⚠️ Webhook Woovi rejeitado', [
                    'reason' => $result['message'] ?? 'Erro desconhecido',
                    'correlation_id' => $webhookData['charge']['correlationID'] ?? null,
                    'event' => $webhookData['event'] ?? null,
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Webhook rejeitado',
                ], 400);
            }

            if (! ($result['payment_approved'] ?? false)) {
                Log::info('⏳ Webhook Woovi recebido, mas sem confirmação', [
                    'event' => $webhookData['event'] ?? null,
                    'status' => $result['status'] ?? null,
                    'correlation_id' => $result['correlation_id'] ?? null,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Pagamento ainda não confirmado na Woovi',
                    'pending' => true,
                ], 202);
            }

            Log::info('📊 Resultado do processamento Woovi', [
                'success' => $result['success'],
                'payment_approved' => $result['payment_approved'],
                'event' => $webhookData['event'] ?? null,
                'status' => $result['status'] ?? null,
                'correlation_id' => $result['correlation_id'] ?? 'N/A',
                'woovi_id' => $result['woovi_id'] ?? 'N/A',
            ]);

            if ($result['payment_approved']) {

                DB::beginTransaction();

                try {
                    // Buscar pagamento com múltiplas estratégias
                    $payment = Payment::where('pix_location', $result['correlation_id'])
                        ->orWhere('gateway_payment_id', $result['woovi_id'])
                        ->orWhere('transaction_id', $result['correlation_id'])
                        ->orWhere('gateway_payment_id', $result['correlation_id'])
                        ->first();

                    if (! $payment) {
                        Log::warning('❌ Pagamento não encontrado', [
                            'correlation_id' => $result['correlation_id'],
                            'woovi_id' => $result['woovi_id'],
                        ]);

                        DB::rollback();

                        return response()->json([
                            'success' => false,
                            'message' => 'Pagamento não encontrado',
                        ], 404);
                    }

                    Log::info('💳 Pagamento encontrado', [
                        'payment_id' => $payment->id,
                        'current_status' => $payment->status,
                        'user_mac' => $payment->user->mac_address ?? 'N/A',
                    ]);

                    // Só processar se ainda está pendente
                    if ($payment->status === 'pending') {

                        // Verificar se não é webhook duplicado (mesmo correlation_id processado recentemente)
                        $recentProcessed = Payment::where('gateway_payment_id', $result['correlation_id'])
                            ->where('status', 'completed')
                            ->where('updated_at', '>', now()->subMinutes(5))
                            ->count();

                        if ($recentProcessed > 0) {
                            DB::rollback();
                            Log::warning('⚠️ Webhook duplicado detectado', [
                                'correlation_id' => $result['correlation_id'],
                                'payment_id' => $payment->id,
                                'recent_processed_count' => $recentProcessed,
                            ]);

                            return response()->json([
                                'success' => true,
                                'message' => 'Webhook duplicado - já processado',
                                'duplicate' => true,
                            ]);
                        }

                        // Atualizar pagamento
                        $payment->update([
                            'status' => 'completed',
                            'paid_at' => $result['paid_at'] ?? now(),
                            'payment_data' => array_merge((array) $payment->payment_data, $webhookData),
                        ]);

                        // Ativar acesso do usuário
                        $this->activateUserAccess($payment);

                        DB::commit();

                        $processingTime = round((microtime(true) - $startTime) * 1000, 2);

                        Log::info('✅ Pagamento Woovi processado com SUCESSO', [
                            'payment_id' => $payment->id,
                            'user_mac' => $payment->user->mac_address,
                            'expires_at' => $payment->user->expires_at,
                            'total_processing_time' => $processingTime.'ms',
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Pagamento processado com sucesso',
                            'processing_time' => $processingTime.'ms',
                        ]);

                    } else {
                        DB::rollback();
                        Log::info('ℹ️ Pagamento já processado anteriormente', [
                            'payment_id' => $payment->id,
                            'status' => $payment->status,
                            'paid_at' => $payment->paid_at,
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Pagamento já processado anteriormente',
                            'already_processed' => true,
                        ]);
                    }

                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }
            }

            // Webhook recebido mas não processado
            return response()->json(['success' => true, 'message' => 'Webhook recebido']);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollback();
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO CRÍTICO no webhook Woovi', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'processing_time' => $processingTime.'ms',
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'processing_time' => $processingTime.'ms',
            ], 500);
        }
    }

    /**
     * Testar conexão com Santander
     */
    public function testSantanderConnection()
    {
        try {
            $santanderService = new SantanderPixService;
            $result = $santanderService->testConnection();

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao testar conexão: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Testar conexão com Woovi
     */
    public function testWooviConnection()
    {
        try {
            $wooviService = new WooviPixService;
            $result = $wooviService->testConnection();

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao testar conexão: '.$e->getMessage(),
            ], 500);
        }
    }

    /**
     * Ativa o acesso do usuário e libera no MikroTik - MELHORADO COM LIBERAÇÃO IMEDIATA
     */
    public function activateUserAccess(Payment $payment)
    {
        if (\App\Services\IntervalPlanService::isInterval($payment)) {
            // The first eligible day starts on payment, without requiring bypass
            // logs or the captive browser to return from the banking app.
            app(\App\Services\IntervalPlanService::class)->activatePaidCheckout($payment);
            Cache::forget('mikrotik_sync_lists_all');
            if ($payment->user) {
                $this->sendPaymentConfirmedWhatsapp($payment->user, $payment, (float) data_get($payment->payment_data, 'interval.hours_per_day', 12));
            }
            return;
        }
        $startTime = microtime(true);

        try {
            // 🔧 FIX: Recarregar o model do usuário para garantir que temos o MAC mais recente
            // Entre a criação do pagamento e a confirmação via webhook, o MAC pode ter sido atualizado
            $payment->user->refresh();

            Log::info('🔓 Iniciando ativação de acesso do usuário', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'mac_address' => $payment->user->mac_address,
            ]);

            // 🎯 REGISTRAR MAC NA TABELA MIKROTIK_MAC_REPORTS AUTOMATICAMENTE
            if ($payment->user->mac_address && $payment->user->ip_address) {
                try {
                    MikrotikMacReport::updateOrCreate(
                        [
                            'ip_address' => $payment->user->ip_address,
                            'mac_address' => $payment->user->mac_address,
                        ],
                        [
                            'transaction_id' => $payment->transaction_id,
                            'mikrotik_ip' => null, // Será preenchido quando MikroTik reportar
                            'reported_at' => now(),
                        ]
                    );

                    Log::info('✅ MAC registrado automaticamente na tabela mikrotik_mac_reports', [
                        'mac_address' => $payment->user->mac_address,
                        'ip_address' => $payment->user->ip_address,
                        'transaction_id' => $payment->transaction_id,
                        'reason' => 'payment_approved',
                    ]);
                } catch (\Exception $e) {
                    Log::error('❌ Erro ao registrar MAC automaticamente', [
                        'error' => $e->getMessage(),
                        'mac_address' => $payment->user->mac_address,
                        'ip_address' => $payment->user->ip_address,
                    ]);
                }
            } else {
                Log::warning('⚠️ Usuário sem MAC ou IP - não é possível registrar no mikrotik_mac_reports', [
                    'user_id' => $payment->user_id,
                    'mac_address' => $payment->user->mac_address,
                    'ip_address' => $payment->user->ip_address,
                ]);

                // 🔥 TENTAR RECUPERAR MAC DOS DADOS DO PAGAMENTO
                if (! $payment->user->mac_address && isset($payment->payment_data['mac_address'])) {
                    $recoveredMac = $payment->payment_data['mac_address'];
                    $payment->user->update(['mac_address' => $recoveredMac]);

                    Log::info('🔧 MAC recuperado dos dados do pagamento', [
                        'user_id' => $payment->user_id,
                        'recovered_mac' => $recoveredMac,
                    ]);
                }

                // 🔥 FALLBACK FINAL: recuperar MAC pelo IP via relatórios do MikroTik
                // (registrarMacs reporta MAC+IP de todos os conectados). Garante liberação
                // mesmo se o MAC não veio no fluxo de pagamento.
                if (! $payment->user->mac_address && $payment->user->ip_address) {
                    try {
                        $report = MikrotikMacReport::getLatestMacForIp($payment->user->ip_address);
                        if ($report && ! \App\Support\HotspotIdentity::isMockMac($report->mac_address)) {
                            $payment->user->update(['mac_address' => strtoupper($report->mac_address)]);
                            Log::info('🔧 MAC recuperado via relatório MikroTik (por IP)', [
                                'user_id' => $payment->user_id,
                                'ip' => $payment->user->ip_address,
                                'recovered_mac' => $report->mac_address,
                            ]);
                        }
                    } catch (\Exception $e) {
                        Log::warning('Fallback MAC por IP falhou', ['error' => $e->getMessage()]);
                    }
                }
            }

            // Criar sessão ativa
            $session = Session::create([
                'user_id' => $payment->user_id,
                'payment_id' => $payment->id,
                'started_at' => now(),
                'session_status' => 'active',
            ]);

            Log::info('✅ Sessão criada', ['session_id' => $session->id]);

            // Atualizar status do usuário com duração configurável
            // 🔧 FIX: Usar SystemSetting (admin/settings) como fonte primária
            $sessionDurationConfig = data_get($payment->payment_data, 'duration_hours', \App\Helpers\SettingsHelper::getSessionDuration());
            $sessionDurationHours = max((float) $sessionDurationConfig, 0.1);

            $expiresAt = now()->addHours($sessionDurationHours);

            // Descobrir em qual MikroTik/ônibus o usuário está
            $mikrotikId = $payment->user->last_mikrotik_id;
            if (!$mikrotikId && $payment->user->mac_address) {
                $report = MikrotikMacReport::where('mac_address', strtoupper($payment->user->mac_address))
                    ->whereNotNull('mikrotik_id')
                    ->orderBy('last_seen', 'desc')
                    ->first();
                if ($report) {
                    $mikrotikId = $report->mikrotik_id;
                }
            }

            $payment->user->update([
                'status' => 'connected',
                'connected_at' => now(),
                'expires_at' => $expiresAt,
                'last_mikrotik_id' => $mikrotikId ?: $payment->user->last_mikrotik_id,
            ]);

            Log::info('✅ Status do usuário atualizado', [
                'status' => 'connected',
                'expires_at' => $expiresAt->toISOString(),
                'duration_hours' => $sessionDurationHours,
                'mac_address' => $payment->user->mac_address,
            ]);

            // 🚀 LIBERAÇÃO NO MIKROTIK
            // O servidor está na nuvem e NÃO consegue conectar diretamente ao MikroTik (10.10.10.1)
            // A liberação acontece via syncPagos que roda a cada 15 segundos no MikroTik
            // O status já está 'connected' no banco, o MikroTik vai buscar na próxima sync

            // 🔥 Invalidar cache da lista global de MACs para que a PRÓXIMA chamada de
            // checkPaidUsersLite (por qualquer um dos 8 MikroTiks) reconsulte o banco
            // e pegue este usuário imediatamente. Sem isso, até 8s de cache TTL
            // atrasavam a liberação. Com o forget, a janela máxima é o próprio intervalo
            // de sync (até 15s) — o melhor possível sem acesso direto ao MikroTik.
            Cache::forget('mikrotik_sync_lists_all');

            Log::info('🚀 Pagamento ativado - MAC será liberado no próximo sync (até 15s)', [
                'user_id' => $payment->user_id,
                'mac_address' => $payment->user->mac_address,
                'expires_at' => $expiresAt->toISOString(),
            ]);

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('🎉 Acesso do usuário ativado com SUCESSO', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'mac_address' => $payment->user->mac_address,
                'session_id' => $session->id,
                'expires_at' => $expiresAt->toISOString(),
                'processing_time' => $processingTime.'ms',
            ]);

            // 📱 Notificar usuário via WhatsApp que o pagamento foi aprovado
            $this->sendPaymentConfirmedWhatsapp($payment->user, $payment, $sessionDurationHours);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO CRÍTICO ao ativar acesso do usuário', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'mac_address' => $payment->user->mac_address ?? 'N/A',
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'processing_time' => $processingTime.'ms',
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e; // Re-throw para não perder o erro
        }
    }

    /**
     * Webhook Woovi - Cobrança Criada
     */
    public function wooviWebhookCreated(Request $request)
    {
        $startTime = microtime(true);

        Log::info('🆕 Webhook Woovi CRIADA recebido', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'body' => $request->all(),
        ]);

        try {
            $webhookData = $request->all();

            // Processar webhook de criação
            $wooviService = new WooviPixService;
            $result = $wooviService->processWebhook($webhookData, 'OPENPIX:CHARGE_CREATED');

            Log::info('📊 Resultado webhook CRIADA', [
                'success' => $result['success'],
                'correlation_id' => $result['correlation_id'] ?? 'N/A',
            ]);

            // Para webhook de criação, apenas logar
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('✅ Webhook CRIADA processado', [
                'processing_time' => $processingTime.'ms',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook de criação processado',
                'processing_time' => $processingTime.'ms',
            ]);

        } catch (\Exception $e) {
            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO webhook CRIADA', [
                'error' => $e->getMessage(),
                'processing_time' => $processingTime.'ms',
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno',
                'processing_time' => $processingTime.'ms',
            ], 500);
        }
    }

    /**
     * Webhook Woovi - Cobrança Expirada
     */
    public function wooviWebhookExpired(Request $request)
    {
        $startTime = microtime(true);

        Log::info('⏰ Webhook Woovi EXPIRADA recebido', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'body' => $request->all(),
        ]);

        try {
            $webhookData = $request->all();

            // Processar webhook de expiração
            $wooviService = new WooviPixService;
            $result = $wooviService->processWebhook($webhookData, 'OPENPIX:CHARGE_EXPIRED');

            if ($result['success'] && isset($result['correlation_id'])) {

                DB::beginTransaction();

                try {
                    // Buscar pagamento expirado
                    $payment = Payment::where('pix_location', $result['correlation_id'])
                        ->orWhere('gateway_payment_id', $result['correlation_id'])
                        ->orWhere('transaction_id', $result['correlation_id'])
                        ->first();

                    if ($payment && $payment->status === 'pending') {

                        // Marcar como expirado
                        $payment->update([
                            'status' => 'cancelled',
                            'payment_data' => array_merge((array) $payment->payment_data, $webhookData),
                        ]);

                        Log::info('⏰ Pagamento marcado como expirado', [
                            'payment_id' => $payment->id,
                            'mac_address' => $payment->user->mac_address ?? 'N/A',
                        ]);
                    }

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('✅ Webhook EXPIRADA processado', [
                'processing_time' => $processingTime.'ms',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook de expiração processado',
                'processing_time' => $processingTime.'ms',
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollback();
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO webhook EXPIRADA', [
                'error' => $e->getMessage(),
                'processing_time' => $processingTime.'ms',
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno',
                'processing_time' => $processingTime.'ms',
            ], 500);
        }
    }

    /**
     * Webhook Woovi - Transação Recebida
     */
    public function wooviWebhookTransaction(Request $request)
    {
        $startTime = microtime(true);

        Log::info('💰 Webhook Woovi TRANSAÇÃO RECEBIDA', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'body' => $request->all(),
        ]);

        try {
            $webhookData = $request->all();

            // Processar webhook de transação recebida
            $wooviService = new WooviPixService;
            $result = $wooviService->processWebhook($webhookData, 'OPENPIX:TRANSACTION_RECEIVED');

            if ($result['success'] && isset($result['correlation_id'])) {

                DB::beginTransaction();

                try {
                    // Buscar pagamento pela transação
                    $payment = Payment::where('pix_location', $result['correlation_id'])
                        ->orWhere('gateway_payment_id', $result['correlation_id'])
                        ->orWhere('transaction_id', $result['correlation_id'])
                        ->first();

                    if ($payment && $payment->status === 'pending') {

                        // Marcar como pago (transação recebida = pagamento confirmado)
                        $payment->update([
                            'status' => 'completed',
                            'paid_at' => now(),
                            'payment_data' => array_merge((array) $payment->payment_data, $webhookData),
                        ]);

                        // Ativar acesso do usuário
                        $this->activateUserAccess($payment);

                        Log::info('💰 Transação processada - usuário liberado', [
                            'payment_id' => $payment->id,
                            'mac_address' => $payment->user->mac_address ?? 'N/A',
                        ]);
                    }

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('✅ Webhook TRANSAÇÃO processado', [
                'processing_time' => $processingTime.'ms',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook de transação processado',
                'processing_time' => $processingTime.'ms',
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollback();
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO webhook TRANSAÇÃO', [
                'error' => $e->getMessage(),
                'processing_time' => $processingTime.'ms',
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno',
                'processing_time' => $processingTime.'ms',
            ], 500);
        }
    }

    /**
     * Webhook Woovi - Pagamento com Pessoa Diferente
     */
    public function wooviWebhookDifferentPayer(Request $request)
    {
        $startTime = microtime(true);

        Log::info('👤 Webhook Woovi PAGADOR DIFERENTE recebido', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'body' => $request->all(),
        ]);

        try {
            $webhookData = $request->all();

            // Processar webhook de pagamento com pessoa diferente
            $wooviService = new WooviPixService;
            $result = $wooviService->processWebhook($webhookData, 'OPENPIX:CHARGE_COMPLETED_NOT_SAME_CUSTOMER_PAYER');

            if ($result['success'] && isset($result['correlation_id'])) {

                DB::beginTransaction();

                try {
                    // Buscar pagamento
                    $payment = Payment::where('pix_location', $result['correlation_id'])
                        ->orWhere('gateway_payment_id', $result['correlation_id'])
                        ->orWhere('transaction_id', $result['correlation_id'])
                        ->first();

                    if ($payment && $payment->status === 'pending') {

                        // Marcar como pago (mesmo com pagador diferente, é válido)
                        $payment->update([
                            'status' => 'completed',
                            'paid_at' => now(),
                            'payment_data' => array_merge((array) $payment->payment_data, $webhookData, [
                                'different_payer' => true,
                                'note' => 'Pagamento feito por pessoa diferente do solicitante',
                            ]),
                        ]);

                        // Ativar acesso do usuário normalmente
                        $this->activateUserAccess($payment);

                        Log::info('👤 Pagamento com pagador diferente processado', [
                            'payment_id' => $payment->id,
                            'mac_address' => $payment->user->mac_address ?? 'N/A',
                            'note' => 'Usuário liberado mesmo com pagador diferente',
                        ]);
                    }

                    DB::commit();

                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('✅ Webhook PAGADOR DIFERENTE processado', [
                'processing_time' => $processingTime.'ms',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Webhook pagador diferente processado',
                'processing_time' => $processingTime.'ms',
            ]);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollback();
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO webhook PAGADOR DIFERENTE', [
                'error' => $e->getMessage(),
                'processing_time' => $processingTime.'ms',
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno',
                'processing_time' => $processingTime.'ms',
            ], 500);
        }
    }

    /**
     * Webhook PagBank
     */
    public function pagbankWebhook(Request $request)
    {
        $startTime = microtime(true);

        Log::info('🏦 Webhook PagBank recebido', [
            'timestamp' => now()->toISOString(),
            'ip' => $request->ip(),
            'headers' => $request->headers->all(),
            'body' => $request->all(),
        ]);

        try {
            $webhookData = $request->all();

            // Processar webhook PagBank
            $pagbankService = new \App\Services\PagBankPixService;
            $result = $pagbankService->processWebhook($webhookData);

            if (! ($result['success'] ?? false)) {
                Log::warning('⚠️ Webhook PagBank rejeitado', [
                    'reason' => $result['message'] ?? 'Erro desconhecido',
                ]);

                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? 'Webhook rejeitado',
                ], 400);
            }

            if (! ($result['payment_approved'] ?? false)) {
                Log::info('⏳ Webhook PagBank sem aprovação', [
                    'status' => $result['status'] ?? null,
                    'reference_id' => $result['reference_id'] ?? null,
                    'message' => $result['message'] ?? null,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => $result['message'] ?? 'Pagamento ainda não confirmado',
                    'status' => $result['status'] ?? null,
                ], 202);
            }

            Log::info('📊 Resultado do processamento PagBank', [
                'success' => $result['success'],
                'payment_approved' => $result['payment_approved'],
                'reference_id' => $result['reference_id'] ?? 'N/A',
                'order_id' => $result['order_id'] ?? 'N/A',
            ]);

            if ($result['payment_approved']) {

                DB::beginTransaction();

                try {
                    // Buscar pagamento
                    $payment = Payment::where('transaction_id', $result['reference_id'])
                        ->orWhere('gateway_payment_id', $result['order_id'])
                        ->orWhere('pix_location', $result['reference_id'])
                        ->first();

                    if (! $payment) {
                        Log::warning('❌ Pagamento PagBank não encontrado', [
                            'reference_id' => $result['reference_id'],
                            'order_id' => $result['order_id'],
                        ]);

                        DB::rollback();

                        return response()->json([
                            'success' => false,
                            'message' => 'Pagamento não encontrado',
                        ], 404);
                    }

                    Log::info('💳 Pagamento PagBank encontrado', [
                        'payment_id' => $payment->id,
                        'current_status' => $payment->status,
                        'user_mac' => $payment->user->mac_address ?? 'N/A',
                    ]);

                    // Só processar se ainda está pendente
                    if ($payment->status === 'pending') {

                        // Atualizar pagamento
                        $payment->update([
                            'status' => 'completed',
                            'paid_at' => $result['paid_at'] ?? now(),
                            'payment_data' => array_merge((array) $payment->payment_data, $webhookData),
                        ]);

                        // Ativar acesso do usuário
                        $this->activateUserAccess($payment);

                        DB::commit();

                        $processingTime = round((microtime(true) - $startTime) * 1000, 2);

                        Log::info('✅ Pagamento PagBank processado com SUCESSO', [
                            'payment_id' => $payment->id,
                            'user_mac' => $payment->user->mac_address,
                            'expires_at' => $payment->user->expires_at,
                            'total_processing_time' => $processingTime.'ms',
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Pagamento processado com sucesso',
                            'processing_time' => $processingTime.'ms',
                        ]);

                    } else {
                        DB::rollback();
                        Log::info('ℹ️ Pagamento PagBank já processado anteriormente', [
                            'payment_id' => $payment->id,
                            'status' => $payment->status,
                            'paid_at' => $payment->paid_at,
                        ]);

                        return response()->json([
                            'success' => true,
                            'message' => 'Pagamento já processado anteriormente',
                            'already_processed' => true,
                        ]);
                    }

                } catch (\Exception $e) {
                    DB::rollback();
                    throw $e;
                }
            }

            // Webhook recebido mas não processado
            return response()->json(['success' => true, 'message' => 'Webhook recebido']);

        } catch (\Exception $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollback();
            }

            $processingTime = round((microtime(true) - $startTime) * 1000, 2);

            Log::error('❌ ERRO CRÍTICO no webhook PagBank', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'processing_time' => $processingTime.'ms',
                'request_data' => $request->all(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erro interno do servidor',
                'processing_time' => $processingTime.'ms',
            ], 500);
        }
    }

    /**
     * Testar conexão com PagBank
     */
    public function testPagBankConnection()
    {
        try {
            $pagbankService = new \App\Services\PagBankPixService;
            $result = $pagbankService->testConnection();

            return response()->json($result);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Exportar logs do PagBank para validação
     * Endpoint: GET /api/payment/export-pagbank-logs
     */
    public function exportPagBankLogs()
    {
        try {
            $logPath = storage_path('logs/pagbank.log');

            if (!file_exists($logPath)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum log encontrado. Execute transações primeiro.',
                    'log_path' => $logPath,
                ], 404);
            }

            // Ler arquivo de log
            $logContent = file_get_contents($logPath);
            $lines = explode("\n", $logContent);

            $transactions = [];
            $webhooks = [];
            $currentContext = null;

            foreach ($lines as $line) {
                if (empty(trim($line))) {
                    continue;
                }

                // Parsear JSON do log
                if (preg_match('/\{.*\}/', $line, $matches)) {
                    $jsonStr = $matches[0];
                    $data = json_decode($jsonStr, true);
                    
                    if ($data && isset($data['message'])) {
                        $message = $data['message'];
                        
                        if (strpos($message, '=== REQUEST: Criar Pedido PIX ===') !== false) {
                            $currentContext = [
                                'type' => 'create_order',
                                'timestamp' => $data['context']['timestamp'] ?? date('c'),
                                'endpoint' => $data['context']['endpoint'] ?? '',
                                'method' => $data['context']['method'] ?? 'POST',
                            ];
                        } elseif (strpos($message, 'REQUEST PAYLOAD:') !== false) {
                            if ($currentContext && $currentContext['type'] === 'create_order') {
                                $currentContext['request'] = $data['context']['payload'] ?? [];
                            }
                        } elseif (strpos($message, 'RESPONSE:') !== false) {
                            if ($currentContext && $currentContext['type'] === 'create_order') {
                                $currentContext['response'] = [
                                    'status' => $data['context']['status'] ?? 0,
                                    'body' => $data['context']['body'] ?? [],
                                ];
                                $transactions[] = $currentContext;
                                $currentContext = null;
                            }
                        } elseif (strpos($message, '=== WEBHOOK RECEBIDO ===') !== false) {
                            $webhooks[] = [
                                'timestamp' => $data['context']['timestamp'] ?? date('c'),
                                'data' => $data['context']['webhook_data'] ?? [],
                            ];
                        }
                    }
                }
            }

            $exportData = [
                'export_info' => [
                    'generated_at' => now()->toISOString(),
                    'system' => 'WiFi Tocantins',
                    'purpose' => 'Validação de integração PagBank',
                    'environment' => config('wifi.payment_gateways.pix.environment', 'sandbox'),
                ],
                'statistics' => [
                    'total_transactions' => count($transactions),
                    'total_webhooks' => count($webhooks),
                ],
                'transactions' => $transactions,
                'webhooks' => $webhooks,
            ];

            // Salvar arquivo JSON
            $outputPath = storage_path('logs/pagbank_validation_export.json');
            file_put_contents($outputPath, json_encode($exportData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return response()->json([
                'success' => true,
                'message' => 'Logs exportados com sucesso',
                'file_path' => $outputPath,
                'statistics' => $exportData['statistics'],
                'download_url' => url('/storage/logs/pagbank_validation_export.json'),
            ]);

        } catch (\Exception $e) {
            Log::error('Erro ao exportar logs PagBank: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Erro ao exportar logs: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * 🔧 Reativar acesso de usuário que pagou mas não tem internet
     * Endpoint público - usuário chama pelo portal quando pagou mas não conectou
     */
    public function reactivateAccess(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'phone' => 'required|string|min:10|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Telefone inválido'], 422);
        }

        try {
            $cleanPhone = preg_replace('/[^\d]/', '', $request->phone);
            $clientIp = HotspotIdentity::resolveClientIp($request);
            $currentMac = HotspotIdentity::resolveRealMac($request->input('mac_address'), $clientIp);

            // 🛡️ PROTEÇÃO 1: Rate limit por IP — máximo 5 tentativas por hora
            $ipKey = 'recovery_attempts_ip_' . md5($clientIp ?: $request->ip());
            $attempts = (int) Cache::get($ipKey, 0);
            if ($attempts >= 5) {
                Log::warning('⚠️ Recovery: rate limit por IP atingido', [
                    'ip' => $clientIp,
                    'attempts' => $attempts,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Muitas tentativas. Aguarde 1 hora ou faça um novo pagamento.',
                ], 429);
            }
            Cache::put($ipKey, $attempts + 1, now()->addHour());

            // Buscar usuário pelo telefone (múltiplas estratégias)
            $user = User::where('phone', $cleanPhone)
                ->orderBy('updated_at', 'desc')
                ->first();

            if (!$user) {
                $user = User::where('phone', '55' . $cleanPhone)
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            if (!$user) {
                $user = User::where('phone', 'LIKE', '%' . substr($cleanPhone, -9))
                    ->orderBy('updated_at', 'desc')
                    ->first();
            }

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum cadastro encontrado com este telefone. Verifique o número ou faça um novo pagamento.',
                ], 404);
            }

            // 🛡️ PROTEÇÃO 2: Rate limit por TELEFONE — máximo 3 mudanças de MAC por dia
            // Evita que alguém com o telefone de outro fique trocando MAC sem parar
            $phoneKey = 'recovery_mac_changes_' . preg_replace('/[^\d]/', '', $user->phone);
            $macChanges = (int) Cache::get($phoneKey, 0);

            // 🛡️ PROTEÇÃO 3: Verificar se MAC atual já é o do usuário (não conta como mudança)
            $isSameMac = $currentMac && strtoupper(str_replace('-', ':', $currentMac)) === strtoupper(str_replace('-', ':', $user->mac_address ?? ''));

            // Buscar pagamento recente completado
            $payment = Payment::where('user_id', $user->id)
                ->where('status', 'completed')
                ->where(function ($query) {
                    $query->where('paid_at', '>', now()->subHours(168))
                        ->orWhere(function ($interval) {
                            $interval->where('payment_data->plan_type', 'interval')
                                ->where('payment_data->interval->end', '>=', now()->subDay()->toDateString());
                        });
                })
                ->orderBy('paid_at', 'desc')
                ->first();

            if (!$payment) {
                return response()->json([
                    'success' => false,
                    'message' => 'Nenhum pagamento ativo encontrado. Seu acesso pode ter expirado. Faça um novo pagamento.',
                    'needs_payment' => true,
                ], 404);
            }

            // 🛡️ PROTEÇÃO 4: Se MAC novo é diferente E já mudou 3 vezes hoje, bloquear
            $shouldUpdateMac = $currentMac && !$isSameMac && HotspotIdentity::shouldReplaceMac($user->mac_address, $currentMac);
            
            if ($shouldUpdateMac && $macChanges >= 3) {
                Log::warning('⚠️ Recovery: limite de mudanças de MAC atingido', [
                    'user_id' => $user->id,
                    'phone' => $cleanPhone,
                    'mac_changes_today' => $macChanges,
                ]);
                return response()->json([
                    'success' => false,
                    'message' => 'Limite de tentativas excedido para este telefone hoje. Procure o motorista ou tente amanhã.',
                ], 429);
            }

            // 🛡️ PROTEÇÃO 5: Verificar tempo desde última troca
            // Se trocou há menos de 30 segundos, suspeitar de uso indevido
            $lastChangeKey = 'recovery_last_mac_change_' . $user->id;
            $lastChange = Cache::get($lastChangeKey);
            if ($shouldUpdateMac && $lastChange && now()->diffInSeconds($lastChange) < 30) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aguarde 30 segundos antes de tentar de novo.',
                ], 429);
            }

            // Atualizar MAC se diferente (só se passou pelas proteções)
            if ($shouldUpdateMac) {
                User::where('mac_address', $currentMac)
                    ->where('id', '!=', $user->id)
                    ->update(['mac_address' => null]);

                HotspotIdentity::markOrphanedMac($user->mac_address);

                $oldMac = $user->mac_address;
                $user->update([
                    'mac_address' => $currentMac,
                    'ip_address' => $clientIp,
                ]);

                // Incrementar contador de mudanças do dia
                Cache::put($phoneKey, $macChanges + 1, now()->endOfDay());
                Cache::put($lastChangeKey, now(), now()->addMinutes(5));

                Log::info('🔄 MAC atualizado via recuperação por telefone', [
                    'user_id' => $user->id,
                    'phone' => $cleanPhone,
                    'old_mac' => $oldMac,
                    'new_mac' => $currentMac,
                    'mac_changes_today' => $macChanges + 1,
                    'ip' => $clientIp,
                ]);

                // 📱 Notificar o DONO REAL da conta sobre a alteração
                $this->notifyMacChangeToOwner($user, $oldMac, $currentMac, $clientIp);
            }

            // Se status já está connected e ainda não expirou, só confirmar e re-sync
            if (in_array($user->status, ['connected', 'active']) && $user->expires_at && $user->expires_at > now()) {
                Cache::forget('mikrotik_sync_lists_all');
                
                return response()->json([
                    'success' => true,
                    'message' => 'Seu acesso já está ativo! Aguarde até 30 segundos para liberar. Tente desconectar e reconectar o WiFi.',
                    'already_active' => true,
                    'expires_at' => $user->expires_at->toISOString(),
                    'mac_address' => $user->mac_address,
                ]);
            }

            // Reativar acesso
            if (\App\Services\IntervalPlanService::isInterval($payment)) {
                $access = $currentMac && $currentMac === $user->mac_address
                    ? app(IntervalAccessController::class)->connect(
                        $request->duplicate(null, ['mac_address' => $currentMac, 'ip_address' => $clientIp]),
                        app(\App\Services\IntervalPlanService::class)
                    )->getData(true)
                    : app(\App\Services\IntervalPlanService::class)->access($user);
                return response()->json([
                    'success' => $access['state'] === 'active',
                    'message' => $access['message'],
                    'interval_access' => $access,
                    'expires_at' => $access['expires_at'],
                    'mac_address' => $user->mac_address,
                ]);
            }
            $sessionDuration = max((float) data_get($payment->payment_data, 'duration_hours', \App\Helpers\SettingsHelper::getSessionDuration()), 0.1);
            $paidAt = $payment->paid_at ? \Carbon\Carbon::parse($payment->paid_at) : now();
            $expiresAt = $paidAt->copy()->addHours($sessionDuration);

            if ($expiresAt <= now()) {
                return response()->json([
                    'success' => false,
                    'message' => 'O tempo do seu pagamento já expirou. Faça um novo pagamento para continuar navegando.',
                    'needs_payment' => true,
                ], 404);
            }

            $user->update([
                'status' => 'connected',
                'connected_at' => now(),
                'expires_at' => $expiresAt,
            ]);
            
            Cache::forget('mikrotik_sync_lists_all');

            Log::info('🔄 ACESSO REATIVADO VIA PORTAL', [
                'user_id' => $user->id,
                'mac_address' => $user->mac_address,
                'phone' => $cleanPhone,
                'payment_id' => $payment->id,
                'expires_at' => $expiresAt->toISOString(),
                'ip' => $clientIp,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Acesso reativado! Sua internet será liberada em até 30 segundos. Se não funcionar, desconecte e reconecte o WiFi.',
                'expires_at' => $expiresAt->toISOString(),
                'mac_address' => $user->mac_address,
            ]);

        } catch (\Exception $e) {
            Log::error('❌ Erro ao reativar acesso', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'message' => 'Erro interno. Tente novamente.'], 500);
        }
    }

    /**
     * Notifica o dono real da conta quando o MAC dele é alterado por outro dispositivo.
     * Permite que ele saiba se outra pessoa usou o telefone dele indevidamente.
     */
    private function notifyMacChangeToOwner(User $user, ?string $oldMac, string $newMac, ?string $ip): void
    {
        try {
            if (!$user->phone || strlen($user->phone) < 10) return;
            if (!\App\Models\WhatsappSetting::isConnected()) return;

            // Não notificar se o MAC antigo era nulo/mock (primeira associação)
            // 02:FA:CE = prefixo exclusivo de mock gerado internamente (MACs 02: reais são válidos)
            if (!$oldMac || str_starts_with(strtoupper($oldMac), '02:FA:CE') || str_starts_with(strtoupper($oldMac), '00:00:00')) {
                return;
            }

            $phone = \App\Models\WhatsappMessage::formatPhone($user->phone);
            $nome = $user->name ? trim(explode(' ', $user->name)[0]) : 'Cliente';
            $hora = now()->format('H:i');

            $message = "🔔 *Aviso de segurança*\n\n"
                     . "Oi {$nome}! Seu acesso ao WiFi Tocantins foi *reassociado a outro dispositivo* às {$hora}.\n\n"
                     . "Se foi você (trocou de celular ou reiniciou o aparelho), tudo certo, ignore essa mensagem. ✅\n\n"
                     . "❌ *Se NÃO foi você*, alguém pode ter usado seu telefone indevidamente. Responda *NÃO FUI EU* aqui que vamos verificar.\n\n"
                     . "Tocantins Transporte WiFi";

            $msg = \App\Models\WhatsappMessage::create([
                'user_id' => $user->id,
                'phone' => $phone,
                'message' => $message,
                'status' => 'pending',
            ]);

            $baileysUrl = env('BAILEYS_SERVER_URL', 'http://localhost:3001');
            $resp = $this->whatsappHttpClient()->post($baileysUrl . '/send', [
                'phone' => $phone,
                'message' => $message,
            ]);

            if ($resp->successful()) {
                $msg->markAsSent($resp->json('messageId'));
            } else {
                $msg->markAsFailed($resp->body());
            }

            Log::info('🔔 Notificação de mudança de MAC enviada ao dono', [
                'user_id' => $user->id,
                'phone' => $phone,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Erro ao notificar mudança de MAC', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Tenta atualizar o MAC do usuário usando mikrotik_mac_reports
     */
    private function tryUpdateMacFromReports(User $user, Request $request): bool
    {
        try {
            $clientIp = HotspotIdentity::resolveClientIp($request);

            if (!$clientIp) return false;

            // Filtrar por mikrotik_id do usuário para evitar colisão de IP
            // (múltiplos ônibus usam a mesma faixa 10.5.50.x)
            $report = MikrotikMacReport::getLatestMacForIp($clientIp, $user->last_mikrotik_id);

            // Se não encontrou com filtro de mikrotik, tenta sem filtro (fallback)
            if (!$report && $user->last_mikrotik_id) {
                $report = MikrotikMacReport::getLatestMacForIp($clientIp);
            }

            if ($report && $report->mac_address) {
                $newMac = strtoupper($report->mac_address);

                if ($newMac !== $user->mac_address && !HotspotIdentity::isMockMac($newMac)) {
                    Log::info('🔧 MAC atualizado via mikrotik_mac_reports', [
                        'user_id' => $user->id,
                        'old_mac' => $user->mac_address,
                        'new_mac' => $newMac,
                        'ip' => $clientIp,
                    ]);

                    $user->update([
                        'mac_address' => $newMac,
                        'ip_address' => $clientIp,
                    ]);

                    return true;
                }
            }

            // Atualizar IP se mudou
            if ($clientIp && $user->ip_address !== $clientIp) {
                $user->update(['ip_address' => $clientIp]);
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
