<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TourismTrip extends Model
{
    public const PAYMENT_MODES = [
        'individual' => 'Cada passageiro paga o seu',
        'group' => 'Uma pessoa paga tudo',
    ];

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
        'title', 'bus_id', 'starts_at', 'ends_at', 'payment_mode', 'plan',
        'responsible_name', 'responsible_phone', 'agreed_amount', 'passengers_count',
        'notes', 'status', 'admin_notes', 'created_by',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
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

    public function planLabel(): string
    {
        return self::PLANS[$this->plan] ?? $this->plan;
    }

    public function paymentModeLabel(): string
    {
        return self::PAYMENT_MODES[$this->payment_mode] ?? $this->payment_mode;
    }
}
