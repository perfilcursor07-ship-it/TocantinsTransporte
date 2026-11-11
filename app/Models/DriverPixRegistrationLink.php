<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DriverPixRegistrationLink extends Model
{
    protected $fillable = [
        'token',
        'label',
        'reference_month',
        'is_active',
        'expires_at',
        'uses_count',
        'created_by',
    ];

    /**
     * Competência do link = mês em que o pagamento sai. Ela paga os ganhos do
     * mês ANTERIOR (ver ganhosLabel). Sem mês definido, vale o mês corrente —
     * é o comportamento dos links antigos.
     */
    public function competencia(): \Illuminate\Support\Carbon
    {
        return $this->reference_month
            ? $this->reference_month->copy()->startOfMonth()
            : now()->startOfMonth();
    }

    public function competenciaLabel(): string
    {
        return DriverPixProfileMonth::labelFor($this->competencia());
    }

    /** "Outubro/2026": o mês TRABALHADO que este link paga. */
    public function ganhosLabel(): string
    {
        return \App\Services\DriverPrizeService::rotuloGanhos($this->competencia());
    }

    /** "Outubro/2026 (01/10 a 31/10)". */
    public function ganhosLabelCompleto(): string
    {
        return \App\Services\DriverPrizeService::rotuloGanhosCompleto($this->competencia());
    }

    /**
     * O link é de um ciclo atrasado? O ciclo normal paga os ganhos do mês
     * passado (competência = mês corrente); só é "atrasado" o que vem antes.
     */
    public function ehMesPassado(): bool
    {
        return $this->competencia()->lt(now()->startOfMonth());
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'reference_month' => 'date',
            'expires_at' => 'datetime',
            'uses_count' => 'integer',
        ];
    }

    public static function generateToken(): string
    {
        do {
            $token = Str::random(32);
        } while (self::where('token', $token)->exists());

        return $token;
    }

    public function isUsable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function publicUrl(): string
    {
        return route('driver-pix.register', $this->token);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function profiles()
    {
        return $this->hasMany(DriverPixProfile::class, 'registration_link_id');
    }
}
