<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'payment_type',
        'status',
        'payment_id',
        'transaction_id',
        'pix_emv_string',
        'pix_location',
        'gateway_payment_id',
        'payment_data',
        'bus_mikrotik_id',
        'paid_at',
        'unpaid_reminder_sent_at',
        'whatsapp_confirmation_sent_at',
        'refund_receipt_path',
        'refunded_at',
        'refund_note',
    ];

    protected function casts(): array
    {
        return [
            'payment_data' => 'array',
            'paid_at' => 'datetime',
            'unpaid_reminder_sent_at' => 'datetime',
            'whatsapp_confirmation_sent_at' => 'datetime',
            'refunded_at' => 'datetime',
            'amount' => 'decimal:2',
        ];
    }

    /**
     * Guarda o ônibus do passageiro no próprio pagamento, para a receita do mês
     * não mudar de dono quando ele viajar em outro carro depois.
     *
     * Roda em todos os caminhos de criação de pagamento (checkout, webhook,
     * comandos) sem que nenhum deles precise saber disso. Qualquer falha aqui é
     * engolida: o campo fica nulo e o relatório volta a usar o
     * users.last_mikrotik_id, que é o comportamento de antes desta coluna
     * existir. Pagar nunca pode quebrar por causa de um dado de relatório.
     */
    protected static function booted(): void
    {
        static::creating(function (self $payment) {
            if (filled($payment->bus_mikrotik_id) || blank($payment->user_id)) {
                return;
            }

            try {
                $payment->bus_mikrotik_id = User::whereKey($payment->user_id)
                    ->value('last_mikrotik_id');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning(
                    'Não foi possível congelar o ônibus do pagamento (segue sem isso)',
                    ['user_id' => $payment->user_id, 'erro' => $e->getMessage()]
                );
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bus()
    {
        return $this->belongsTo(Bus::class, 'bus_mikrotik_id', 'mikrotik_serial');
    }

    /**
     * Pagamentos atribuídos a um ônibus (serial do MikroTik).
     *
     * Precisa seguir exatamente a mesma precedência da soma em
     * BusRevenueService::busIdExpression(), senão filtrar o relatório por um
     * ônibus mostra uma lista que não fecha com o total daquele ônibus.
     */
    public function scopeDoOnibus($query, string $serial)
    {
        $semTransferencia = fn ($q) => $q->whereNull('payment_data->transferred_mikrotik_id');
        $semCongelamento = fn ($q) => $q->where(
            fn ($q) => $q->whereNull('bus_mikrotik_id')->orWhere('bus_mikrotik_id', '')
        );

        return $query->where(function ($q) use ($serial, $semTransferencia, $semCongelamento) {
            // 1. Transferência manual do admin aponta para cá.
            $q->where('payment_data->transferred_mikrotik_id', $serial)
                // 2. Sem transferência, vale o ônibus congelado no pagamento.
                ->orWhere(fn ($q) => $semTransferencia($q)->where('bus_mikrotik_id', $serial))
                // 3. Sem os dois: pagamento legado, cai no ônibus atual do passageiro.
                ->orWhere(function ($q) use ($serial, $semTransferencia, $semCongelamento) {
                    $semTransferencia($q);
                    $semCongelamento($q);
                    $q->whereHas('user', fn ($u) => $u->where('last_mikrotik_id', $serial));
                });
        });
    }

    public function sessions()
    {
        return $this->hasMany(Session::class);
    }

    public function isRefunded(): bool
    {
        return $this->status === 'refunded';
    }

    public function hasRefundReceipt(): bool
    {
        return filled($this->refund_receipt_path);
    }
}
