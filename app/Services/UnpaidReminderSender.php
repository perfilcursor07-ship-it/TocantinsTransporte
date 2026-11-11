<?php

namespace App\Services;

use App\Console\Commands\SendUnpaidPaymentReminders;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Log;

/**
 * Envia o lembrete de PIX não pago a UM passageiro.
 *
 * É o único lugar que monta e dispara o lembrete: o comando automático e os
 * botões de /admin/whatsapp passam por aqui, então o passageiro recebe sempre
 * a mesma coisa. Quem chama continua responsável pelas regras de QUEM pode
 * receber (autorização, PARAR, horário de silêncio, teto diário, 24h).
 *
 * Com o código PIX ligado, são duas mensagens: o aviso e, em seguida, só o
 * código copia-e-cola de uma cobrança nova. Se a cobrança não puder ser
 * gerada, sai o aviso antigo (uma mensagem, com o link do portal).
 */
class UnpaidReminderSender
{
    public function __construct(private ReminderPixService $pix)
    {
    }

    /** O lembrete deve levar um código PIX novo? (ligado por padrão) */
    public static function pixCodeEnabled(): bool
    {
        return WhatsappSetting::get('reminder_pix_code_enabled', 'true') === 'true';
    }

    /**
     * @return array{ok:bool,with_code:bool,error:?string,message:?WhatsappMessage}
     */
    public function send(User $user, Payment $payment, int $timeout = 15): array
    {
        $phone = WhatsappMessage::formatPhone($user->phone);
        $fresh = self::pixCodeEnabled() ? $this->pix->createFreshCharge($payment) : null;

        $text = $fresh
            ? self::textWithCode($user, $payment)
            : SendUnpaidPaymentReminders::buildReminderMessage($user, $payment);

        $notice = WhatsappMessage::create([
            'user_id' => $user->id,
            'payment_id' => $payment->id,
            'phone' => $phone,
            'message' => $text,
            'status' => 'pending',
        ]);

        $error = $this->deliver($notice, $phone, $text, [], $timeout);
        if ($error !== null) {
            // O aviso não saiu: o código também não vai, então a cobrança nova some.
            $this->pix->discard($fresh);

            return ['ok' => false, 'with_code' => false, 'error' => $error, 'message' => $notice];
        }

        if ($fresh) {
            $this->sendCode($user, $fresh, $phone, $timeout);
        }

        return ['ok' => true, 'with_code' => (bool) $fresh, 'error' => null, 'message' => $notice];
    }

    /**
     * Segunda mensagem: só o código, para copiar com um toque. O aviso já
     * prometeu o código, então uma falha aqui ganha uma segunda tentativa.
     */
    private function sendCode(User $user, Payment $fresh, string $phone, int $timeout): void
    {
        $code = (string) $fresh->pix_emv_string;

        $message = WhatsappMessage::create([
            'user_id' => $user->id,
            'payment_id' => $fresh->id,
            'phone' => $phone,
            'message' => $code,
            'status' => 'pending',
        ]);

        // Pausa curta para o código chegar depois do aviso. O número acabou de
        // ser conferido no aviso, então não precisa sondar de novo.
        WhatsappSendPolicy::pauseBetweenSends(2, 4);
        $error = $this->deliver($message, $phone, $code, ['skipCheck' => true], $timeout);

        if ($error !== null) {
            WhatsappSendPolicy::pauseBetweenSends(3, 5);
            $error = $this->deliver($message, $phone, $code, ['skipCheck' => true], $timeout);
        }

        if ($error !== null) {
            Log::warning('Lembrete saiu, mas o código PIX não foi entregue', [
                'payment_id' => $fresh->id,
                'user_id' => $user->id,
                'erro' => $error,
            ]);
        }
    }

    /** Envia e atualiza o registro. Devolve null se deu certo, ou o erro. */
    private function deliver(WhatsappMessage $record, string $phone, string $text, array $options, int $timeout): ?string
    {
        try {
            $response = WhatsappClient::send($phone, $text, $options, $timeout);

            if ($response->successful()) {
                $record->markAsSent($response->json('messageId'));

                return null;
            }

            $record->markAsFailed($response->body());

            return 'Falha ao enviar mensagem';
        } catch (\Throwable $e) {
            $record->markAsFailed($e->getMessage());

            return $e->getMessage();
        }
    }

    public static function textWithCode(User $user, Payment $payment): string
    {
        $name = $user->name ? trim(explode(' ', $user->name)[0]) : null;
        $greeting = $name ? "Oi {$name}!" : 'Oi!';
        $amount = number_format((float) $payment->amount, 2, ',', '.');

        return self::reminderTextWithCode($payment, $greeting, $amount);
    }

    /**
     * 🛡️ Mesma ideia dos textos sem código: 4 versões com o mesmo conteúdo
     * (valor, onde colar o código, "se já pagou, ignore", convite para
     * responder e PARAR), para não repetir um texto idêntico em vários números.
     */
    public static function reminderTextWithCode(Payment $payment, string $greeting, string $amount): string
    {
        $variants = [
            "{$greeting}\n\n"
                . "Você autorizou receber atualizações deste pagamento. O PIX de *R\$ {$amount}* do WiFi do ônibus ainda não foi confirmado.\n\n"
                . "Gerei um código novo para facilitar: *copie a próxima mensagem inteira* e cole no app do seu banco, em PIX Copia e Cola. A internet libera sozinha assim que o pagamento cair.\n\n"
                . "Se você já pagou, ignore esta mensagem.\n\n"
                . "Teve algum problema? Responda aqui.\n\n"
                . "_Para parar estas mensagens, responda *PARAR*._",
            "{$greeting} Aqui é o WiFi do ônibus da Tocantins Transporte.\n\n"
                . "Seu PIX de *R\$ {$amount}* não foi concluído, então a internet ainda não foi liberada.\n\n"
                . "Para pagar agora, *copie o código da próxima mensagem* e cole no banco (PIX Copia e Cola). Pagou, a liberação é automática.\n\n"
                . "Já pagou? Pode ignorar.\n\n"
                . "Se algo deu errado, me responda que eu ajudo.\n\n"
                . "_Não quer mais receber? Responda *PARAR*._",
            "{$greeting}\n\n"
                . "Vimos que o pagamento de *R\$ {$amount}* do WiFi não chegou a ser confirmado. Como você pediu aviso, estamos lembrando.\n\n"
                . "Ainda dá tempo: a próxima mensagem traz um *código PIX novo*. É só copiar e colar no app do banco que a internet é liberada na hora.\n\n"
                . "Se já pagou, desconsidere.\n\n"
                . "Dúvida ou problema? Responda esta mensagem.\n\n"
                . "_Para não receber mais, responda *PARAR*._",
            "{$greeting}\n\n"
                . "Seu acesso ao WiFi do ônibus ficou pendente: o PIX de *R\$ {$amount}* não foi pago.\n\n"
                . "Quer navegar? Mandei um *PIX novo na mensagem abaixo*: copie o código inteiro e cole no seu banco. A liberação é automática depois do pagamento.\n\n"
                . "Caso já tenha pago, é só ignorar.\n\n"
                . "Precisa de ajuda? Responda aqui.\n\n"
                . "_Responda *PARAR* para não receber mais._",
        ];

        return $variants[$payment->id % count($variants)];
    }
}
