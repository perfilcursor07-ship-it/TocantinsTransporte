<?php

namespace App\Services;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\ConnectivityProbe;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatAIService
{
    /**
     * IA tenta resolver antes de mandar pro humano.
     * Ações: reply, request_probe, request_receipt, request_mac, escalate.
     */

    public const ACTIONS = ['reply', 'request_probe', 'request_receipt', 'request_mac', 'escalate'];

    /** @var array<int, array<string, mixed>> */
    private array $paymentContextCache = [];

    /** Afirmação de que pagou. */
    private const RE_PAID_CLAIM = '/\b(paguei|fiz\s*o\s*pix|pagamento\s*feito|enviei\s*o\s*comprovante|acabei\s*de\s*pagar)\b/u';

    /** Negação explícita de pagamento — precisa vencer o RE_PAID_CLAIM. */
    private const RE_PAY_DENIAL = '/((ainda\s+)?n[aã]o\s+(cheguei\s+a\s+)?paguei|(ainda\s+)?nao\s+(cheguei\s+a\s+)?paguei|n[aã]o\s+fiz\s+o\s+pix|nao\s+fiz\s+o\s+pix|n[aã]o\s+paguei\s+nada|nunca\s+paguei|n[aã]o\s+cheguei\s+a\s+pagar|sem\s+ter\s+pago|n[aã]o\s+tenho\s+pagamento)/u';

    /** Quer pagar agora (nunca pagou) — deve receber passo a passo, nunca pedido de comprovante. */
    private const RE_WANTS_TO_PAY = '/(quero\s+pagar|queria\s+pagar|vou\s+pagar|preciso\s+pagar|gostaria\s+de\s+pagar|como\s+(que\s+)?(eu\s+)?pago|como\s+(eu\s+)?fa[cç]o\s+p(ra|ara)\s+pagar|onde\s+(eu\s+)?pago|quero\s+comprar|quero\s+contratar|me\s+ensina\s+a\s+pagar|como\s+pagar)/u';

    public function isEnabled(): bool
    {
        return (bool) config('services.together.enabled')
            && (bool) config('services.together.api_key');
    }

    /**
     * Se admin já entrou na conversa, IA sai de cena.
     * Também respeita flag ai_disabled no metadata da conversa (se houver).
     * E limita número de turnos automáticos pra não ficar em loop.
     */
    public function shouldRespond(ChatConversation $conv): bool
    {
        if (!$this->isEnabled()) {
            Log::info('🤖 IA desabilitada (flag ou api_key ausente)', [
                'enabled' => config('services.together.enabled'),
                'has_key' => (bool) config('services.together.api_key'),
            ]);
            return false;
        }
        if ($conv->status === 'closed') {
            Log::info('🤖 Conversa fechada, IA não responde', ['conv' => $conv->id]);
            return false;
        }

        $humanAdminReplied = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->whereNotNull('admin_id')
            ->exists();

        if ($humanAdminReplied) {
            Log::info('🤖 Humano já entrou na conversa, IA cede controle', ['conv' => $conv->id]);
            return false;
        }

        $aiTurns = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->whereNull('admin_id')
            ->count();

        if ($aiTurns >= (int) config('services.together.max_turns', 10)) {
            Log::info('🤖 Limite de turnos atingido', ['conv' => $conv->id, 'turns' => $aiTurns]);
            return false;
        }

        Log::info('🤖 IA vai responder', ['conv' => $conv->id, 'ai_turns_so_far' => $aiTurns]);
        return true;
    }

    /**
     * Processa último turno do visitante e executa a ação escolhida.
     * Retorna a ChatMessage criada (ou null em falha total).
     */
    public function respond(ChatConversation $conv): ?ChatMessage
    {
        try {
            if ($this->visitorTypedMacAfterRequest($conv)) {
                return $this->handleCollectedMac($conv, self::extractMacAddress($this->lastVisitorMessage($conv)));
            }

            // Já mandou o passo a passo do DNS privado: resolveu → encerra; não resolveu → humano.
            if ($this->alreadySentPrivateDnsSteps($conv)) {
                if ($this->visitorReportsResolved($conv)) {
                    return $this->actionReply($conv, 'Que bom que voltou a funcionar! Seu acesso segue liberado. Tenha uma ótima viagem!');
                }
                if ($this->visitorSaysStepsFailed($conv)) {
                    return $this->actionEscalate(
                        $conv,
                        'Poxa, desculpa que não resolveu por aqui. Vou passar pro meu colega verificar direto no roteador do ônibus. Aguarda só um minutinho!',
                        'MAC confere com o sistema, mas o ajuste do DNS privado não resolveu'
                    );
                }
            }

            // Casos de pagamento precisam usar o estado real do banco antes de consultar o modelo.
            // Isso evita instruções contraditórias e pedidos de MAC para quem ainda não pagou.
            $paymentDecision = $this->paymentFirstDecision($conv);
            if ($paymentDecision) {
                return $this->executeAction($conv, $paymentDecision);
            }

            // Pagou e está no prazo: o primeiro passo é a recuperação por telefone, que
            // reconhece o aparelho atual (inclusive com MAC novo) e religa sozinha.
            // Só depois, se não resolver, entra a investigação de aparelho/MAC.
            if ($this->shouldOfferRecovery($conv)) {
                return $this->executeAction($conv, [
                    'action' => 'request_recovery',
                    'message' => $this->recoveryOfferMessage($conv),
                ]);
            }

            // Respondeu iOS/iPhone/Android → pede o MAC na hora (não escala, não chama a API)
            if ($this->shouldRequestMacAfterDeviceAnswer($conv)) {
                $device = $this->visitorDeviceFromHistory($conv) ?? 'both';
                Log::info('🤖 Visitante informou o aparelho — pedindo MAC', [
                    'conversation_id' => $conv->id,
                    'device' => $device,
                ]);
                return $this->executeAction($conv, [
                    'action' => 'request_mac',
                    'message' => $this->macInstructionsMessage($device),
                ]);
            }

            $messages = $this->buildMessages($conv);
            $decision = $this->callApi($messages);

            if (!$decision || !in_array($decision['action'] ?? null, self::ACTIONS, true)) {
                $decision = $this->fallbackDecision($conv);
            }

            $decision = $this->guardDeviceAnswerRequestMac($conv, $decision);
            $decision = $this->guardWantsToPayNeverAskReceipt($conv, $decision);
            $decision = $this->guardPaidWithoutReceipt($conv, $decision);
            $decision = $this->guardPaymentHelpWithoutEscalate($conv, $decision);
            $decision = $this->guardConfusionWithoutEscalate($conv, $decision);
            $decision = $this->guardAccessIssueAskMac($conv, $decision);
            $decision = $this->guardNoRepeatPaymentSteps($conv, $decision);

            return $this->executeAction($conv, $decision);
        } catch (\Throwable $e) {
            Log::warning('🤖 Falha na IA do chat', [
                'conversation_id' => $conv->id,
                'error' => $e->getMessage(),
            ]);

            return $this->executeAction($conv, $this->fallbackDecision($conv, 'Erro na chamada da IA'));
        }
    }

    // ---------- Contexto ----------

    /**
     * Resolve primeiro os casos em que o banco já dá uma resposta objetiva.
     * O modelo continua cuidando da conversa técnica quando existe acesso/pagamento válido.
     */
    private function paymentFirstDecision(ChatConversation $conv): ?array
    {
        if ($this->visitorAskedForHuman($conv)) {
            return null;
        }

        $context = $this->paymentContext($conv);
        if ($context['has_valid_payment']) {
            return null;
        }

        if ($this->visitorReportsResolved($conv)) {
            return [
                'action' => 'reply',
                'message' => 'Que bom que deu certo! Seu acesso já deve estar liberado. Tenha uma ótima viagem!',
            ];
        }

        if ($this->visitorClaimsPaid($conv)) {
            if ($this->alreadyUploadedReceipt($conv)) {
                return null;
            }

            return [
                'action' => 'request_receipt',
                'message' => $this->paymentNotFoundAfterClaimMessage($conv),
            ];
        }

        $last = mb_strtolower(trim((string) $this->lastVisitorMessage($conv)));
        $isDeviceAnswer = $this->visitorJustAnsweredDevice($conv);
        $isShortConfirmation = (bool) preg_match('/^(sim|s|ok|certo|beleza|pode|quero|manda|isso)$/u', $last);
        $isPaymentOrAccessIssue = (bool) preg_match(
            '/\b(internet|wifi|wi-fi|acesso|conect|naveg|pagar|pagamento|pix|banco|portal|rede|liber)/u',
            $last
        );
        $isFailedPaymentStep = $this->alreadySentPaymentSteps($conv) && $this->visitorSaysStepsFailed($conv);

        if (!$isDeviceAnswer && !$isShortConfirmation && !$isPaymentOrAccessIssue
            && !$isFailedPaymentStep && !$this->visitorDeniesPayment($conv)) {
            return null;
        }

        if ($this->visitorCannotOpenBank($conv)) {
            return [
                'action' => 'reply',
                'message' => $this->bankPaymentBridgeMessage($conv),
            ];
        }

        if ($this->alreadySentPaymentSteps($conv)) {
            return [
                'action' => 'reply',
                'message' => $this->paymentFollowupMessage(),
            ];
        }

        return [
            'action' => 'reply',
            'message' => $this->noActivePaymentMessage($conv),
        ];
    }

    /**
     * Estado financeiro real da conversa. Cadastro e pagamento são conceitos separados:
     * ter um usuário antigo no banco não significa possuir pagamento ativo.
     *
     * @return array<string, mixed>
     */
    private function paymentContext(ChatConversation $conv): array
    {
        if (isset($this->paymentContextCache[$conv->id])) {
            return $this->paymentContextCache[$conv->id];
        }

        $users = collect();
        if ($conv->visitor_mac) {
            $byMac = User::where('mac_address', strtoupper(trim($conv->visitor_mac)))->first();
            if ($byMac) {
                $users->push($byMac);
            }
        }

        $phoneDigits = preg_replace('/\D/', '', (string) $conv->visitor_phone);
        $phoneTail = substr($phoneDigits, -9);
        if (strlen($phoneTail) >= 8) {
            $users = $users->concat(
                User::where('phone', 'like', '%' . $phoneTail)
                    ->orderByDesc('connected_at')
                    ->orderByDesc('id')
                    ->limit(10)
                    ->get()
            );
        }

        $linked = $conv->linked_user;
        if ($linked) {
            $users->push($linked);
        }
        $users = $users->unique('id')->values();

        $activeUser = $users->first(fn (User $candidate) =>
            in_array($candidate->status, ['connected', 'active', 'temp_bypass'], true)
            && $candidate->expires_at
            && $candidate->expires_at->isFuture()
        );
        $activeAccess = (bool) $activeUser;

        $payments = $users->isEmpty()
            ? collect()
            : Payment::whereIn('user_id', $users->pluck('id'))
                ->where('status', 'completed')
                ->orderByDesc('paid_at')
                ->orderByDesc('id')
                ->limit(30)
                ->get();

        $paymentExpiresAt = null;
        $activePayment = false;
        $scheduledPayment = false;
        $payment = null;
        $expiredPayment = null;
        $scheduled = null;
        $today = now()->toDateString();

        foreach ($payments as $candidate) {
            if (!$expiredPayment) {
                $expiredPayment = $candidate;
            }

            if (data_get($candidate->payment_data, 'plan_type') === 'interval') {
                $start = data_get($candidate->payment_data, 'interval.start');
                $end = data_get($candidate->payment_data, 'interval.end');
                if ($start && $end && $start <= $today && $end >= $today) {
                    $payment = $candidate;
                    $activePayment = true;
                    break;
                }
                if (!$scheduled && $start && $start > $today) {
                    $scheduled = $candidate;
                }
                continue;
            }

            if ($candidate->paid_at) {
                $duration = data_get($candidate->payment_data, 'duration_hours');
                if ($duration === null) {
                    $duration = \App\Helpers\SettingsHelper::getSessionDuration();
                }
                $candidateExpiresAt = $candidate->paid_at->copy()->addHours(max((float) $duration, 0.1));
                if ($candidateExpiresAt->isFuture()) {
                    $payment = $candidate;
                    $paymentExpiresAt = $candidateExpiresAt;
                    $activePayment = true;
                    break;
                }
            }
        }

        if (!$payment && $scheduled) {
            $payment = $scheduled;
            $scheduledPayment = true;
        } elseif (!$payment) {
            $payment = $expiredPayment;
        }

        $paymentUser = $payment ? $users->firstWhere('id', $payment->user_id) : null;
        $user = $activeUser ?: $paymentUser ?: $linked;

        $description = 'SEM PAGAMENTO ATIVO — não existe pagamento válido agora para este telefone/aparelho. Oriente a realizar um novo pagamento; não diga apenas que o cadastro expirou.';

        if ($activeAccess) {
            $description = "ACESSO ATIVO — pagamento confirmado e acesso válido até {$activeUser->expires_at->format('d/m H:i')}.";
        } elseif ($activePayment) {
            $expiry = $paymentExpiresAt ? ' até ' . $paymentExpiresAt->format('d/m H:i') : '';
            $description = "PAGAMENTO ATIVO{$expiry}, mas o acesso ainda não aparece como conectado. Investigue o aparelho/MAC antes de escalar.";
        } elseif ($scheduledPayment) {
            $start = data_get($payment->payment_data, 'interval.start');
            $description = 'PAGAMENTO AGENDADO — plano por intervalo válido a partir de ' . \Carbon\Carbon::parse($start)->format('d/m/Y') . '.';
        } elseif ($payment?->paid_at) {
            $description = 'SEM PAGAMENTO ATIVO — há um pagamento anterior, mas ele não está mais dentro da validade. É necessário um novo pagamento.';
        } elseif ($user) {
            $description = 'SEM PAGAMENTO ATIVO — existe cadastro, porém nenhum pagamento aprovado e válido foi encontrado. É necessário pagar para navegar.';
        }

        return $this->paymentContextCache[$conv->id] = [
            'user' => $user,
            'payment' => $payment,
            'active_access' => (bool) $activeAccess,
            'active_payment' => $activePayment,
            'scheduled_payment' => $scheduledPayment,
            'has_valid_payment' => (bool) ($activeAccess || $activePayment || $scheduledPayment),
            'can_troubleshoot_access' => (bool) ($activeAccess || $activePayment),
            'payment_expires_at' => $paymentExpiresAt,
            'description' => $description,
        ];
    }

    private function buildSystemPrompt(ChatConversation $conv): string
    {
        $now = now()->format('d/m/Y H:i');
        $paymentContext = $this->paymentContext($conv);
        $userStatus = $paymentContext['description'];

        $bus = $conv->linked_bus_name ?? 'desconhecido';
        $mac = $conv->visitor_mac ?: 'não capturado';
        $ip = $conv->visitor_ip ?: 'não capturado';
        $phone = $conv->visitor_phone ?: 'não informado';
        $portalUrl = rtrim(config('wifi.server_url', 'https://www.tocantinstransportewifi.com.br'), '/');
        $portalHost = parse_url($portalUrl, PHP_URL_HOST) ?: 'www.tocantinstransportewifi.com.br';

        $pendingProbe = ConnectivityProbe::where('conversation_id', $conv->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists();

        $lastProbe = ConnectivityProbe::where('conversation_id', $conv->id)
            ->where('status', 'completed')
            ->orderByDesc('id')
            ->first();

        $probeStatus = 'nenhum teste foi enviado ainda nesta conversa';
        if ($pendingProbe) {
            $probeStatus = '⚠️ JÁ EXISTE UM TESTE AGUARDANDO RESPOSTA — não peça outro. Oriente o usuário a clicar no botão que apareceu acima.';
        } elseif ($lastProbe) {
            $v = $lastProbe->verdict;
            $r = $lastProbe->results ?? [];
            $dl = isset($r['download_mbps']) ? number_format($r['download_mbps'], 1) . ' Mbps' : 'n/d';
            $lt = isset($r['latency_ms']) ? round($r['latency_ms']) . ' ms' : 'n/d';
            $probeStatus = "Último teste concluído: veredicto={$v}, download={$dl}, latência={$lt}, DNS=" . ($r['dns_ok'] ?? false ? 'OK' : 'falhou') . ", Google=" . ($r['google_ok'] ?? false ? 'OK' : 'falhou') . ". Você pode pedir um novo teste se necessário.";
        }

        // Preços vêm sempre das configurações (/admin/settings), nunca fixos no prompt.
        $priceFullTxt = number_format(\App\Helpers\SettingsHelper::getWifiPriceFull(), 2, ',', '.');
        $priceShortTxt = number_format(\App\Helpers\SettingsHelper::getWifiPrice(), 2, ',', '.');
        $sessionHours = \App\Helpers\SettingsHelper::getSessionDuration();
        $sessionHoursShort = \App\Helpers\SettingsHelper::getSessionDurationShort();
        $discountTxt = '';
        if (\App\Helpers\SettingsHelper::isVideoDiscountEnabled()) {
            $discounted = max(0, \App\Helpers\SettingsHelper::getWifiPriceFull() - \App\Helpers\SettingsHelper::getVideoDiscountAmount());
            $discountTxt = ", ou R\$ " . number_format($discounted, 2, ',', '.') . " quando o desconto do parceiro estiver disponível";
        }

        return <<<PROMPT
Você é a **Ana**, assistente de atendimento da **Tocantins Transporte WiFi**, serviço de internet em ônibus via Starlink. Converse com naturalidade e nunca invente informações que não estejam no contexto.

# Como o serviço funciona
- Rede WiFi do ônibus: **TocantinsTransporteWiFi** (sem senha).
- **Portal de pagamento:** {$portalHost} (URL completa: {$portalUrl} ou www.tocantinstransportewifi.com.br)
- Forma correta de pagar: o celular precisa abrir o **captive portal** (janelinha automática) ou o site www.tocantinstransportewifi.com.br **já conectado no WiFi do ônibus com dados móveis desligados**.
- Escolhe entre Plano por Hora ou Viagem Completa.
- Plano por Hora: R\$ {$priceShortTxt} por {$sessionHoursShort} hora(s) de acesso.
- Viagem Completa é o plano recomendado: R\$ {$priceFullTxt} via PIX{$discountTxt}, com WiFi até o destino final (duração configurada: {$sessionHours} horas corridas).
- Liberação automática em até 15 segundos após pagamento.
- Acesso vinculado ao MAC do dispositivo. Se o MAC mudar (randomização), o sistema não reconhece mais.
- MAC diferente pode explicar "paguei e não funciona", mas só investigue MAC quando o contexto confirmar PAGAMENTO ATIVO ou ACESSO ATIVO.
- Para abrir o portal, os dados móveis devem ficar desligados. Depois de gerar e copiar o PIX, o passageiro pode desligar o WiFi temporariamente e ligar o 4G para abrir o banco e pagar. Após pagar, deve desligar o 4G e voltar ao mesmo WiFi sem esquecer a rede.

# REGRA DO PORTAL — SEMPRE CITE O SITE
Sempre que orientar pagamento, acesso ao portal ou "abrir o navegador", inclua **www.tocantinstransportewifi.com.br** (ou {$portalHost}).
Nunca diga só "abra o navegador" ou "pague pelo portal" sem informar o site.

# REGRA CRÍTICA — LEIA A NEGAÇÃO ANTES DE PEDIR COMPROVANTE
"Não paguei", "ainda não paguei", "quero pagar", "como eu pago" NÃO é afirmação de pagamento — é o contrário.
- Se o visitante disser que NÃO pagou ou que QUER pagar: **PROIBIDO** usar request_receipt, request_probe ou escalate. Use **reply** com o PASSO A PASSO DE PAGAMENTO.
- Vale mesmo que ele tenha dito "paguei" antes: a mensagem MAIS RECENTE manda. Se ele se corrigiu ("na verdade não paguei"), esqueça a afirmação antiga e ensine a pagar.
- Nunca responda a "não paguei" mandando enviar comprovante. Isso irrita o cliente e é o pior erro possível.
- Nunca repita a mesma mensagem duas vezes seguidas. Se o visitante repetir a fala dele, é sinal de que você não entendeu — mude a resposta e trate o que ele realmente disse.

# REGRA CRÍTICA — ESQUECER A REDE
- **ANTES de pagar / SEM PAGAMENTO ATIVO:** primeiro peça apenas para conectar na TocantinsTransporteWiFi, desligar o 4G e abrir o site. Só sugira esquecer a rede se o portal continuar sem abrir.
- **DEPOIS de pagar / ACESSO ATIVO:** NÃO peça pra esquecer a rede (isso gera MAC novo e quebra o vínculo do pagamento). Nesse caso oriente só desligar/ligar o WiFi ou desativar endereço privado.

# Contexto deste visitante
- **Nome:** {$conv->visitor_name}
- **Telefone:** {$phone}
- **MAC atual:** {$mac}
- **IP:** {$ip}
- **Ônibus:** {$bus}
- **Status financeiro e de acesso:** {$userStatus}
- **Status de teste de conexão:** {$probeStatus}
- **Horário agora:** {$now}

# Personalidade e raciocínio
- Tom humano, acolhedor, direto e paciente. Português brasileiro natural.
- Primeiro reconheça o problema, depois diga o que você conferiu no sistema e só então dê o próximo passo.
- Use os dados reais do contexto. Nunca diga "cadastro expirado" quando o contexto disser "SEM PAGAMENTO ATIVO".
- Não faça perguntas cuja resposta o passageiro já informou. Se ele disse "paguei", não pergunte se ele pagou.
- Dê uma orientação por vez e termine com uma pergunta específica sobre o resultado.
- Respostas curtas (2-5 frases), exceto quando um passo a passo for realmente necessário.
- No máximo 1 emoji por mensagem. Use o nome do visitante quando fizer sentido.
- A interface já identifica você como Assistente IA; não finja ser uma pessoa e não fale sobre sua identidade sem necessidade.

# FORMATO DO TEXTO QUE O USUÁRIO LÊ (campo "message") — OBRIGATÓRIO
- NUNCA use asteriscos (*), markdown, **negrito**, _itálico_ ou cercas ```.
- Use texto puro, fácil de ler no celular.
- Em listas/passo a passo: cada item em UMA LINHA NOVA, com quebra de linha real (\n), nunca tudo numa linha só.
- Exemplo certo de passo a passo (copie o estilo):
Vamos liberar seu acesso:

1) Conecte na rede TocantinsTransporteWiFi e desligue o 4G/5G.

2) Abra www.tocantinstransportewifi.com.br e escolha o plano.

3) Gere o PIX e faça o pagamento. A liberação acontece automaticamente em até 15 segundos.

A página de pagamento abriu no seu celular?

# Ações disponíveis (UMA por turno)
1. **reply** — texto normal. Para cumprimentar, perguntar, dar dica, orientar.
2. **request_probe** — pede teste automático de conexão. Use SOMENTE quando o contexto confirma ACESSO ATIVO e ainda existe problema técnico.
3. **request_receipt** — abre no chat um botão para enviar comprovante. Use quando a pessoa afirma que pagou, mas o contexto diz SEM PAGAMENTO ATIVO. Não pergunte de novo se ela pagou.
4. **request_mac** — abre no chat um botão para enviar o MAC. Use SOMENTE quando o contexto confirma PAGAMENTO ATIVO ou ACESSO ATIVO. Antes, pergunte se é iPhone ou Android.
5. **escalate** — passa pro humano. Use SOMENTE em último caso (depois do comprovante, depois da foto do MAC, ou se o usuário pediu atendente).

# REGRA OURO: SEJA INTELIGENTE E PERSISTENTE
Sua missão é RESOLVER o problema do cliente, não escalar de cara. Antes de escalar, você precisa ter feito perguntas e oferecido soluções de verdade. Os admins humanos só recebem casos onde você realmente tentou.

# FLUXOS DE CONVERSA — SIGA RIGOROSAMENTE

## CENÁRIO A: Status = "ACESSO ATIVO" (pagou e tá no prazo)
Usuário pagou e tá ativo, mas reclama de internet. PROBLEMA TÉCNICO.

**Fluxo:**
1. **Botão de recuperação (o sistema manda sozinho):** quando o passageiro reclama de internet, o sistema já envia o botão "Recuperar acesso". Ele informa o telefone e o aparelho atual é religado, mesmo que o MAC tenha mudado. Você NUNCA envia esse botão nem escreve link pra ele.
   - Se ele só cumprimentou (ex.: "oi"), diga que o pagamento está ativo e pergunte o que está acontecendo com a internet. NÃO pergunte o aparelho ainda.
   - Se ele disser que deu certo: comemore e encerre.
   - Se disser que usou a recuperação e não resolveu, pergunte o aparelho:
   "Entendi. Pra te ajudar no passo certo, me fala: você está usando iOS (iPhone) ou Android?"

2. **Quando ele responder iOS / iPhone / Android:** use **request_mac** na hora (passo a passo pra achar o MAC daquele aparelho + botão de foto). NUNCA escalate nesse turno. NUNCA peça só pra desativar endereço privado e parar.
   O sistema confere sozinho o MAC recebido (foto ou digitado) com o MAC liberado. Se for o mesmo, ele já manda o passo a passo do DNS privado; se for diferente, passa pro humano.

3. **Se não resolveu, mande o probe:** "Hmm. Deixa eu mandar um teste rápido pra ver como tá seu sinal." → **request_probe**

4. **Após probe, dica baseada no resultado** (use o "Status de teste" do contexto).

5. **Última tentativa (SEM esquecer a rede):** "Desliga o WiFi do celular por 5 segundos, liga de novo e reconecta no TocantinsTransporteWiFi (não esquece a rede). Me avisa."

6. **Só agora, se ainda não resolveu**: **escalate**.

## CENÁRIO B: Status = "SEM PAGAMENTO ATIVO" + usuário diz claramente "eu paguei" / "já paguei"
O sistema não vê pagamento ativo, mas o usuário *afirma* que pagou.

**REGRA:** Só use este cenário se o usuário *afirmar* que já pagou. Se ele só disser "wifi", "conexão", "não consigo" — use o CENÁRIO C.
Peça o comprovante com **request_receipt** imediatamente. Não pergunte se ele pagou nem peça o horário antes do comprovante.

**Fluxo:**
1. Use **request_receipt**: "Entendi. Conferi aqui e o pagamento ainda não aparece como ativo. Envie o comprovante do PIX pelo botão abaixo para eu localizar a cobrança."

2. **Depois que o comprovante chegar:** o sistema avisa o atendente humano. Só **escalate** se ele pedir atendente ou se o comprovante já foi enviado.

4. **Se disser que NÃO pagou / se confundiu / quer pagar:** ABANDONE este cenário na hora. Nada de comprovante. Use **reply** com o **PASSO A PASSO DE PAGAMENTO** e um reconhecimento curto, tipo:
   "Ah, entendi! Então é só fazer o pagamento que libera na hora. Faz assim: ..."
   Se você já mandou o passo a passo nesta conversa, NÃO repita igual — pergunte onde ele parou e mande o caminho direto do portal.

## CENÁRIO C: Status = "SEM PAGAMENTO ATIVO" + usuário NÃO afirma ter pago
(Casos tipo "Wifi", "quero wifi", "Conexão do celular", "Não estou conseguindo", "sem internet", "como pago".)
**NUNCA use request_probe.**

**Primeiro turno — se ainda NÃO mandou o passo a passo nesta conversa:**
action: **reply** com o **PASSO A PASSO DE PAGAMENTO** (pode perguntar antes OU já mandar direto se ele pediu "como pago").

**Se o usuário confirmar** (ex.: "sim", "quero sim", "pode", "manda") e você ainda NÃO mandou o passo a passo:
action: **reply** com o **PASSO A PASSO DE PAGAMENTO**. Não escalate.

**Se o usuário NÃO ENTENDEU um passo** (ex.: "não entendi", "como desligo o 4G", "como continuo pro pagamento", "explica melhor"):
action: **reply**. EXPLIQUE aquele passo com calma. **PROIBIDO escalate.** Dúvida não é falha.

Use o **COMO DESLIGAR O 4G** quando a dúvida for dados móveis / 4G / continuar pro pagamento.

**DEPOIS que o passo a passo JÁ FOI ENVIADO nesta conversa:**
Se ainda não abre, pergunte exatamente onde travou: página não abre, PIX não é gerado ou banco não abre. NÃO peça MAC e NÃO faça teste de conexão enquanto não houver pagamento ativo.

"Não entendi" / "como faço" / "explica" NÃO é motivo pra escalar.

## PASSO A PASSO DE PAGAMENTO (use sempre que for orientar a conectar/pagar)
Mande EXATAMENTE neste estilo (texto puro, cada passo em linha nova, SEM asteriscos):

Vamos liberar seu acesso:

1) Conecte na rede TocantinsTransporteWiFi e desligue o 4G/5G.

2) Abra www.tocantinstransportewifi.com.br e escolha o plano.

3) Gere o PIX e faça o pagamento. A liberação acontece automaticamente em até 15 segundos.

A página de pagamento abriu no seu celular?

## COMO DESLIGAR O 4G (use quando o usuário não entender esse passo)
Mande neste estilo, texto puro, SEM asteriscos:

Tranquilo! O 4G é a internet do chip. Pra pagar no WiFi do ônibus, ele precisa estar desligado.

iPhone: Ajustes → Celular (ou Dados Celulares) → desliga o botão no topo.

Android: desliza a tela de cima pra baixo e toca no ícone Dados móveis / 4G até apagar.

Depois fica só no WiFi TocantinsTransporteWiFi, abre o navegador em www.tocantinstransportewifi.com.br, escolhe o plano e paga no PIX.

Me fala se é iPhone ou Android que eu te guiando no detalhe!

## CENÁRIO H: Insiste que não consegue acessar (possível MAC diferente)
Use este cenário SOMENTE se o contexto confirmar PAGAMENTO ATIVO ou ACESSO ATIVO. Às vezes o pagamento liberou outro MAC (endereço privado / MAC aleatório). Antes de passar pro humano:

1. Pergunte: você está usando iOS (iPhone) ou Android?
2. No turno SEGUINTE, quando ele responder iOS/iPhone/Android, use **request_mac** (obrigatório). NUNCA escalate nesse turno.

iPhone: Ajustes → Wi-Fi → toca no (i) azul ao lado de TocantinsTransporteWiFi. O Endereço Wi-Fi / MAC aparece aí. Manda uma foto dessa tela ou escreve o código (tipo AA:BB:CC:DD:EE:FF).

Android: Configurações → Wi-Fi → toca na rede TocantinsTransporteWiFi (ou em detalhes / i). Procura Endereço MAC ou MAC do dispositivo. Manda foto ou escreve o código.

NÃO use request_mac sem saber se é iOS (iPhone) ou Android. Quando ele responder o aparelho, request_mac é obrigatório.

## CENÁRIO F: Portal não abre / "não consigo pagar" / "não consigo conectar"
- Se o usuário está com DÚVIDA (não entendeu / como faz): explique o passo. Não escalate.
- Se o passo a passo **ainda não** foi enviado nesta conversa: mande o **PASSO A PASSO DE PAGAMENTO**.
- Se ainda não há pagamento ativo e o passo a passo falhou: pergunte qual tela aparece e trate portal, PIX ou banco. Não peça MAC.
- Se há PAGAMENTO ATIVO ou ACESSO ATIVO e mesmo assim não navega: siga o cenário H.

**Se portal abre mas trava no PIX/cadastro:**
"Qual parte trava? É na hora de gerar o PIX, na tela de cadastro ou depois de pagar? Me descreve o que aparece que eu te guio."

**Se não está no ônibus:**
"O pagamento só funciona dentro do ônibus, conectado no TocantinsTransporteWiFi. Quando estiver a bordo, me chama que te passo o passo a passo."

## CENÁRIO G: Após resultado do teste de conexão (mensagem "Teste concluído")
Interprete os dados e responda de forma **específica**.

**Se "Sem pagamento ativo" (com ou sem dados móveis):**
use o **PASSO A PASSO DE PAGAMENTO** (lembre de desligar o 4G no passo 1).

**Se "Pagamento ativo" + conexão ruim:**
"Seu pagamento tá ativo, mas o sinal tá fraco. Me fala: você está usando iOS (iPhone) ou Android?" Depois da resposta, **request_mac**. *(NÃO peça pra esquecer a rede neste caso.)*

**Se "Pagamento ativo" + conexão boa:**
"O teste mostrou que tá tudo certo com pagamento e conexão! Se ainda não navega, desliga e liga o WiFi (sem esquecer a rede) ou fecha e abre o navegador. Funcionou?"

## CENÁRIO D: Pediu atendente humano
Só escale se o usuário pediu EXPLICITAMENTE atendente/humano (ex.: "quero falar com atendente", "chama um humano").
"sim", "quero sim", "pode", "manda o passo a passo" NÃO é pedido de atendente — nesse caso mande o PASSO A PASSO.
Se pediu atendente de verdade: "Claro, {$conv->visitor_name}! Já vou passar pro meu colega. Aguarda só um minutinho."

## CENÁRIO E: Quer pagar pra outro celular
"O pagamento fica vinculado ao celular conectado no WiFi do ônibus. Pra liberar outro aparelho, a pessoa precisa conectar AQUELE celular no TocantinsTransporteWiFi, abrir o navegador, acessar {$portalHost} e pagar por lá. Não tem como pagar de um e liberar em outro, infelizmente."

# DICAS TÉCNICAS DETALHADAS

## iPhone (iOS)
- **Endereço Privado** (causa #1): Ajustes → Wi-Fi → (i) ao lado da rede → desativa "Endereço Privado/Privacy Address" → reconecta.
- **Portal não aparece**: Abre o **Safari** (não Chrome) → acessa **{$portalHost}** ou **http://google.com** (sem https) → portal aparece.
- **Assistência Wi-Fi**: Ajustes → Celular → desativa "Assistência Wi-Fi" (impede trocar pra 4G).
- **Esquecer rede**: Ajustes → Wi-Fi → (i) → "Esquecer Esta Rede" → reconecta.

## Android
- **DNS privado** (pagou, MAC confere, mas nenhum site abre / "não é possível acessar esse site"): Configurações → Conexões → Mais configurações de conexão → DNS privado → Automático (ou Desativado) → desliga e liga o WiFi. Em outros Android: Configurações → Rede e internet → DNS privado.
- **MAC aleatório** (causa #1): Configurações → Wi-Fi → segura na rede → "Modificar"/"Avançado" → "Privacidade"/"Tipo de endereço MAC" → muda pra "MAC do dispositivo" → reconecta.
- **Portal não aparece**: Chrome → acessa **{$portalHost}** ou **http://google.com** → toca em "Fazer login na rede" se aparecer. Senão, desconecta e reconecta.
- **Dados móveis interferindo**: desativa "Mudar para dados móveis automaticamente" (Samsung: "Dados móveis inteligentes"; Xiaomi: "Assistente Wi-Fi").
- **Esquecer rede**: Configurações → Wi-Fi → segura na rede → "Esquecer".

## Geral (qualquer celular)
- Desligar 4G/5G antes de conectar.
- Modo avião + ligar só o WiFi.
- Reiniciar WiFi (desliga 5s, liga).

# REGRA DE FORMATO — OBRIGATÓRIO
Responda APENAS com JSON válido, sem markdown, sem ```json:
{"action":"reply","message":"texto que o usuário lê"}
ou
{"action":"request_probe","message":"Vou mandar um teste rápido pra ver como tá seu sinal, leva 15 segundos."}
ou
{"action":"request_receipt","message":"Pra eu localizar seu pagamento, me manda o comprovante do PIX (foto ou print). Use o botão abaixo."}
ou
{"action":"request_mac","message":"Pra liberar o aparelho certo, me manda o MAC da rede. Use o botão abaixo pra enviar a foto da tela ou escreva o código."}
ou
{"action":"escalate","message":"Vou passar pro meu colega, aguarda um minutinho."}

Apenas JSON cru, nada antes ou depois.
PROMPT;
    }

    private function buildMessages(ChatConversation $conv): array
    {
        $system = $this->buildSystemPrompt($conv);

        // Últimas 10 mensagens pra dar contexto
        $history = ChatMessage::where('conversation_id', $conv->id)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get()
            ->reverse()
            ->values();

        $arr = [['role' => 'system', 'content' => $system]];

        foreach ($history as $msg) {
            $type = $msg->type ?? 'text';
            if ($msg->sender_type === 'visitor') {
                $content = (string) $msg->message;
                if ($type === 'receipt_upload') {
                    $content .= ' [enviou comprovante PIX]';
                } elseif ($type === 'mac_upload') {
                    $content .= ' [enviou foto do MAC]';
                } elseif ($type === 'mac_text' && !empty($msg->metadata['mac_address'])) {
                    $content .= ' [MAC digitado: ' . $msg->metadata['mac_address'] . ']';
                }
                if ($type === 'probe_result') {
                    $r = $msg->metadata['results'] ?? [];
                    $hints = [];
                    if (array_key_exists('payment_active', $r)) {
                        $hints[] = $r['payment_active'] ? 'pagamento_ativo=sim' : 'pagamento_ativo=não';
                    }
                    if (array_key_exists('is_cellular', $r)) {
                        $hints[] = $r['is_cellular'] ? 'usando_dados_móveis=sim' : 'usando_dados_móveis=não';
                    }
                    if (array_key_exists('is_wifi', $r)) {
                        $hints[] = $r['is_wifi'] ? 'no_wifi=sim' : 'no_wifi=não';
                    }
                    if (!empty($hints)) {
                        $content .= ' [' . implode(', ', $hints) . ']';
                    }
                }
                $arr[] = ['role' => 'user', 'content' => $content];
            } else {
                // admin (humano ou IA) conta como assistant
                $prefix = '';
                if ($type === 'probe_request') $prefix = '[sistema: enviou probe] ';
                elseif ($type === 'probe_result') {
                    $verdict = $msg->metadata['verdict'] ?? 'n/d';
                    $prefix = "[resultado do teste: {$verdict}] ";
                } elseif ($type === 'receipt_request') {
                    $prefix = '[sistema: pediu comprovante PIX] ';
                } elseif ($type === 'mac_request') {
                    $prefix = '[sistema: pediu foto/MAC do aparelho] ';
                }
                $arr[] = ['role' => 'assistant', 'content' => $prefix . (string) $msg->message];
            }
        }

        return $arr;
    }

    // ---------- Together.ai ----------

    private function callApi(array $messages): ?array
    {
        $http = Http::timeout((int) config('services.together.timeout', 15))
            ->withHeaders([
                'Authorization' => 'Bearer ' . config('services.together.api_key'),
                'Content-Type' => 'application/json',
            ]);

        if (!config('services.together.verify_ssl', true)) {
            $http = $http->withoutVerifying();
        }

        $payload = [
            'model' => config('services.together.model'),
            'messages' => $messages,
            'max_tokens' => 500,
            'temperature' => 0.4,
            'stream' => false,
            'thinking' => ['type' => 'disabled'],
        ];

        // Tentar com response_format primeiro
        $response = $http->post(config('services.together.api_url'), array_merge($payload, [
            'response_format' => ['type' => 'json_object'],
        ]));

        // Se falhou com response_format, tentar sem ele
        if (!$response->successful()) {
            Log::info('🤖 Tentando sem response_format', ['status' => $response->status()]);
            $response = $http->post(config('services.together.api_url'), $payload);
        }

        if (!$response->successful()) {
            Log::warning('🤖 AI API falhou', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
                'url' => config('services.together.api_url'),
                'model' => config('services.together.model'),
            ]);
            return null;
        }

        $content = $response->json('choices.0.message.content');
        
        // DeepSeek pode retornar content vazio quando usa thinking mode
        // Nesse caso o texto real fica em reasoning_content
        if (!$content || trim($content) === '') {
            $content = $response->json('choices.0.message.reasoning_content');
        }
        
        if (!$content) {
            Log::warning('🤖 AI API sem content', ['response' => substr($response->body(), 0, 500)]);
            return null;
        }

        // Remove possíveis cercas de código que o modelo insista em colocar
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $content);
        $content = trim($content);

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            Log::warning('🤖 JSON da IA inválido', ['raw' => substr($content, 0, 300)]);
            return null;
        }

        return $decoded;
    }

    // ---------- Execução das ações ----------

    private function executeAction(ChatConversation $conv, array $decision): ChatMessage
    {
        $action = $decision['action'];
        $text = $this->sanitizeUserFacingMessage((string) ($decision['message'] ?? ''));
        if ($text === '') $text = 'Estou processando, um instante.';
        if (mb_strlen($text) > 900) $text = mb_substr($text, 0, 900);

        return match ($action) {
            'request_probe' => $this->actionProbe($conv, $text),
            'request_receipt' => $this->actionRequestReceipt($conv, $text),
            'request_mac' => $this->actionRequestMac($conv, $text),
            'request_recovery' => $this->actionRequestRecovery($conv, $text),
            'escalate' => $this->actionEscalate($conv, $text, $decision['reason'] ?? null),
            default => $this->actionReply($conv, $text),
        };
    }

    private function actionReply(ChatConversation $conv, string $text): ChatMessage
    {
        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type' => 'admin',
            'admin_id' => null,
            'type' => 'text',
            'message' => $text,
            'metadata' => ['ai' => true, 'ai_name' => 'Ana', 'model' => config('services.together.model')],
            'is_read' => true,
        ]);

        $conv->update([
            'last_message_at' => now(),
            'status' => 'active',
        ]);

        Log::info('💬 Ana (IA) respondeu', ['conversation_id' => $conv->id]);
        return $msg;
    }

    private function actionProbe(ChatConversation $conv, string $text): ChatMessage
    {
        if (!$this->visitorHasActiveAccess($conv)) {
            if ($this->visitorHasValidPayment($conv)) {
                return $this->actionReply(
                    $conv,
                    'Seu pagamento está confirmado, mas o acesso ainda não aparece como conectado. Você está usando iPhone ou Android?'
                );
            }

            return $this->actionReply(
                $conv,
                $this->noActivePaymentMessage($conv)
            );
        }

        // Só bloqueia se já existe probe PENDENTE (não expirado, não concluído).
        // Se o anterior foi completado ou expirou, pode mandar outro.
        $pendingProbe = ConnectivityProbe::where('conversation_id', $conv->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->exists();

        if ($pendingProbe) {
            return $this->actionReply($conv, 'Já tem um teste aberto acima — clica no botão pra rodar, por favor.');
        }

        $probe = ConnectivityProbe::create([
            'token' => ConnectivityProbe::generateToken(),
            'conversation_id' => $conv->id,
            'created_by_admin_id' => null,
            'target_mac' => $conv->visitor_mac,
            'target_phone' => $conv->visitor_phone,
            'status' => 'pending',
            'expires_at' => now()->addMinutes(30),
        ]);

        $probeUrl = route('diagnostico.show', ['token' => $probe->token]);

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type' => 'admin',
            'admin_id' => null,
            'type' => 'probe_request',
            'message' => $text ?: '🔍 Vou rodar um teste rápido. Toque no botão — leva uns 15 segundos.',
            'metadata' => [
                'ai' => true,
                'ai_name' => 'Ana',
                'model' => config('services.together.model'),
                'probe_id' => $probe->id,
                'probe_token' => $probe->token,
                'probe_url' => $probeUrl,
                'expires_at' => $probe->expires_at->toIso8601String(),
            ],
            'is_read' => true,
        ]);

        $conv->update([
            'last_message_at' => now(),
            'status' => 'active',
        ]);

        Log::info('🤖 IA pediu probe', ['conversation_id' => $conv->id, 'probe_id' => $probe->id]);
        return $msg;
    }

    /**
     * Abre no chat o cartão pra o visitante enviar comprovante do PIX.
     * Só faz sentido quando o usuário insiste que pagou e o sistema não vê o pagamento.
     */
    private function actionRequestReceipt(ChatConversation $conv, string $text): ChatMessage
    {
        // Evita spam: se já pediu comprovante nos últimos 10 min e ainda não enviou, só relembra
        $recentRequest = ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'receipt_request')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        $alreadyUploaded = ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'receipt_upload')
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();

        if ($alreadyUploaded) {
            return $this->actionEscalate(
                $conv,
                'Já recebi seu comprovante. Vou passar pro meu colega conferir e liberar se estiver tudo certo. Aguarda só um minutinho!',
                'comprovante já enviado'
            );
        }

        if ($recentRequest) {
            // Ele disse que não pagou depois do pedido: era engano nosso, ensina a pagar.
            if ($this->visitorDeniesPayment($conv)) {
                Log::info('🤖 Comprovante pedido por engano — visitante não pagou', ['conversation_id' => $conv->id]);
                return $this->actionReply($conv, $this->paymentStepsMessage());
            }

            // Não repete o mesmo lembrete pra sempre: no segundo, chama o humano.
            $reminders = ChatMessage::where('conversation_id', $conv->id)
                ->where('sender_type', 'admin')
                ->whereNull('admin_id')
                ->where('created_at', '>=', now()->subMinutes(30))
                ->where('message', 'like', '%comprovante%')
                ->where('type', 'text')
                ->count();

            if ($reminders >= 1) {
                return $this->actionEscalate(
                    $conv,
                    'Beleza, vou chamar meu colega pra conferir seu pagamento direto no sistema. Aguarda só um minutinho!',
                    'não enviou comprovante após lembrete'
                );
            }

            return $this->actionReply(
                $conv,
                "O botão pra enviar o comprovante do PIX está logo acima na conversa. Se preferir, me diga o horário do PIX e o nome de quem pagou que eu procuro aqui."
            );
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type' => 'admin',
            'admin_id' => null,
            'type' => 'receipt_request',
            'message' => $text ?: 'Pra eu localizar seu pagamento, me manda o comprovante do PIX (foto ou print). Use o botão abaixo.',
            'metadata' => [
                'ai' => true,
                'ai_name' => 'Ana',
                'model' => config('services.together.model'),
                'receipt_requested' => true,
            ],
            'is_read' => true,
        ]);

        $conv->update([
            'last_message_at' => now(),
            'status' => 'active',
        ]);

        Log::info('🤖 IA pediu comprovante PIX', ['conversation_id' => $conv->id]);
        return $msg;
    }

    /**
     * Abre no chat o cartão com o botão "Recuperar acesso": o visitante informa o
     * telefone e o sistema religa o aparelho atual — inclusive quando o MAC mudou,
     * porque a recuperação troca o MAC do cadastro antes de responder.
     */
    private function actionRequestRecovery(ChatConversation $conv, string $text): ChatMessage
    {
        if (!$this->visitorHasValidPayment($conv)) {
            return $this->actionReply($conv, $this->noActivePaymentMessage($conv));
        }

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type' => 'admin',
            'admin_id' => null,
            'type' => 'recovery_request',
            'message' => $text,
            'metadata' => [
                'ai' => true,
                'ai_name' => 'Ana',
                'model' => config('services.together.model'),
                // Fora do portal (sem a janela de recuperação na página) o botão abre esta.
                'recovery_url' => '/reativar',
            ],
            'is_read' => true,
        ]);

        $conv->update([
            'last_message_at' => now(),
            'status' => 'active',
        ]);

        Log::info('🤖 IA enviou o botão de recuperação por telefone', [
            'conversation_id' => $conv->id,
        ]);
        return $msg;
    }

    /**
     * Abre no chat o cartão pra o visitante enviar foto do MAC (ou escrever o código).
     */
    private function actionRequestMac(ChatConversation $conv, string $text): ChatMessage
    {
        if (!$this->visitorHasValidPayment($conv)) {
            return $this->actionReply($conv, $this->noActivePaymentMessage($conv));
        }

        if ($this->alreadyCollectedMac($conv)) {
            $mac = $this->collectedMacAddress($conv);
            $suffix = $mac ? " ({$mac})" : '';
            return $this->actionEscalate(
                $conv,
                "Já recebi o MAC{$suffix}. Vou passar pro meu colega liberar esse aparelho. Aguarda só um minutinho!",
                'MAC já enviado'
            );
        }

        $device = $this->visitorDeviceFromHistory($conv);
        if ($device === null) {
            return $this->actionReply(
                $conv,
                'Beleza. Às vezes o WiFi libera outro aparelho. Pra te ajudar no passo certo, você está usando iOS (iPhone) ou Android?'
            );
        }

        $recentRequest = ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'mac_request')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->exists();

        if ($recentRequest) {
            return $this->actionReply(
                $conv,
                'O botão pra enviar a foto do MAC já está na conversa acima. Manda a foto da tela ou escreve o código (tipo AA:BB:CC:DD:EE:FF) que eu passo pro meu colega liberar.'
            );
        }

        $instructions = $this->macInstructionsMessage($device);
        $looksComplete = mb_strlen($text) >= 80 && preg_match('/ajustes|configura/iu', $text);
        $cardText = $looksComplete ? $text : $instructions;

        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type' => 'admin',
            'admin_id' => null,
            'type' => 'mac_request',
            'message' => $cardText,
            'metadata' => [
                'ai' => true,
                'ai_name' => 'Ana',
                'model' => config('services.together.model'),
                'mac_requested' => true,
                'device' => $device,
            ],
            'is_read' => true,
        ]);

        $conv->update([
            'last_message_at' => now(),
            'status' => 'active',
        ]);

        Log::info('🤖 IA pediu MAC do aparelho', [
            'conversation_id' => $conv->id,
            'device' => $device,
        ]);
        return $msg;
    }

    /**
     * MAC recebido (digitado ou lido da foto): confere com o que está liberado no sistema.
     * - Confere → o acesso está ativo; o culpado costuma ser o DNS privado do celular.
     * - Diferente → humano libera o aparelho certo.
     * - Foto ilegível → pede pra digitar; na segunda vez, humano.
     */
    public function handleCollectedMac(ChatConversation $conv, ?string $mac): ChatMessage
    {
        $device = $this->visitorDeviceFromHistory($conv) ?? 'both';

        if (!$mac) {
            $alreadyAskedToType = ChatMessage::where('conversation_id', $conv->id)
                ->where('sender_type', 'admin')
                ->whereNull('admin_id')
                ->where('message', 'like', '%consegui ler o MAC%')
                ->exists();

            if (!$alreadyAskedToType) {
                return $this->actionReply(
                    $conv,
                    'Recebi a foto, mas não consegui ler o MAC nela. Pode escrever o código aqui? Fica em Endereço MAC (tipo AA:BB:CC:DD:EE:FF).'
                );
            }

            return $this->actionEscalate(
                $conv,
                "Vou passar pro meu colega conferir a foto e liberar o aparelho. Enquanto isso, tenta este ajuste:\n\n"
                    . $this->privateDnsStepsMessage($device),
                'foto do MAC enviada (IA não conseguiu ler o MAC)'
            );
        }

        if (!$this->macIsLiberated($mac)) {
            $liberated = implode(', ', $this->liberatedMacsForConversation($conv)) ?: 'nenhum';
            return $this->actionEscalate(
                $conv,
                "Conferi aqui: o MAC do seu celular ({$mac}) é diferente do que está liberado no sistema. Vou passar pro meu colega liberar este aparelho. Aguarda só um minutinho!",
                "MAC do aparelho {$mac} não está liberado (liberado no sistema: {$liberated})"
            );
        }

        if ($this->alreadySentPrivateDnsSteps($conv)) {
            return $this->actionEscalate(
                $conv,
                "O MAC {$mac} confere com o sistema. Vou passar pro meu colega verificar direto no roteador do ônibus. Aguarda só um minutinho!",
                "MAC {$mac} confere com o sistema e o DNS privado já foi orientado"
            );
        }

        Log::info('🤖 MAC confere com o sistema — orientando DNS privado', [
            'conversation_id' => $conv->id,
            'mac' => $mac,
        ]);

        return $this->actionReply(
            $conv,
            "Conferi aqui: o MAC {$mac} é o mesmo que está liberado no sistema, então seu acesso está ativo. ✅\n\n"
                . "Quando isso acontece e nenhum site abre, o que costuma travar é o DNS privado do celular. Faz assim:\n\n"
                . $this->privateDnsStepsMessage($device)
                . "\n\nVoltou a navegar?"
        );
    }

    /**
     * Lê o MAC na foto da tela de detalhes do Wi-Fi usando um modelo com visão.
     * Retorna null se a leitura estiver desativada, falhar ou não houver MAC legível.
     */
    public function readMacFromImage(string $path, ?string $mime = null): ?string
    {
        $model = config('services.together.vision_model');
        if (!$this->isEnabled() || !$model || !is_file($path)) {
            return null;
        }

        $mime = $mime ?: (mime_content_type($path) ?: 'image/jpeg');
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode((string) file_get_contents($path));

        try {
            $http = Http::timeout((int) config('services.together.vision_timeout', 25))
                ->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.together.api_key'),
                    'Content-Type' => 'application/json',
                ]);

            if (!config('services.together.verify_ssl', true)) {
                $http = $http->withoutVerifying();
            }

            $response = $http->post(config('services.together.vision_api_url') ?: config('services.together.api_url'), [
                'model' => $model,
                'max_tokens' => 60,
                'temperature' => 0,
                'stream' => false,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Esta é uma captura da tela de detalhes do Wi-Fi de um celular. '
                                . 'Responda somente com o endereço MAC do Wi-Fi mostrado (Endereço MAC, Endereço Wi-Fi ou MAC do dispositivo), '
                                . 'no formato AA:BB:CC:DD:EE:FF. Ignore endereços IP e IPv6. Se não houver MAC legível, responda NENHUM.',
                        ],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ]],
            ]);

            if (!$response->successful()) {
                Log::warning('🤖 Leitura do MAC na foto falhou', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                    'model' => $model,
                ]);
                return null;
            }

            $mac = self::extractMacAddress((string) $response->json('choices.0.message.content'));
            Log::info('🤖 MAC lido na foto', ['mac' => $mac]);

            return $mac;
        } catch (\Throwable $e) {
            Log::warning('🤖 Erro ao ler MAC na foto', ['error' => $e->getMessage()]);
            return null;
        }
    }

    private function macIsLiberated(string $mac): bool
    {
        return User::whereRaw('UPPER(mac_address) = ?', [strtoupper($mac)])
            ->whereIn('status', ['connected', 'active', 'temp_bypass'])
            ->where('expires_at', '>', now())
            ->exists();
    }

    /**
     * MACs liberados ligados a esta conversa (telefone/usuário vinculado) — só pro motivo da escalada.
     *
     * @return array<int, string>
     */
    private function liberatedMacsForConversation(ChatConversation $conv): array
    {
        $user = $this->paymentContext($conv)['user'];
        $phoneTail = substr(preg_replace('/\D/', '', (string) $conv->visitor_phone), -9);

        $query = User::whereIn('status', ['connected', 'active', 'temp_bypass'])
            ->where('expires_at', '>', now())
            ->whereNotNull('mac_address');

        if (strlen($phoneTail) >= 8) {
            $query->where(function ($q) use ($phoneTail, $user) {
                $q->where('phone', 'like', '%' . $phoneTail);
                if ($user) {
                    $q->orWhere('id', $user->id);
                }
            });
        } elseif ($user) {
            $query->where('id', $user->id);
        } else {
            return [];
        }

        return $query->pluck('mac_address')->map(fn ($m) => strtoupper((string) $m))->unique()->values()->all();
    }

    private function privateDnsStepsMessage(string $device): string
    {
        $android = "1) Abra Configurações → Conexões → Mais configurações de conexão → DNS privado (em outros Android: Configurações → Rede e internet → DNS privado).\n\n"
            . "2) Escolha Automático (ou Desativado) e salve.\n\n"
            . "3) Desligue e ligue o WiFi, ou troque de rede e volte para a TocantinsTransporteWiFi.";

        $ios = "1) Desative VPN ou app de bloqueio de anúncios (Ajustes → VPN).\n\n"
            . "2) Em Ajustes → Wi-Fi → toque no (i) da TocantinsTransporteWiFi → Configurar DNS → Automático.\n\n"
            . "3) Desligue e ligue o WiFi e volte para a TocantinsTransporteWiFi.";

        $warning = "Importante: não toque em Esquecer a rede e não mude o tipo de endereço MAC / Endereço Privado, senão o celular troca de MAC e perde a liberação.";

        return match ($device) {
            'ios' => $ios . "\n\n" . $warning,
            'android' => $android . "\n\n" . $warning,
            default => "Android:\n\n" . $android . "\n\niPhone: desative VPN e, em Ajustes → Wi-Fi → (i) → Configurar DNS, deixe Automático.\n\n" . $warning,
        };
    }

    private function alreadySentPrivateDnsSteps(ChatConversation $conv): bool
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->whereNull('admin_id')
            ->where('created_at', '>=', now()->subHours(6))
            ->where(function ($q) {
                $q->where('message', 'like', '%DNS privado%')
                    ->orWhere('message', 'like', '%Configurar DNS%');
            })
            ->exists();
    }

    private function actionEscalate(ChatConversation $conv, string $text, ?string $reason): ChatMessage
    {
        $msg = ChatMessage::create([
            'conversation_id' => $conv->id,
            'sender_type' => 'admin',
            'admin_id' => null,
            'type' => 'text',
            'message' => $text ?: 'Vou passar pro meu colega que consegue resolver isso direto no sistema. Aguarda só um minutinho.',
            'metadata' => [
                'ai' => true,
                'ai_name' => 'Ana',
                'model' => config('services.together.model'),
                'escalated' => true,
                'reason' => $reason,
            ],
            'is_read' => true,
        ]);

        $conv->update([
            'last_message_at' => now(),
            'status' => 'pending',
            'unread_count' => $conv->unread_count + 1,
        ]);

        // Avisa admin humano que tem caso pra pegar
        try {
            app(\App\Services\NtfyService::class)->send(
                "💬 Ana passou conversa pra você",
                "{$conv->visitor_name} ({$conv->visitor_phone})\n\nMotivo: " . ($reason ?? 'Não conseguiu resolver'),
                'high',
                ['speech_balloon', 'warning']
            );
        } catch (\Exception $e) {}

        Log::info('💬 Ana (IA) escalou pra humano', [
            'conversation_id' => $conv->id,
            'reason' => $reason,
        ]);

        return $msg;
    }

    private function escalateFallback(ChatConversation $conv, string $reason): ChatMessage
    {
        return $this->actionEscalate(
            $conv,
            'Vou passar pro meu colega que consegue te ajudar melhor. Aguarda só um minutinho!',
            $reason
        );
    }

    /**
     * Limpa markdown/asteriscos e organiza listas numeradas pra leitura no celular.
     */
    private function sanitizeUserFacingMessage(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        // Remove cercas de código se o modelo insistir
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $text) ?? $text;

        // Separa passos "1) ... 2) ..." que vieram numa linha só
        $text = preg_replace('/\s+(\d+\))\s+/u', "\n\n$1 ", $text) ?? $text;

        // Remove ênfase markdown **x** / *x* (mantém o texto)
        $text = preg_replace('/\*\*([^*]+)\*\*/u', '$1', $text) ?? $text;
        $text = preg_replace('/\*([^*\n]+)\*/u', '$1', $text) ?? $text;

        // Asteriscos soltos restantes
        $text = str_replace('*', '', $text);

        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function visitorHasActiveAccess(ChatConversation $conv): bool
    {
        return (bool) $this->paymentContext($conv)['active_access'];
    }

    private function visitorHasValidPayment(ChatConversation $conv): bool
    {
        return (bool) $this->paymentContext($conv)['can_troubleshoot_access'];
    }

    /**
     * Visitante disse que NÃO pagou (ou que quer pagar): ensinar a pagar.
     * Nunca pedir comprovante, nunca rodar probe, nunca escalar por isso.
     */
    private function guardWantsToPayNeverAskReceipt(ChatConversation $conv, array $decision): array
    {
        if (!$this->visitorDeniesPayment($conv)) {
            return $decision;
        }

        if ($this->visitorAskedForHuman($conv) || $this->visitorHasActiveAccess($conv)) {
            return $decision;
        }

        $action = $decision['action'] ?? 'reply';
        $message = mb_strtolower((string) ($decision['message'] ?? ''));

        $wrongAction = in_array($action, ['request_receipt', 'request_probe', 'escalate'], true);
        $insistsOnReceipt = str_contains($message, 'comprovante');

        if (!$wrongAction && !$insistsOnReceipt) {
            return $decision;
        }

        // Já ensinamos a pagar e ele continua dizendo que quer pagar → deixa a IA seguir
        // (evita repetir o mesmo texto pra sempre).
        if ($this->alreadySentPaymentSteps($conv) && $this->alreadySentPaymentFollowup($conv)) {
            return $decision;
        }

        Log::info('🤖 Guard: visitante NÃO pagou — ensinando a pagar em vez de pedir comprovante', [
            'conversation_id' => $conv->id,
            'was_action' => $action,
        ]);

        return [
            'action' => 'reply',
            'message' => $this->alreadySentPaymentSteps($conv)
                ? $this->paymentFollowupMessage()
                : $this->paymentStepsMessage(),
        ];
    }

    /**
     * Se o usuário insiste que pagou, status sem acesso ativo e a IA tenta escalar
     * (ou pedir probe) sem ter comprovante, força request_receipt.
     */
    private function guardPaidWithoutReceipt(ChatConversation $conv, array $decision): array
    {
        $action = $decision['action'] ?? 'reply';
        if (!in_array($action, ['escalate', 'request_probe'], true)) {
            return $decision;
        }

        if ($this->visitorHasActiveAccess($conv)) {
            return $decision;
        }

        if ($this->visitorAskedForHuman($conv)) {
            return $decision;
        }

        if (!$this->visitorClaimsPaid($conv)) {
            return $decision;
        }

        $alreadyUploaded = ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'receipt_upload')
            ->where('created_at', '>=', now()->subHours(6))
            ->exists();

        if ($alreadyUploaded) {
            return $decision;
        }

        Log::info('🤖 Guard: forçando request_receipt (insistiu que pagou, sem comprovante)', [
            'conversation_id' => $conv->id,
            'was_action' => $action,
        ]);

        return [
            'action' => 'request_receipt',
            'message' => 'Pra eu localizar seu pagamento, me manda o comprovante do PIX (foto ou print). Use o botão abaixo pra enviar.',
        ];
    }

    /**
     * Usuário sem pagamento pediu só orientação (wifi / passo a passo / "sim").
     * A IA NÃO deve escalar cedo — manda o passo a passo UMA vez.
     * Se já mandou e o usuário diz que não funcionou, deixa escalar.
     */
    private function guardPaymentHelpWithoutEscalate(ChatConversation $conv, array $decision): array
    {
        $action = $decision['action'] ?? 'reply';
        if ($action !== 'escalate' && $action !== 'request_probe') {
            return $decision;
        }

        if ($this->visitorHasActiveAccess($conv)) {
            return $decision;
        }

        if ($this->visitorAskedForHuman($conv)) {
            return $decision;
        }

        // Já mandou o passo a passo e o usuário diz que falhou → pode escalar
        if ($this->alreadySentPaymentSteps($conv) && $this->visitorSaysStepsFailed($conv)) {
            return $decision;
        }

        // Já mandou o passo a passo (mesmo sem "falhou" explícito) e tenta escalar cedo
        // só bloqueia se for a primeira confirmação ("sim"/"quero") — aí manda os passos
        if ($this->alreadySentPaymentSteps($conv)) {
            // Se não reclamou de falha, ainda pode ser cedo demais pra escalar
            // em "sim/ok" após oferta — mas se já mandou passos, deixa a IA decidir escalate
            // Apenas bloqueia escalate prematuro quando ainda NÃO mandou os passos
            return $decision;
        }

        // Se insiste que pagou, o outro guard já trata (comprovante)
        if ($this->visitorClaimsPaid($conv)) {
            return $decision;
        }

        Log::info('🤖 Guard: forçando passo a passo (pedido de informação, sem escalar)', [
            'conversation_id' => $conv->id,
            'was_action' => $action,
        ]);

        return [
            'action' => 'reply',
            'message' => $this->paymentStepsMessage(),
        ];
    }

    /**
     * Se o visitante acabou de dizer iOS/iPhone/Android, obriga request_mac.
     */
    private function guardDeviceAnswerRequestMac(ChatConversation $conv, array $decision): array
    {
        if (!$this->shouldRequestMacAfterDeviceAnswer($conv)) {
            return $decision;
        }

        $device = $this->visitorDeviceFromHistory($conv) ?? 'both';

        Log::info('🤖 Guard: resposta do aparelho — forçando request_mac', [
            'conversation_id' => $conv->id,
            'device' => $device,
            'was_action' => $decision['action'] ?? 'reply',
        ]);

        return [
            'action' => 'request_mac',
            'message' => $this->macInstructionsMessage($device),
        ];
    }

    /**
     * Insiste que não acessa: pergunta iPhone/Android e pede MAC (foto ou código)
     * antes de passar pro humano — o MAC liberado costuma ser outro.
     */
    private function guardAccessIssueAskMac(ChatConversation $conv, array $decision): array
    {
        if (!$this->visitorHasValidPayment($conv)) {
            return $decision;
        }

        $action = $decision['action'] ?? 'reply';

        if ($action === 'request_receipt' || $action === 'request_mac') {
            return $decision;
        }

        if ($this->visitorAskedForHuman($conv)) {
            return $decision;
        }

        if ($this->alreadyCollectedMac($conv)) {
            return $decision;
        }

        // "paguei" sem comprovante NÃO bloqueia o MAC se o pagamento já está ativo
        // ou se ele acabou de dizer iOS/Android (fluxo do aparelho)
        $receiptPending = $this->visitorClaimsPaid($conv)
            && !$this->alreadyUploadedReceipt($conv)
            && !$this->visitorHasActiveAccess($conv)
            && !$this->visitorJustAnsweredDevice($conv)
            && !$this->alreadyAskedDevice($conv);
        if ($receiptPending) {
            return $decision;
        }

        $insists = $this->visitorSaysStepsFailed($conv);
        $alreadyHelped = $this->alreadySentPaymentSteps($conv)
            || $this->visitorHasActiveAccess($conv)
            || $this->alreadyAskedDevice($conv);

        // Pagamento válido já foi confirmado acima: antes de escalar, sempre coleta o MAC.
        $iaTriedToEscalate = in_array($action, ['escalate', 'request_probe'], true);

        if (!(($insists && $alreadyHelped) || $iaTriedToEscalate)) {
            return $decision;
        }

        $next = $this->nextMacCollectionDecision($conv);
        if (!$next) {
            return $decision;
        }

        Log::info('🤖 Guard: pedindo MAC (iPhone/Android) em vez de escalar', [
            'conversation_id' => $conv->id,
            'was_action' => $action,
            'next_action' => $next['action'],
        ]);

        return $next;
    }

    /**
     * Dúvida ("não entendi", "como desligo o 4G") não escala — explica o passo.
     */
    private function guardConfusionWithoutEscalate(ChatConversation $conv, array $decision): array
    {
        if (!$this->visitorNeedsGuidedHelp($conv)) {
            return $decision;
        }

        if ($this->visitorAskedForHuman($conv)) {
            return $decision;
        }

        $action = $decision['action'] ?? 'reply';
        if ($action !== 'escalate' && $action !== 'request_probe') {
            return $decision;
        }

        Log::info('🤖 Guard: dúvida do usuário — explicando em vez de escalar', [
            'conversation_id' => $conv->id,
            'was_action' => $action,
        ]);

        return [
            'action' => 'reply',
            'message' => $this->howToDisableMobileDataMessage(),
        ];
    }

    /**
     * Nunca repetir o mesmo passo a passo quando o usuário já tentou e falhou.
     */
    private function guardNoRepeatPaymentSteps(ChatConversation $conv, array $decision): array
    {
        if (!$this->visitorHasValidPayment($conv)
            && $this->alreadySentPaymentSteps($conv)
            && $this->visitorSaysStepsFailed($conv)) {
            return [
                'action' => 'reply',
                'message' => $this->paymentFollowupMessage(),
            ];
        }

        if ($this->visitorHasActiveAccess($conv)) {
            return $decision;
        }

        if ($this->visitorNeedsGuidedHelp($conv)) {
            return $decision;
        }

        if (!$this->alreadySentPaymentSteps($conv)) {
            return $decision;
        }

        if (!$this->visitorSaysStepsFailed($conv)) {
            return $decision;
        }

        // Insistiu que pagou → comprovante tem prioridade
        if ($this->visitorClaimsPaid($conv)) {
            $alreadyUploaded = ChatMessage::where('conversation_id', $conv->id)
                ->where('type', 'receipt_upload')
                ->where('created_at', '>=', now()->subHours(6))
                ->exists();

            if (!$alreadyUploaded) {
                return [
                    'action' => 'request_receipt',
                    'message' => 'Pra eu localizar seu pagamento, me manda o comprovante do PIX (foto ou print). Use o botão abaixo pra enviar.',
                ];
            }
        }

        if (in_array($decision['action'] ?? 'reply', ['request_mac', 'request_receipt'], true)) {
            return $decision;
        }

        if (!$this->alreadyCollectedMac($conv)) {
            $next = $this->nextMacCollectionDecision($conv);
            if ($next) {
                Log::info('🤖 Guard: passos falharam — pedindo MAC antes de escalar', [
                    'conversation_id' => $conv->id,
                    'was_action' => $decision['action'] ?? 'reply',
                ]);
                return $next;
            }
        }

        $action = $decision['action'] ?? 'reply';
        $msg = mb_strtolower((string) ($decision['message'] ?? ''));
        $looksLikeSteps = str_contains($msg, '1)') && (
            str_contains($msg, 'esquece a rede')
            || str_contains($msg, 'tocantinstransportewifi')
            || str_contains($msg, 'passo')
        );

        if ($action === 'escalate' && !$looksLikeSteps) {
            return $decision;
        }

        Log::info('🤖 Guard: passos já falharam e MAC já coletado — escalando', [
            'conversation_id' => $conv->id,
            'was_action' => $action,
        ]);

        return [
            'action' => 'escalate',
            'message' => 'Poxa, desculpa que não resolveu por aqui. Vou passar pro meu colega que consegue te ajudar direto. Aguarda só um minutinho!',
            'reason' => 'passo a passo já enviado e usuário disse que não funcionou',
        ];
    }

    private function fallbackDecision(ChatConversation $conv, ?string $reason = null): array
    {
        if ($this->visitorAskedForHuman($conv)) {
            return [
                'action' => 'escalate',
                'message' => 'Claro! Vou passar pro meu colega. Aguarda só um minutinho!',
                'reason' => $reason ?? 'pediu atendente',
            ];
        }

        if ($this->shouldRequestMacAfterDeviceAnswer($conv)) {
            $device = $this->visitorDeviceFromHistory($conv) ?? 'both';
            return [
                'action' => 'request_mac',
                'message' => $this->macInstructionsMessage($device),
            ];
        }

        if ($this->visitorDeniesPayment($conv) && !$this->visitorHasActiveAccess($conv)) {
            return [
                'action' => 'reply',
                'message' => $this->alreadySentPaymentSteps($conv)
                    ? $this->paymentFollowupMessage()
                    : $this->paymentStepsMessage(),
            ];
        }

        if ($this->visitorNeedsGuidedHelp($conv)) {
            return [
                'action' => 'reply',
                'message' => $this->howToDisableMobileDataMessage(),
            ];
        }

        if ($this->alreadyAskedDevice($conv) && !$this->alreadyCollectedMac($conv)) {
            return $this->nextMacCollectionDecision($conv) ?? [
                'action' => 'reply',
                'message' => 'Pra te ajudar no passo certo, você está usando iOS (iPhone) ou Android?',
            ];
        }

        if (!$this->visitorHasActiveAccess($conv)) {
            if ($this->alreadySentPaymentSteps($conv) && $this->visitorSaysStepsFailed($conv) && !$this->visitorNeedsGuidedHelp($conv)) {
                if (!$this->alreadyCollectedMac($conv)) {
                    return $this->nextMacCollectionDecision($conv) ?? [
                        'action' => 'reply',
                        'message' => 'Beleza. Às vezes o WiFi libera outro aparelho. Pra te ajudar, você está usando iOS (iPhone) ou Android?',
                    ];
                }

                return [
                    'action' => 'escalate',
                    'message' => 'Poxa, desculpa que não resolveu por aqui. Vou passar pro meu colega que consegue te ajudar direto. Aguarda só um minutinho!',
                    'reason' => $reason ?? 'passos falharam / fallback',
                ];
            }

            if (!$this->alreadySentPaymentSteps($conv) && !$this->visitorClaimsPaid($conv)) {
                return [
                    'action' => 'reply',
                    'message' => $this->paymentStepsMessage(),
                ];
            }
        }

        return [
            'action' => 'escalate',
            'message' => 'Vou passar pro meu colega que consegue te ajudar melhor. Aguarda só um minutinho!',
            'reason' => $reason ?? 'IA não retornou decisão válida',
        ];
    }

    private function howToDisableMobileDataMessage(): string
    {
        return "Tranquilo! O 4G é a internet do chip. Pra pagar no WiFi do ônibus, ele precisa estar desligado.\n\n"
            . "iPhone: Ajustes → Celular (ou Dados Celulares) → desliga o botão no topo.\n\n"
            . "Android: desliza a tela de cima pra baixo e toca no ícone Dados móveis / 4G até apagar.\n\n"
            . "Depois fica só no WiFi TocantinsTransporteWiFi, abre o navegador em www.tocantinstransportewifi.com.br, escolhe o plano e paga no PIX.\n\n"
            . "Me fala se é iOS (iPhone) ou Android que eu te guiando no detalhe!";
    }

    private function paymentStepsMessage(): string
    {
        return "Vamos liberar seu acesso:\n\n"
            . "1) Conecte na rede TocantinsTransporteWiFi e desligue o 4G/5G.\n\n"
            . "2) Abra www.tocantinstransportewifi.com.br e escolha o plano.\n\n"
            . "3) Gere o PIX e faça o pagamento. A liberação acontece automaticamente em até 15 segundos.\n\n"
            . "A página de pagamento abriu no seu celular?";
    }

    /**
     * Segunda mensagem de ajuda pra pagar — nunca repete o passo a passo igual,
     * pergunta onde travou e já dá o caminho do portal.
     */
    private function paymentFollowupMessage(): string
    {
        return "Certo, vamos por uma etapa de cada vez. Continue conectado na TocantinsTransporteWiFi, desligue o 4G/5G e abra www.tocantinstransportewifi.com.br.\n\n"
            . "Me diga exatamente o que aparece na tela: a página não abre, não gera o PIX ou o banco não abre?";
    }

    private function noActivePaymentMessage(ChatConversation $conv): string
    {
        $name = trim((string) $conv->visitor_name);
        $greeting = $name !== '' ? "Oi, {$name}! " : 'Oi! ';

        return $greeting
            . "Conferi aqui e não encontrei pagamento ativo para este telefone ou aparelho. Para navegar, primeiro é necessário fazer um novo pagamento.\n\n"
            . $this->paymentStepsMessage();
    }

    private function paymentNotFoundAfterClaimMessage(ChatConversation $conv): string
    {
        $name = trim((string) $conv->visitor_name);
        $greeting = $name !== '' ? "Entendi, {$name}. " : 'Entendi. ';

        return $greeting
            . 'Conferi aqui e o pagamento ainda não aparece como ativo para este telefone ou aparelho. '
            . 'Envie o comprovante do PIX pelo botão abaixo para eu localizar a cobrança antes de chamar um atendente.';
    }

    private function bankPaymentBridgeMessage(ChatConversation $conv): string
    {
        $name = trim((string) $conv->visitor_name);
        $greeting = $name !== '' ? "Entendi, {$name}. " : 'Entendi. ';

        return $greeting
            . "Dá para concluir em duas etapas:\n\n"
            . "1) Com o celular conectado na TocantinsTransporteWiFi e o 4G desligado, abra www.tocantinstransportewifi.com.br, escolha o plano e copie o código PIX.\n\n"
            . "2) Sem esquecer a rede, desligue o WiFi por um momento, ligue o 4G e pague no banco. Depois, desligue o 4G e conecte novamente na TocantinsTransporteWiFi.\n\n"
            . 'Você consegue chegar até a tela que gera o código PIX?';
    }

    private function alreadySentPaymentFollowup(ChatConversation $conv): bool
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->whereNull('admin_id')
            ->where('message', 'like', '%Sem problema, vamos pagar agora%')
            ->exists();
    }

    private function alreadySentPaymentSteps(ChatConversation $conv): bool
    {
        $adminMsgs = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->whereNull('admin_id')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('message');

        foreach ($adminMsgs as $msg) {
            $t = mb_strtolower((string) $msg);
            if (
                str_contains($t, '1)')
                && str_contains($t, 'tocantinstransportewifi')
                && (str_contains($t, 'gere o pix') || str_contains($t, 'esquece a rede'))
            ) {
                return true;
            }
        }

        return false;
    }

    private function visitorSaysStepsFailed(ChatConversation $conv): bool
    {
        $last = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'visitor')
            ->orderByDesc('id')
            ->value('message');

        if (!$last) {
            return false;
        }

        $normalized = mb_strtolower($last);

        if ($this->visitorNeedsGuidedHelp($conv)) {
            return false;
        }

        return (bool) preg_match(
            '/(n[aã]o\s*abre|nao\s*abre|n[aã]o\s*funcion|nao\s*funcion|nada\s*deu\s*certo|nada\s*funciona|fiz\s*(isso\s*)?tudo|tentei|n[aã]o\s*deu|nao\s*deu|mesmo\s*assim|continua\s*sem|ainda\s*n[aã]o|ainda\s*nao|sem\s*p[aá]gina|n[aã]o\s*aparec|nao\s*aparec|n[aã]o\s*consig|nao\s*consig|n[aã]o\s*conect|nao\s*conect|n[aã]o\s*libera|nao\s*libera|sem\s*acesso)/u',
            $normalized
        );
    }

    private function visitorNeedsGuidedHelp(ChatConversation $conv): bool
    {
        $last = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'visitor')
            ->orderByDesc('id')
            ->value('message');

        if (!$last) {
            return false;
        }

        $normalized = mb_strtolower($last);

        return (bool) preg_match(
            '/(n[aã]o\s*entendi|nao\s*entendi|como\s*(desligo|desligar|fa[cç]o|pago|abro|entro|continuo|continuar|continar)|me\s*explica|explica\s*(melhor|de\s*novo)|o\s*que\s*[eé]\s*(o\s*)?4g|dados\s*m[oó]veis|desligar\s*(o\s*)?4g)/u',
            $normalized
        );
    }

    private function visitorReportsResolved(ChatConversation $conv): bool
    {
        $last = mb_strtolower(trim((string) $this->lastVisitorMessage($conv)));

        if ((bool) preg_match('/\b(n[aã]o|nao)\s+(deu\s*certo|funcionou|consegui|resolveu)/u', $last)) {
            return false;
        }

        return (bool) preg_match(
            '/\b(deu\s*certo|funcionou|consegui|resolveu|j[aá]\s*est[aá]\s*funcionando|agora\s*foi)\b/u',
            $last
        );
    }

    private function visitorCannotOpenBank(ChatConversation $conv): bool
    {
        $last = mb_strtolower((string) $this->lastVisitorMessage($conv));

        return str_contains($last, 'banco')
            && (bool) preg_match('/(n[aã]o\s*(tenho|consigo|abre|funciona)|nao\s*(tenho|consigo|abre|funciona)|sem\s*internet|sem\s*acesso)/u', $last);
    }

    private function visitorClaimsPaid(ChatConversation $conv): bool
    {
        // Se ele acabou de negar ("não paguei") ou dizer que quer pagar,
        // a afirmação antiga não vale mais — senão a IA fica pedindo comprovante de quem nunca pagou.
        if ($this->visitorDeniesPayment($conv)) {
            return false;
        }

        $texts = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'visitor')
            ->orderByDesc('id')
            ->limit(8)
            ->pluck('message');

        foreach ($texts as $text) {
            $normalized = mb_strtolower((string) $text);
            // Tira os trechos negados pra "não paguei" não contar como "paguei"
            $normalized = preg_replace(self::RE_PAY_DENIAL, ' ', $normalized);

            if (preg_match(self::RE_PAID_CLAIM, $normalized)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Última mensagem do visitante nega o pagamento ou pede pra pagar.
     * Quem diz "não paguei" / "quero pagar" precisa do PASSO A PASSO, nunca de comprovante.
     */
    private function visitorDeniesPayment(ChatConversation $conv): bool
    {
        $last = $this->lastVisitorMessage($conv);
        if (!$last) {
            return false;
        }

        $normalized = mb_strtolower($last);

        return (bool) preg_match(self::RE_PAY_DENIAL, $normalized)
            || (bool) preg_match(self::RE_WANTS_TO_PAY, $normalized);
    }

    private function visitorAskedForHuman(ChatConversation $conv): bool
    {
        $last = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'visitor')
            ->orderByDesc('id')
            ->value('message');

        if (!$last) {
            return false;
        }

        $normalized = mb_strtolower($last);

        return (bool) preg_match(
            '/\b(atendente|humano|pessoa\s*real|falar\s*com\s*(alguém|alguem|atendente)|quero\s*atendente)\b/u',
            $normalized
        );
    }

    /**
     * Pagou, está no prazo e reclama de acesso → manda o botão de recuperação antes de
     * investigar aparelho/MAC. Uma vez por conversa, e nunca no meio do fluxo do MAC:
     * conversa que já estava nele segue como antes.
     */
    private function shouldOfferRecovery(ChatConversation $conv): bool
    {
        if (!$this->visitorHasValidPayment($conv)) {
            return false;
        }

        if ($this->alreadyOfferedRecovery($conv)) {
            return false;
        }

        if ($this->visitorAskedForHuman($conv) || $this->visitorReportsResolved($conv)) {
            return false;
        }

        if ($this->alreadyAskedDevice($conv) || $this->alreadyRequestedMac($conv) || $this->alreadyCollectedMac($conv)) {
            return false;
        }

        return $this->visitorClaimsPaid($conv)
            || $this->visitorSaysStepsFailed($conv)
            || $this->visitorReportsAccessIssue($conv);
    }

    private function alreadyOfferedRecovery(ChatConversation $conv): bool
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'recovery_request')
            ->where('created_at', '>=', now()->subHours(6))
            ->exists();
    }

    /** Última mensagem fala de internet/conexão (ex.: "não tá conectando", "sem internet"). */
    private function visitorReportsAccessIssue(ChatConversation $conv): bool
    {
        $last = mb_strtolower((string) $this->lastVisitorMessage($conv));

        return (bool) preg_match(
            '/\b(internet|wifi|wi-fi|acesso|conect|naveg|rede|liber|sinal|funciona)/u',
            $last
        );
    }

    private function recoveryOfferMessage(ChatConversation $conv): string
    {
        $context = $this->paymentContext($conv);
        $expiresAt = $context['active_access']
            ? $context['user']?->expires_at
            : $context['payment_expires_at'];

        $name = trim((string) $conv->visitor_name);
        $greeting = $name !== '' ? "Oi {$name}! " : 'Oi! ';
        $validity = $expiresAt
            ? ', com acesso válido até ' . $expiresAt->format('d/m') . ' às ' . $expiresAt->format('H:i')
            : '';

        return $greeting . "Vi aqui que seu pagamento está ativo{$validity}.\n\n"
            . 'Para reativar seu acesso, toque no botão abaixo e informe o telefone que você usou para pagar. '
            . 'A liberação acontece em até 30 segundos.';
    }

    /**
     * Acabou de responder iOS/iPhone/Android e ainda não pedimos/recebemos o MAC.
     */
    private function shouldRequestMacAfterDeviceAnswer(ChatConversation $conv): bool
    {
        if (!$this->visitorHasValidPayment($conv)) {
            return false;
        }

        if ($this->alreadyCollectedMac($conv) || $this->alreadyRequestedMac($conv)) {
            return false;
        }

        if ($this->visitorAskedForHuman($conv)) {
            return false;
        }

        return $this->visitorJustAnsweredDevice($conv);
    }

    private function visitorJustAnsweredDevice(ChatConversation $conv): bool
    {
        $last = mb_strtolower(trim((string) $this->lastVisitorMessage($conv)));
        if ($last === '') {
            return false;
        }

        $mentionsDevice = (bool) preg_match(
            '/\b(iphone|ios|android|samsung|xiaomi|motorola|moto|redmi|galaxy)\b/u',
            $last
        );

        if (!$mentionsDevice) {
            return false;
        }

        if ($this->alreadyAskedDevice($conv)) {
            return true;
        }

        // Resposta curta do tipo "é iphone" / "android" mesmo se a pergunta não casou 100%
        return mb_strlen($last) <= 80;
    }

    /**
     * Próximo passo da coleta de MAC: perguntar aparelho, pedir foto, ou lembrar o botão.
     */
    private function nextMacCollectionDecision(ChatConversation $conv): ?array
    {
        if (!$this->visitorHasValidPayment($conv)) {
            return null;
        }

        if ($this->alreadyCollectedMac($conv)) {
            return null;
        }

        $device = $this->visitorDeviceFromHistory($conv);

        if ($device === null) {
            return [
                'action' => 'reply',
                'message' => 'Beleza. Às vezes o WiFi libera outro aparelho. Pra te ajudar no passo certo, você está usando iOS (iPhone) ou Android?',
            ];
        }

        if ($this->alreadyRequestedMac($conv)) {
            return [
                'action' => 'reply',
                'message' => 'O botão pra enviar a foto do MAC já está na conversa acima. Manda a foto da tela ou escreve o código (tipo AA:BB:CC:DD:EE:FF) que eu passo pro meu colega liberar.',
            ];
        }

        return [
            'action' => 'request_mac',
            'message' => $this->macInstructionsMessage($device),
        ];
    }

    private function macInstructionsMessage(string $device): string
    {
        if ($device === 'ios') {
            return "Beleza! Às vezes o pagamento libera outro aparelho. Preciso do MAC da rede deste iPhone.\n\n"
                . "1) Abre Ajustes → Wi-Fi\n\n"
                . "2) Toca no (i) azul ao lado de TocantinsTransporteWiFi\n\n"
                . "3) Procura Endereço Wi-Fi ou Endereço MAC\n\n"
                . "Manda uma foto dessa tela pelo botão abaixo, ou escreve o código aqui (tipo AA:BB:CC:DD:EE:FF).";
        }

        if ($device === 'android') {
            return "Beleza! Às vezes o pagamento libera outro aparelho. Preciso do MAC da rede deste Android.\n\n"
                . "1) Abre Configurações → Wi-Fi\n\n"
                . "2) Toca na rede TocantinsTransporteWiFi (ou em detalhes / i)\n\n"
                . "3) Procura Endereço MAC ou MAC do dispositivo\n\n"
                . "Manda uma foto dessa tela pelo botão abaixo, ou escreve o código aqui (tipo AA:BB:CC:DD:EE:FF).";
        }

        return "Beleza! Às vezes o pagamento libera outro aparelho. Preciso do MAC da rede.\n\n"
            . "iPhone: Ajustes → Wi-Fi → toca no (i) azul ao lado de TocantinsTransporteWiFi → Endereço Wi-Fi / MAC.\n\n"
            . "Android: Configurações → Wi-Fi → toca na rede TocantinsTransporteWiFi (ou detalhes) → Endereço MAC.\n\n"
            . "Manda uma foto dessa tela pelo botão abaixo, ou escreve o código aqui (tipo AA:BB:CC:DD:EE:FF).";
    }

    /**
     * ios | android | both | null
     */
    private function visitorDeviceFromHistory(ChatConversation $conv): ?string
    {
        $texts = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'visitor')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('message')
            ->implode(' ');

        $normalized = mb_strtolower($texts);
        $ios = (bool) preg_match('/\b(iphone|ios|apple)\b/u', $normalized);
        $android = (bool) preg_match('/\b(android|samsung|xiaomi|motorola|moto|redmi|galaxy)\b/u', $normalized);

        if ($ios && !$android) {
            return 'ios';
        }
        if ($android && !$ios) {
            return 'android';
        }

        $last = mb_strtolower((string) $this->lastVisitorMessage($conv));
        if ($this->alreadyAskedDevice($conv) && preg_match('/n[aã]o\s*sei|nao\s*sei/u', $last)) {
            return 'both';
        }

        return null;
    }

    private function alreadyAskedDevice(ChatConversation $conv): bool
    {
        $adminMsgs = ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'admin')
            ->orderByDesc('id')
            ->limit(12)
            ->pluck('message');

        foreach ($adminMsgs as $msg) {
            $t = mb_strtolower((string) $msg);
            if (preg_match('/(ios|iphone).{0,40}(android)|(android).{0,40}(ios|iphone)/u', $t)) {
                return true;
            }
        }

        return false;
    }

    private function alreadyRequestedMac(ChatConversation $conv): bool
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'mac_request')
            ->where('created_at', '>=', now()->subHours(2))
            ->exists();
    }

    private function alreadyUploadedReceipt(ChatConversation $conv): bool
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('type', 'receipt_upload')
            ->where('created_at', '>=', now()->subHours(6))
            ->exists();
    }

    private function alreadyCollectedMac(ChatConversation $conv): bool
    {
        $uploaded = ChatMessage::where('conversation_id', $conv->id)
            ->whereIn('type', ['mac_upload', 'mac_text'])
            ->where('created_at', '>=', now()->subHours(6))
            ->exists();

        return $uploaded || $this->visitorTypedMacAfterRequest($conv);
    }

    private function collectedMacAddress(ChatConversation $conv): ?string
    {
        $typed = self::extractMacAddress($this->lastVisitorMessage($conv));
        if ($typed) {
            return $typed;
        }

        $msg = ChatMessage::where('conversation_id', $conv->id)
            ->whereIn('type', ['mac_upload', 'mac_text'])
            ->orderByDesc('id')
            ->first();

        return $msg->metadata['mac_address'] ?? null;
    }

    private function visitorTypedMacAfterRequest(ChatConversation $conv): bool
    {
        if (!$this->alreadyRequestedMac($conv)) {
            return false;
        }

        return self::extractMacAddress($this->lastVisitorMessage($conv)) !== null;
    }

    private function lastVisitorMessage(ChatConversation $conv): ?string
    {
        return ChatMessage::where('conversation_id', $conv->id)
            ->where('sender_type', 'visitor')
            ->orderByDesc('id')
            ->value('message');
    }

    public static function extractMacAddress(?string $text): ?string
    {
        if (!$text) {
            return null;
        }

        if (preg_match('/\b(?:[0-9A-Fa-f]{2}[:\-\.]){5}[0-9A-Fa-f]{2}\b/', $text, $m)) {
            $raw = preg_replace('/[^0-9A-Fa-f]/', '', $m[0]);
            return strtoupper(implode(':', str_split($raw, 2)));
        }

        if (preg_match('/\b[0-9A-Fa-f]{12}\b/', $text, $m)) {
            return strtoupper(implode(':', str_split($m[0], 2)));
        }

        return null;
    }
}
