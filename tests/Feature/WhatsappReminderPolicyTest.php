<?php

namespace Tests\Feature;

use App\Console\Commands\SendUnpaidPaymentReminders;
use App\Http\Controllers\Admin\WhatsappController;
use App\Models\Payment;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Services\WhatsappSendPolicy;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Tests\Support\IntervalTestDatabase;

class WhatsappReminderPolicyTest extends TestCase
{
    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        IntervalTestDatabase::create();
        Schema::table('users', function (Blueprint $t) {
            $t->dateTime('whatsapp_payment_opt_in_at')->nullable();
            $t->string('whatsapp_payment_opt_in_phone')->nullable();
            $t->string('whatsapp_payment_opt_in_source')->nullable();
        });
        Schema::table('payments', fn (Blueprint $t) => $t->dateTime('unpaid_reminder_sent_at')->nullable());
        Schema::create('whatsapp_settings', function (Blueprint $t) {
            $t->id(); $t->string('key')->unique(); $t->text('value')->nullable(); $t->timestamps();
        });
        Schema::create('whatsapp_messages', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->nullable(); $t->foreignId('payment_id')->nullable();
            $t->string('phone'); $t->text('message'); $t->string('status');
            $t->text('error_message')->nullable(); $t->string('message_id')->nullable();
            $t->dateTime('sent_at')->nullable(); $t->dateTime('delivered_at')->nullable();
            $t->dateTime('read_at')->nullable(); $t->timestamps();
        });
        Schema::create('whatsapp_opt_outs', function (Blueprint $t) {
            $t->id(); $t->string('phone'); $t->string('phone_last8'); $t->string('source')->nullable();
            $t->string('keyword')->nullable(); $t->dateTime('opted_out_at')->nullable(); $t->timestamps();
        });
        (new \ReflectionProperty(\App\Models\SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        config(['app.timezone' => 'America/Araguaina']);
        \App\Models\SystemSetting::setValue('unpaid_reminder_enabled', '1');
        WhatsappSetting::set('is_connected', 'true');
        WhatsappSetting::set('auto_send_enabled', 'true');
        // Código PIX no lembrete desligado por padrão aqui; os testes que o
        // exercitam ligam com fakePagBank().
        WhatsappSetting::set('reminder_pix_code_enabled', 'false');
        Http::preventStrayRequests();
        Http::fake(['*/send' => Http::response(['messageId' => 'MSG'], 200)]);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** PIX pendente de quem autorizou receber o lembrete, criado 20 min antes de agora. */
    private function unpaid(int $n): Payment
    {
        $phone = '6398101'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
        $user = User::create(['name' => "Passageiro {$n}", 'phone' => $phone,
            'mac_address' => sprintf('D6:DE:C4:66:F2:%02X', $n), 'status' => 'pending',
            'whatsapp_payment_opt_in_at' => now(), 'whatsapp_payment_opt_in_phone' => $phone]);
        $payment = Payment::create(['user_id' => $user->id, 'amount' => 6.99, 'payment_type' => 'pix', 'status' => 'pending']);
        $payment->forceFill(['created_at' => now()->subMinutes(20)])->saveQuietly();
        return $payment;
    }

    private function runReminders(): void
    {
        Artisan::call('payments:send-unpaid-reminders');
    }

    public function test_quiet_hours_send_nothing_and_keep_payment_eligible_for_the_morning(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 23:30:00', 'America/Araguaina'));
        $payment = $this->unpaid(1);
        $this->runReminders();
        $this->assertSame(0, WhatsappMessage::count());
        $this->assertNull($payment->fresh()->unpaid_reminder_sent_at);
        Http::assertNothingSent();

        Carbon::setTestNow(Carbon::parse('2026-09-11 07:05:00', 'America/Araguaina'));
        $morning = $this->unpaid(2);
        $this->runReminders();
        $this->assertSame(1, WhatsappMessage::where('payment_id', $morning->id)->count());
    }

    public function test_quiet_window_can_cross_midnight_or_be_disabled(): void
    {
        $at = fn (string $time) => Carbon::parse("2026-09-10 {$time}", 'America/Araguaina');
        $this->assertTrue(WhatsappSendPolicy::isQuietTime($at('22:00')));
        $this->assertTrue(WhatsappSendPolicy::isQuietTime($at('03:00')));
        $this->assertFalse(WhatsappSendPolicy::isQuietTime($at('07:00')));
        $this->assertFalse(WhatsappSendPolicy::isQuietTime($at('19:30')));
        WhatsappSetting::set('quiet_start_hour', 0);
        WhatsappSetting::set('quiet_end_hour', 0);
        $this->assertFalse(WhatsappSendPolicy::isQuietTime($at('03:00')));
    }

    public function test_daily_cap_stops_sending_and_leaves_the_rest_unmarked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        WhatsappSetting::set('daily_reminder_cap', 2);
        $payments = collect(range(1, 4))->map(fn ($n) => $this->unpaid($n));
        $this->runReminders();
        $this->assertSame(2, WhatsappMessage::count());
        $this->assertSame(2, WhatsappSendPolicy::remindersSentToday());
        $this->assertSame(2, $payments->filter(fn ($p) => $p->fresh()->unpaid_reminder_sent_at === null)->count());

        // Manual também respeita o teto.
        $response = app(WhatsappController::class)->sendToPendingPayments();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(2, WhatsappMessage::count());

        // Dia seguinte: o contador zera.
        Carbon::setTestNow(Carbon::parse('2026-09-11 08:00:00', 'America/Araguaina'));
        $this->assertSame(2, WhatsappSendPolicy::remainingToday());
    }

    public function test_reminders_pause_randomly_between_sends_and_rotate_texts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        foreach (range(1, 4) as $n) {
            $this->unpaid($n);
        }
        $this->runReminders();
        $messages = WhatsappMessage::pluck('message');
        $this->assertCount(4, $messages);
        $this->assertCount(4, $messages->unique(), 'Os 4 lembretes deveriam ter textos diferentes.');
        foreach ($messages as $text) {
            $this->assertStringContainsString('R$ 6,99', $text);
            $this->assertStringContainsString('PARAR', $text);
            $this->assertMatchesRegularExpression('/[Rr]esponda/', $text);
        }
        // 4 envios = 3 pausas, cada uma entre 3 e 8 segundos.
        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(fn ($duration) => $duration->totalSeconds >= 3 && $duration->totalSeconds <= 8, 3);
    }

    public function test_manual_send_is_blocked_during_quiet_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 02:00:00', 'America/Araguaina'));
        $this->unpaid(1);
        $response = app(WhatsappController::class)->sendToPendingPayments();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('22h às 07h', $response->getData(true)['error']);
        $this->assertSame(0, WhatsappMessage::count());
    }

    private function manualSend(Payment $payment): \Illuminate\Http\JsonResponse
    {
        return app(WhatsappController::class)->sendMessage(\Illuminate\Http\Request::create('/admin/whatsapp/send', 'POST', [
            'phone' => $payment->user->phone, 'user_id' => $payment->user_id, 'payment_id' => $payment->id,
        ]));
    }

    public function test_manual_button_sends_the_automatic_text_once_and_cron_does_not_repeat_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        $payment = $this->unpaid(1);

        $this->assertSame(200, $this->manualSend($payment)->getStatusCode());
        $message = WhatsappMessage::sole();
        $this->assertSame(SendUnpaidPaymentReminders::buildReminderMessage($payment->user, $payment), $message->message);
        $this->assertStringContainsString('PARAR', $message->message);
        $this->assertStringNotContainsString('5,99', $message->message);
        $this->assertSame(1, WhatsappSendPolicy::remindersSentToday());
        $this->assertNotNull($payment->fresh()->unpaid_reminder_sent_at);

        // Clicar de novo não manda segunda mensagem, e o automático também não.
        $this->assertSame(422, $this->manualSend($payment)->getStatusCode());
        $this->runReminders();
        $this->assertSame(1, WhatsappMessage::count());
    }

    public function test_manual_button_respects_quiet_hours_and_daily_cap(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 02:00:00', 'America/Araguaina'));
        $payment = $this->unpaid(1);
        $this->assertSame(422, $this->manualSend($payment)->getStatusCode());

        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        WhatsappSetting::set('daily_reminder_cap', 1);
        $this->assertSame(200, $this->manualSend($this->unpaid(2))->getStatusCode());
        $this->assertSame(422, $this->manualSend($this->unpaid(3))->getStatusCode());
        $this->assertSame(1, WhatsappMessage::count());
        Http::assertSentCount(1);
    }

    public function test_command_records_last_run_and_panel_counts_sales_after_reminder(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        $payment = $this->unpaid(1);
        $this->unpaid(2);
        $this->runReminders();
        $this->assertSame('2026-09-10 19:30:00', WhatsappSetting::get('reminder_last_run_at'));
        $this->assertStringContainsString('2 enviados', WhatsappSetting::get('reminder_last_run_result'));

        // Passageiro 1 gera outro PIX e paga 40 min depois do lembrete.
        Carbon::setTestNow(Carbon::parse('2026-09-10 20:10:00', 'America/Araguaina'));
        Payment::create(['user_id' => $payment->user_id, 'amount' => 6.99, 'payment_type' => 'pix',
            'status' => 'completed', 'paid_at' => now()]);

        $insights = app(WhatsappController::class)->index()->getData()['insights'];
        $this->assertTrue($insights['ok']);
        $this->assertSame(2, $insights['sent']);
        $this->assertSame(1, $insights['converted']);
        $this->assertEqualsWithDelta(6.99, $insights['revenue'], 0.001);
        $this->assertTrue($insights['run_is_stale'], '40 min sem rodar deve acender o alerta.');

        $this->runReminders();
        $this->assertFalse(app(WhatsappController::class)->index()->getData()['insights']['run_is_stale']);
    }

    /** Liga o código PIX no lembrete e troca o PagBank por um falso (nenhuma chamada real). */
    private function fakePagBank(bool $works = true): void
    {
        WhatsappSetting::set('reminder_pix_code_enabled', 'true');
        \App\Models\SystemSetting::setValue('pix_gateway', 'pagbank');
        $this->app->bind(\App\Services\PagBankPixService::class, fn () => new class($works)
        {
            public function __construct(private bool $works)
            {
            }

            public function createPixPayment(float $amount, string $description, ?string $referenceId = null): array
            {
                return $this->works
                    ? ['success' => true, 'order_id' => 'ORDE_'.$referenceId, 'reference_id' => $referenceId,
                        'qr_code_text' => '00020126580014br.gov.bcb.pix'.$referenceId, 'amount' => $amount]
                    : ['success' => false, 'message' => 'PagBank fora do ar'];
            }
        });
    }

    public function test_reminder_carries_a_fresh_pix_charge_and_never_reminds_about_it(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        $this->fakePagBank();
        $payment = $this->unpaid(1);
        $payment->update(['payment_data' => ['plan_name' => 'Viagem completa', 'duration_hours' => 12]]);

        $this->runReminders();

        // Cobrança nova: mesmo passageiro, valor e plano; achável pelo webhook.
        $fresh = Payment::where('id', '!=', $payment->id)->sole();
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($payment->user_id, $fresh->user_id);
        $this->assertEqualsWithDelta(6.99, (float) $fresh->amount, 0.001);
        $this->assertSame(12, $fresh->payment_data['duration_hours']);
        $this->assertSame('whatsapp_reminder', $fresh->payment_data['source']);
        $this->assertSame($payment->id, $fresh->payment_data['reminder_of_payment_id']);
        $this->assertSame('ORDE_'.$fresh->transaction_id, $fresh->gateway_payment_id);
        $this->assertSame($fresh->transaction_id, $fresh->pix_location);
        $this->assertNotNull($fresh->unpaid_reminder_sent_at);
        $this->assertNotNull($payment->fresh()->unpaid_reminder_sent_at);

        // Duas mensagens: aviso e, depois, só o código.
        $messages = WhatsappMessage::orderBy('id')->get();
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('R$ 6,99', $messages[0]->message);
        $this->assertStringContainsString('PARAR', $messages[0]->message);
        $this->assertMatchesRegularExpression('/copi/i', $messages[0]->message);
        $this->assertSame($fresh->pix_emv_string, $messages[1]->message);
        $this->assertSame($fresh->id, $messages[1]->payment_id);
        $this->assertSame('sent', $messages[1]->status);

        // Conta como UM lembrete no teto diário e no painel.
        $this->assertSame(1, WhatsappSendPolicy::remindersSentToday());
        $this->assertSame(1, WhatsappMessage::reminders()->count());

        // A cobrança do lembrete nunca vira alvo de outro lembrete.
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:55:00', 'America/Araguaina'));
        $this->runReminders();
        $this->assertSame(2, WhatsappMessage::count());
        $this->assertSame(2, Payment::count());
    }

    public function test_reminder_falls_back_to_portal_text_when_charge_cannot_be_created(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        $this->fakePagBank(works: false);
        $payment = $this->unpaid(1);

        $this->runReminders();

        $message = WhatsappMessage::sole();
        $this->assertSame(SendUnpaidPaymentReminders::buildReminderMessage($payment->user, $payment), $message->message);
        $this->assertSame('sent', $message->status);
        $this->assertSame(1, Payment::count(), 'A cobrança que falhou não pode sobrar como pendente.');
        $this->assertNotNull($payment->fresh()->unpaid_reminder_sent_at);
    }

    public function test_no_fresh_charge_for_multi_day_plans_or_when_whatsapp_send_fails(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        $this->fakePagBank();

        $interval = $this->unpaid(1);
        $interval->update(['payment_data' => ['plan_type' => 'interval']]);
        $this->runReminders();
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, WhatsappMessage::count());

        // WhatsApp fora do ar: o aviso falha, o código não sai e a cobrança nova some.
        // (fábrica nova: um segundo Http::fake não substituiria o do setUp)
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['*/send' => Http::response(['error' => 'offline'], 500)]);
        $second = $this->unpaid(2);
        $this->runReminders();
        $this->assertSame(2, Payment::count());
        $this->assertSame('failed', WhatsappMessage::where('payment_id', $second->id)->sole()->status);
    }

    public function test_reminders_still_require_opt_in(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 19:30:00', 'America/Araguaina'));
        $payment = $this->unpaid(1);
        $payment->user->update(['whatsapp_payment_opt_in_at' => null]);
        $this->runReminders();
        $this->assertSame(0, WhatsappMessage::count());
        $this->assertSame(0, WhatsappSendPolicy::remindersSentToday());
    }
}
