<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DriverPixPayment extends Model
{
    protected $fillable = [
        'driver_pix_profile_id',
        'driver_pix_profile_month_id',
        'reference_month',
        'bus_number',
        'amount',
        'description',
        'status',
        'payment_reference',
        'notes',
        'paid_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'reference_month' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function profile()
    {
        return $this->belongsTo(DriverPixProfile::class, 'driver_pix_profile_id');
    }

    public function monthEntry()
    {
        return $this->belongsTo(DriverPixProfileMonth::class, 'driver_pix_profile_month_id');
    }

    public function monthLabel(): string
    {
        if (! $this->reference_month) {
            return '—';
        }

        return DriverPixProfileMonth::labelFor($this->reference_month);
    }

    /** "Outubro/2026": o mês trabalhado que este pagamento paga. */
    public function ganhosLabel(): string
    {
        if (! $this->reference_month) {
            return '—';
        }

        return \App\Services\DriverPrizeService::rotuloGanhos($this->reference_month);
    }

    public function shortMonthLabel(): string
    {
        if (! $this->reference_month) {
            return '—';
        }

        return DriverPixProfileMonth::shortLabelFor($this->reference_month);
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
