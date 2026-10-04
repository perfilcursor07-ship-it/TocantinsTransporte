<?php

namespace App\Models;

use App\Helpers\SettingsHelper;
use App\Services\IntervalPlanService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TourismTrip extends Model
{
    public const PAYMENT_MODES = [
        'individual' => 'Cada passageiro paga o seu',
        'group' => 'Uma pessoa paga tudo',
    ];

    /** Quando uma pessoa paga tudo: período em que a internet fica liberada. */
    public const COVERAGES = [
        'all_days' => 'Todos os dias da viagem',
        'round_trip' => 'Somente ida e volta',
    ];

    /** Planos antigos (antes do campo "como será o plano"). Mantidos só para exibir. */
    public const PLANS = [
        'viagem_completa' => 'Viagem completa (12 horas)',
        'varios_dias' => 'Vários dias (24 horas por dia)',
        'personalizado' => 'Personalizado (descrever nas observações)',
    ];

    public const STATUSES = [
        'pending' => 'Aguardando administrador',
        'configured' => 'Configurada',
        'cancelled' => 'Cancelada',
    ];

    protected $fillable = [
        'contract_number', 'title', 'bus_id', 'starts_at', 'outbound_arrives_at', 'return_departs_at', 'ends_at',
        'payment_mode', 'coverage', 'plan', 'plan_details',
        'responsible_name', 'responsible_phone', 'agreed_amount', 'passengers_count',
        'notes', 'status', 'admin_notes', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'outbound_arrives_at' => 'datetime',
        'return_departs_at' => 'datetime',
        'ends_at' => 'datetime',
        'agreed_amount' => 'decimal:2',
        'passengers_count' => 'integer',
    ];

    public function bus()
    {
        return $this->belongsTo(Bus::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Viagens (não canceladas) do mesmo ônibus que se sobrepõem ao período. */
    public function scopeOverlapping(Builder $query, int $busId, $startsAt, $endsAt, ?int $ignoreId = null): Builder
    {
        return $query->where('bus_id', $busId)
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId));
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /** Funcionária edita/exclui só enquanto aguarda o administrador; admin pode tudo. */
    public function canBeChangedBy(User $user): bool
    {
        return $user->role === 'admin' || $this->isPending();
    }

    /** Preços atuais do portal (os mesmos que o passageiro vê). */
    public static function pricing(): array
    {
        return [
            'price_12h' => (float) SettingsHelper::getWifiPriceFull(),
            'hours_12h' => (int) SettingsHelper::getSessionDuration(),
            'price_24h' => (float) IntervalPlanService::settings()['price_24h'],
        ];
    }

    /**
     * Trechos em que a internet fica liberada quando uma pessoa paga tudo:
     * a viagem inteira, ou só a ida e a volta.
     *
     * @return array<int, array{label: string, start: CarbonInterface, end: CarbonInterface}>
     */
    public function internetWindows(): array
    {
        if ($this->payment_mode !== 'group' || ! $this->starts_at || ! $this->ends_at) {
            return [];
        }

        if ($this->coverage === 'round_trip' && $this->outbound_arrives_at && $this->return_departs_at) {
            return [
                ['label' => 'Ida', 'start' => $this->starts_at, 'end' => $this->outbound_arrives_at],
                ['label' => 'Volta', 'start' => $this->return_departs_at, 'end' => $this->ends_at],
            ];
        }

        return [['label' => 'Viagem inteira', 'start' => $this->starts_at, 'end' => $this->ends_at]];
    }

    /**
     * Cálculo pelos preços do portal, por passageiro: trecho de até 12h usa o
     * plano de 12h; acima disso, diárias de 24h (sempre 24h cheias).
     */
    public static function estimateWindows(array $windows, array $pricing): array
    {
        $lines = [];
        $total = 0.0;
        foreach ($windows as $window) {
            $hours = (int) ceil($window['start']->diffInMinutes($window['end']) / 60);
            if ($hours <= $pricing['hours_12h']) {
                $price = $pricing['price_12h'];
                $plan = "plano de {$pricing['hours_12h']} horas";
            } else {
                $days = (int) ceil($hours / 24);
                $price = $days * $pricing['price_24h'];
                $plan = $days.' '.($days === 1 ? 'diária' : 'diárias').' de 24h';
            }
            $total += $price;
            $lines[] = ['label' => $window['label'], 'hours' => $hours, 'plan' => $plan, 'price' => round($price, 2)];
        }

        return ['lines' => $lines, 'per_passenger' => round($total, 2)];
    }

    public function estimate(?array $pricing = null): array
    {
        return self::estimateWindows($this->internetWindows(), $pricing ?? self::pricing());
    }

    public function durationLabel(): string
    {
        $hours = (int) round($this->starts_at->diffInMinutes($this->ends_at) / 60);
        $days = intdiv($hours, 24);
        $rest = $hours % 24;

        if ($days === 0) {
            return "{$hours}h";
        }

        return $days.' '.($days === 1 ? 'dia' : 'dias').($rest ? " e {$rest}h" : '');
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** O que mostrar sobre o plano de internet na lista. */
    public function planLabel(): string
    {
        if ($this->payment_mode === 'individual') {
            return 'Cada passageiro escolhe no portal';
        }
        if ($this->coverage) {
            return self::COVERAGES[$this->coverage];
        }

        return self::PLANS[$this->plan] ?? '—';
    }

    public function paymentModeLabel(): string
    {
        return self::PAYMENT_MODES[$this->payment_mode] ?? $this->payment_mode;
    }
}
