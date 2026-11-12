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
     * É uma proposta de verdade: o passageiro informou o CPF e o parceiro
     * devolveu o link (handoff). Só esse tipo de registro pode dar desconto.
     */
    public function isProposal(): bool
    {
        return filled($this->cpf) && filled($this->handoff_id);
    }

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

        // Só propostas que realmente foram para o app do parceiro (têm handoff).
        return static::where('mac_address', $mac)
            ->where('status', self::STATUS_CREATED)
            ->whereNotNull('handoff_id')
            ->whereNull('discount_granted_at')
            ->whereNotNull('link_opened_at')
            ->where('link_opened_at', '>=', now()->subMinutes($validMinutes))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Desconto ainda válido para este aparelho?
     *
     * O desconto vale por um tempo limitado (Admin > Sistema > Descontos) e uma
     * vez só: depois que o passageiro paga, ou quando o prazo vence, o preço
     * volta ao normal. Nesse intervalo ele sobrevive a um refresh do portal.
     */
    public static function activeDiscountFor(?string $macAddress, ?int $validMinutes = null): ?self
    {
        $mac = strtoupper(trim((string) $macAddress));
        if ($mac === '') {
            return null;
        }

        $validMinutes ??= \App\Helpers\SettingsHelper::getDiscountValidityMinutes();

        // Só desconto de proposta (com CPF, enviada ao parceiro).
        $lead = static::where('mac_address', $mac)
            ->whereNotNull('handoff_id')
            ->whereNotNull('cpf')
            ->whereNotNull('discount_granted_at')
            ->where('discount_granted_at', '>=', now()->subMinutes($validMinutes))
            ->orderByDesc('id')
            ->first();

        return $lead && ! $lead->usedPayment() ? $lead : null;
    }

    /** Até quando o desconto liberado pode ser usado. */
    public function discountExpiresAt(): ?\Illuminate\Support\Carbon
    {
        return $this->discount_granted_at
            ? $this->discount_granted_at->copy()->addMinutes(\App\Helpers\SettingsHelper::getDiscountValidityMinutes())
            : null;
    }

    /**
     * Pagamento que usou este desconto: o primeiro pagamento concluído do
     * passageiro depois da liberação, dentro do prazo.
     *
     * Não há gravação no fluxo de pagamento para isso — é calculado na leitura,
     * então nada aqui consegue atrapalhar um pagamento. A folga de 30 minutos
     * cobre o PIX gerado no fim do prazo e pago logo depois.
     */
    public function usedPayment(): ?Payment
    {
        if (! $this->discount_granted_at) {
            return null;
        }

        $userIds = collect([$this->user_id]);
        if ($this->mac_address) {
            $userIds = $userIds->merge(User::where('mac_address', $this->mac_address)->pluck('id'));
        }
        $userIds = $userIds->filter()->unique()->values();
        if ($userIds->isEmpty()) {
            return null;
        }

        return Payment::whereIn('user_id', $userIds)
            ->where('status', 'completed')
            ->where('paid_at', '>=', $this->discount_granted_at)
            ->where('paid_at', '<=', $this->discountExpiresAt()->copy()->addMinutes(30))
            ->orderBy('paid_at')
            ->first();
    }

    /**
     * Situação do desconto, para o portal e para a tela de controle:
     * sem_desconto | ativo | usado | vencido.
     */
    public function discountState(): string
    {
        if (! $this->discount_granted_at) {
            return 'sem_desconto';
        }
        if ($this->usedPayment()) {
            return 'usado';
        }

        return $this->discountExpiresAt()->isFuture() ? 'ativo' : 'vencido';
    }

    /**
     * Este passageiro já ganhou desconto nos últimos X dias? Confere pelo
     * aparelho, pelo telefone e pelo CPF, porque qualquer um deles pode mudar.
     */
    public static function earnedRecently(int $days, ?string $macAddress = null, ?string $phone = null, ?string $cpf = null): bool
    {
        if ($days <= 0) {
            return false;
        }

        $mac = strtoupper(trim((string) $macAddress));
        $phone = preg_replace('/\D/', '', (string) $phone);
        $cpf = preg_replace('/\D/', '', (string) $cpf);
        if ($mac === '' && $phone === '' && $cpf === '') {
            return false;
        }

        return static::whereNotNull('discount_granted_at')
            ->where('discount_granted_at', '>=', now()->subDays($days))
            ->where(function ($q) use ($mac, $phone, $cpf) {
                if ($mac !== '') {
                    $q->orWhere('mac_address', $mac);
                }
                if ($phone !== '') {
                    $q->orWhere('phone', $phone);
                }
                if ($cpf !== '') {
                    $q->orWhere('cpf', $cpf);
                }
            })
            ->exists();
    }
}
