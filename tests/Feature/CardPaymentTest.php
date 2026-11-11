<?php

namespace Tests\Feature;

use App\Http\Controllers\CardPaymentController;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\IntervalTestDatabase;

class CardPaymentTest extends TestCase
{
    private const CPF = '529.982.247-25'; // CPF de exemplo com dígitos válidos

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
        (new \ReflectionProperty(SystemSetting::class, 'runtimeCache'))->setValue(null, []);
        Carbon::setTestNow('2026-11-11 10:00:00');
        SystemSetting::setValue('pagbank_token', 'token-de-teste');
        Cache::forget('pagbank_token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pendingPayment(): Payment
    {
        $user = User::create(['mac_address' => '02:12:34:56:78:90', 'ip_address' => '10.5.50.10', 'phone' => '63999990000']);

        return Payment::create([
            'user_id' => $user->id,
            'amount' => 7.99,
            'payment_type' => 'pix',
            'status' => 'pending',
            'transaction_id' => 'TXN_TESTE_CARTAO',
            'payment_data' => ['plan_name' => 'Viagem completa', 'duration_hours' => 12],
        ]);
    }

    private function fakePagBank(string $chargeStatus): void
    {
        Http::fake([
            '*pagseguro.com/orders' => Http::response([
                'id' => 'ORDE_CARTAO_1',
                'reference_id' => 'TXN_TESTE_CARTAO',
                'charges' => [[
                    'id' => 'CHAR_1',
                    'status' => $chargeStatus,
                    'paid_at' => $chargeStatus === 'PAID' ? '2026-11-11T10:00:01-03:00' : null,
                    'payment_response' => ['code' => $chargeStatus === 'PAID' ? '20000' : '10002', 'message' => 'X'],
                    'payment_method' => ['type' => 'CREDIT_CARD', 'card' => ['brand' => 'visa', 'last_digits' => '1111']],
                ]],
            ], 201),
            '*' => Http::response([], 200),
        ]);
    }

    private function charge(Payment $payment, array $override = [])
    {
        return app(CardPaymentController::class)->charge(Request::create('/', 'POST', array_merge([
            'payment_id' => $payment->id,
            'encrypted_card' => str_repeat('A', 120),
            'holder_name' => 'Maria Teste',
            'cpf' => self::CPF,
        ], $override)));
    }

    public function test_card_is_refused_while_only_pix_is_enabled(): void
    {
        Http::fake();
        $payment = $this->pendingPayment();

        $response = $this->charge($payment);

        $this->assertSame(403, $response->getStatusCode());
        Http::assertNothingSent();
        $this->assertSame('pending', $payment->fresh()->status);
    }

    public function test_approved_card_completes_payment_and_releases_user(): void
    {
        SystemSetting::setValue('payment_methods', 'both');
        $this->fakePagBank('PAID');
        $payment = $this->pendingPayment();

        $response = $this->charge($payment);

        $this->assertSame(200, $response->getStatusCode(), $response->getContent());
        $this->assertSame('PAID', $response->getData(true)['status']);

        $fresh = $payment->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame('card', $fresh->payment_type);
        $this->assertSame('ORDE_CARTAO_1', data_get($fresh->payment_data, 'card_order_id'));
        $this->assertSame(12, (int) data_get($fresh->payment_data, 'duration_hours'));

        $user = $fresh->user->fresh();
        $this->assertSame('connected', $user->status);
        $this->assertTrue($user->expires_at->isFuture());

        // Valor e referência saem do banco, não do navegador.
        Http::assertSent(function ($request) {
            if (! str_ends_with($request->url(), '/orders')) {
                return false;
            }

            return $request['reference_id'] === 'TXN_TESTE_CARTAO'
                && $request['charges'][0]['amount']['value'] === 799
                && $request['charges'][0]['payment_method']['type'] === 'CREDIT_CARD'
                && $request['customer']['tax_id'] === '52998224725';
        });
    }

    public function test_declined_card_keeps_payment_pending_for_pix(): void
    {
        SystemSetting::setValue('payment_methods', 'both');
        $this->fakePagBank('DECLINED');
        $payment = $this->pendingPayment();

        $response = $this->charge($payment);

        $this->assertSame(402, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['declined']);
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertSame('pix', $payment->fresh()->payment_type);
        $this->assertNotSame('connected', $payment->user->fresh()->status);
    }

    public function test_attempts_are_limited_per_payment(): void
    {
        SystemSetting::setValue('payment_methods', 'card');
        $this->fakePagBank('DECLINED');
        $payment = $this->pendingPayment();

        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(402, $this->charge($payment)->getStatusCode());
        }

        $this->assertSame(429, $this->charge($payment)->getStatusCode());
    }

    public function test_invalid_cpf_never_reaches_pagbank(): void
    {
        SystemSetting::setValue('payment_methods', 'both');
        Http::fake();
        $payment = $this->pendingPayment();

        $response = $this->charge($payment, ['cpf' => '111.111.111-11']);

        $this->assertSame(422, $response->getStatusCode());
        Http::assertNothingSent();
    }

    public function test_already_paid_payment_is_not_charged_again(): void
    {
        SystemSetting::setValue('payment_methods', 'both');
        Http::fake();
        $payment = $this->pendingPayment();
        $payment->update(['status' => 'completed', 'paid_at' => now()]);

        $response = $this->charge($payment);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['already_paid']);
        Http::assertNothingSent();
    }
}
