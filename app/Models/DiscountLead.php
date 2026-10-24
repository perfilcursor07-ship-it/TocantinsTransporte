<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DiscountLead extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_CREATED = 'created';
    public const STATUS_FAILED = 'failed';

    protected $table = 'discount_leads';

    protected $fillable = [
        'sponsor_id',
        'user_id',
        'cpf',
        'phone',
        'mac_address',
        'consent_accepted_at',
        'consent_ip',
        'consent_user_agent',
        'consent_latitude',
        'consent_longitude',
        'consent_location_source',
        'status',
        'handoff_id',
        'http_status',
        'error_code',
        'link_expires_at',
        'link_opened_at',
        'returned_at',
        'dwell_seconds',
        'discount_granted_at',
        'discount_amount',
    ];

    protected $casts = [
        'consent_accepted_at' => 'datetime',
        'link_expires_at' => 'datetime',
        'link_opened_at' => 'datetime',
        'returned_at' => 'datetime',
        'discount_granted_at' => 'datetime',
        'consent_latitude' => 'decimal:7',
        'consent_longitude' => 'decimal:7',
        'discount_amount' => 'decimal:2',
    ];

    /**
     * Esconde o CPF em qualquer serialização que escape para o navegador.
     */
    protected $hidden = ['cpf'];

    public function sponsor()
    {
        return $this->belongsTo(DiscountSponsor::class, 'sponsor_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Desconto ainda válido para este aparelho?
     *
     * Vale enquanto o passageiro não pagou — o desconto é concedido no retorno
     * do app e precisa sobreviver a um refresh da página do portal.
     */
    /**
     * Proposta aberta e ainda não confirmada para este aparelho.
     *
     * É o que permite retomar o fluxo quando o passageiro volta do app do
     * parceiro — o redirect na mesma aba recarrega o portal do zero.
     */
    public static function pendingReturnFor(?string $macAddress, int $validMinutes = 30): ?self
    {
        $mac = strtoupper(trim((string) $macAddress));
        if ($mac === '') {
            return null;
        }

        return static::where('mac_address', $mac)
            ->where('status', self::STATUS_CREATED)
            ->whereNull('discount_granted_at')
            ->whereNotNull('link_opened_at')
            ->where('link_opened_at', '>=', now()->subMinutes($validMinutes))
            ->orderByDesc('id')
            ->first();
    }

    public static function activeDiscountFor(?string $macAddress, int $validMinutes = 60): ?self
    {
        $mac = strtoupper(trim((string) $macAddress));
        if ($mac === '') {
            return null;
        }

        return static::where('mac_address', $mac)
            ->whereNotNull('discount_granted_at')
            ->where('discount_granted_at', '>=', now()->subMinutes($validMinutes))
            ->orderByDesc('id')
            ->first();
    }
}
