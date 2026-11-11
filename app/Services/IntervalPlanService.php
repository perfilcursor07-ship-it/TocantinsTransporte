<?php

namespace App\Services;

use App\Models\IntervalAccessDay;
use App\Models\Payment;
use App\Models\Session;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class IntervalPlanService
{
    public const FIRST_DAY_POLICY = 'continuous-v3';

    /**
     * Regra atual (mode "continuous"): N dias = N x 24h seguidas, liberadas de
     * uma vez só. Começa na confirmação do PIX (ou, em compra antecipada, quando
     * o passageiro abre o portal no ônibus a partir da data inicial).
     * Pagamentos antigos (sem "mode") mantêm a regra de uma diária por data.
     */
    public const MODE_CONTINUOUS = 'continuous';

    public static function isContinuous(Payment $payment): bool
    {
        return data_get($payment->payment_data, 'interval.mode') === self::MODE_CONTINUOUS;
    }

    /** First eligible day starts at payment confirmation; later days start in the portal. */
    public function activatePaidCheckout(Payment $payment): array
    {
        if (! self::isInterval($payment) || ! $payment->user) {
            return $this->result('none', 'Nenhum intervalo disponível.');
        }

        return DB::transaction(function () use ($payment) {
            $user = User::whereKey($payment->user_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->status !== 'completed' || ! self::isInterval($payment) || ! $payment->paid_at) {
                return $this->result('none', 'Pagamento ainda não confirmado.');
            }

            if (self::isContinuous($payment)) {
                return $this->activateContinuousCheckout($user, $payment);
            }

            $start = $payment->paid_at->copy();
            $interval = $payment->payment_data['interval'];
            $expires = $start->copy()->addHours($interval['hours_per_day']);

            // A repeated webhook/reconciliation can restore a day, never renew it
            // or consume tomorrow's entitlement. Future bookings wait for the portal.
            if ($start->isFuture() || ! $expires->isFuture()
                || $start->toDateString() < $interval['start']
                || $start->toDateString() > $interval['end']
                || IntervalAccessDay::where('payment_id', $payment->id)->exists()
                || IntervalAccessDay::where('user_id', $user->id)->where('access_date', $start->toDateString())->exists()
                || (in_array($user->status, ['connected', 'active']) && $user->expires_at?->isFuture())) {
                return $this->access($user);
            }

            // Payment is sufficient for the first day. Bypass logs, their age and
            // the browser returning from the banking app must not block paid access.
            $day = $this->startDay($user, $payment, $start);
            return $this->result('active', 'Pagamento confirmado. Sua diária está ativa.', $day);
        });
    }

    public static function settings(): array
    {
        $legacyPrice12h = SystemSetting::getValue('plan_interval_price_12h', null);
        $default24h = $legacyPrice12h !== null ? (string) round((float) $legacyPrice12h * 2, 2) : '15.98';

        return [
            'enabled' => (bool) SystemSetting::getValue('plan_interval_enabled', '0'),
            'max_days' => max(1, (int) SystemSetting::getValue('plan_interval_max_days', '30')),
            // Compatibilidade: enquanto o novo valor não for salvo no painel,
            // duas bases antigas de 12h formam a diária única de 24h.
            'price_24h' => (float) SystemSetting::getValue('plan_interval_price_24h', $default24h),
            'today' => now()->toDateString(),
            'tomorrow' => now()->addDay()->toDateString(),
        ];
    }

    public static function isInterval(Payment $payment): bool
    {
        return data_get($payment->payment_data, 'plan_type') === 'interval';
    }

    public function quote(array $input): array
    {
        $settings = self::settings();
        if (! $settings['enabled']) {
            throw ValidationException::withMessages(['plan_type' => 'O plano por intervalo está indisponível.']);
        }
        $data = Validator::make($input, [
            'interval_start' => 'required|date_format:Y-m-d|after_or_equal:today|before_or_equal:'.now()->addYear()->toDateString(),
            // O último dia é quando o acesso termina: 28 → 30 = 2 dias = 48h.
            'interval_end' => 'required|date_format:Y-m-d|after:interval_start',
            'interval_hours' => 'required|integer|in:24',
        ], [
            'interval_end.after' => 'O último dia precisa ser depois do primeiro dia.',
        ])->validate();
        $start = CarbonImmutable::parse($data['interval_start']);
        $end = CarbonImmutable::parse($data['interval_end']);
        $days = (int) $start->diffInDays($end);
        if ($days < 1 || $days > $settings['max_days']) {
            throw ValidationException::withMessages(['interval_end' => "Escolha de 1 a {$settings['max_days']} dias."]);
        }
        $dailyCents = (int) round($settings['price_24h'] * 100);

        return [
            'plan_type' => 'interval',
            'plan_name' => 'Plano por intervalo',
            'plan_suffix' => "/ {$days} dia(s)",
            'duration_hours' => (int) $data['interval_hours'],
            'interval' => [
                'mode' => self::MODE_CONTINUOUS,
                'start' => $data['interval_start'],
                'end' => $data['interval_end'],
                'days' => $days,
                'total_hours' => $days * 24,
                'hours_per_day' => 24,
                'base_price_cents' => $dailyCents,
                'daily_price_cents' => $dailyCents,
                'total_cents' => $dailyCents * $days,
                'timezone' => config('app.timezone'),
            ],
        ];
    }

    /** Later days require a foreground portal request; background healing only restores access. */
    public function access(User $user, bool $startNewDay = false): array
    {
        if (! Payment::where('user_id', $user->id)->where('status', 'completed')
            ->where('payment_data->plan_type', 'interval')->exists()) {
            return $this->result('none', 'Nenhum intervalo disponível.');
        }
        return DB::transaction(function () use ($user, $startNewDay) {
            $lockedUser = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $today = $now->toDateString();
            $day = IntervalAccessDay::where('user_id', $user->id)
                ->where('expires_at', '>', $now)
                ->whereHas('payment', fn ($q) => $q->where('status', 'completed'))
                ->orderByDesc('expires_at')->first();
            if ($day) {
                $this->restoreDay($lockedUser, $day);
                return $this->result('active', 'Seu acesso está ativo. Liberação em até 30 segundos.', $day);
            }

            // Regra atual: plano contínuo pago e ainda não iniciado.
            $continuous = Payment::where('user_id', $user->id)->where('status', 'completed')
                ->where('payment_data->plan_type', 'interval')
                ->where('payment_data->interval->mode', self::MODE_CONTINUOUS)
                ->where('payment_data->interval->end', '>', $today)
                ->whereNotIn('id', IntervalAccessDay::query()->select('payment_id'))
                ->orderBy('payment_data->interval->start')->orderBy('id')->first();
            if ($continuous) {
                $interval = $continuous->payment_data['interval'];
                if ($interval['start'] > $today) {
                    $date = CarbonImmutable::parse($interval['start'])->format('d/m/Y');
                    return $this->result('scheduled', "Plano pago. Começa a partir de {$date}, quando você se conectar no Wi-Fi do ônibus.");
                }
                if (in_array($lockedUser->status, ['connected', 'active']) && $lockedUser->expires_at?->isFuture()) {
                    return $this->result('existing_access', 'Você já tem internet ativa. Quando ela terminar, abra este portal para começar seu plano de vários dias.');
                }
                if (! $startNewDay) {
                    return $this->result('ready', 'Plano pago. Abra o portal conectado ao Wi-Fi do ônibus para começar.');
                }
                $day = $this->startDay($lockedUser, $continuous, $now);

                return $this->result('active', 'Seu plano começou. Liberação em até 30 segundos.', $day);
            }

            // Pagamentos antigos (sem "mode"): uma diária por data, como antes.
            $payment = Payment::where('user_id', $user->id)->where('status', 'completed')
                ->where('payment_data->plan_type', 'interval')
                ->whereNull('payment_data->interval->mode')
                ->where('payment_data->interval->end', '>=', $today)
                ->orderBy('payment_data->interval->start')->orderBy('id')->first();
            if (! $payment) {
                return $this->result('none', 'Nenhum intervalo disponível.');
            }
            $interval = $payment->payment_data['interval'];
            if ($interval['start'] > $today) {
                $date = CarbonImmutable::parse($interval['start'])->format('d/m/Y');
                return $this->result('scheduled', "Plano pago. Disponível a partir de {$date}.");
            }
            if (in_array($lockedUser->status, ['connected', 'active']) && $lockedUser->expires_at?->isFuture()) {
                return $this->result('existing_access', 'Você já possui acesso ativo. Sua próxima diária ainda não começou.');
            }
            if (IntervalAccessDay::where('user_id', $user->id)->where('access_date', $today)->exists()) {
                return $this->result('used', $interval['end'] > $today
                    ? 'A diária de hoje já terminou. A próxima fica disponível amanhã.'
                    : 'A última diária deste intervalo já terminou.');
            }
            if (! $startNewDay) {
                return $this->result('ready', 'Diária disponível. Abra o portal conectado ao Wi-Fi do ônibus.');
            }

            $day = $this->startDay($lockedUser, $payment, $now);

            return $this->result('active', 'Sua diária começou. Liberação em até 30 segundos.', $day);
        });
    }

    /**
     * Plano contínuo: libera todo o período pago de uma vez, a partir da
     * confirmação do PIX. Webhook repetido ou reconciliação só restauram.
     */
    private function activateContinuousCheckout(User $user, Payment $payment): array
    {
        $start = $payment->paid_at->copy();
        $interval = $payment->payment_data['interval'];

        if (IntervalAccessDay::where('payment_id', $payment->id)->exists()
            // Compra antecipada: começa no portal a partir da data inicial.
            || $start->isFuture() || $start->toDateString() < $interval['start']
            // Acesso atual (plano normal ou liberação manual) não é encurtado nem consumido.
            || (in_array($user->status, ['connected', 'active']) && $user->expires_at?->isFuture())
            || IntervalAccessDay::where('user_id', $user->id)->where('access_date', $start->toDateString())->exists()
            || ! $start->copy()->addHours(self::periodHours($payment))->isFuture()) {
            return $this->access($user);
        }

        $day = $this->startDay($user, $payment, $start);

        return $this->result('active', 'Pagamento confirmado. Sua internet está ativa.', $day);
    }

    /** Horas liberadas por ativação: período inteiro (contínuo) ou uma diária (antigo). */
    private static function periodHours(Payment $payment): int
    {
        $interval = $payment->payment_data['interval'];

        return self::isContinuous($payment)
            ? (int) ($interval['total_hours'] ?? $interval['days'] * $interval['hours_per_day'])
            : (int) $interval['hours_per_day'];
    }

    private function startDay(User $user, Payment $payment, \Carbon\CarbonInterface $start): IntervalAccessDay
    {
        $day = IntervalAccessDay::create([
            'user_id' => $user->id, 'payment_id' => $payment->id,
            'access_date' => $start->toDateString(), 'started_at' => $start,
            'expires_at' => $start->copy()->addHours(self::periodHours($payment)),
        ]);
        Session::create([
            'user_id' => $user->id, 'payment_id' => $payment->id,
            'started_at' => $start, 'session_status' => 'active',
        ]);
        $this->restoreDay($user, $day);
        return $day;
    }

    private function restoreDay(User $user, IntervalAccessDay $day): void
    {
        // A separate valid purchase must not be shortened by an interval reconnect.
        if (in_array($user->status, ['connected', 'active']) && $user->expires_at?->gte($day->expires_at)) {
            return;
        }
        $user->update(['status' => 'connected', 'connected_at' => $day->started_at, 'expires_at' => $day->expires_at]);
        Cache::forget('mikrotik_sync_lists_all');
    }

    private function result(string $state, string $message, ?IntervalAccessDay $day = null): array
    {
        return ['state' => $state, 'message' => $message, 'expires_at' => $day?->expires_at->toISOString()];
    }
}
