<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsappMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'payment_id',
        'phone',
        'message',
        'status',
        'error_message',
        'message_id',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Relacionamento com usuário
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relacionamento com pagamento
     */
    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    /**
     * Escopo para mensagens pendentes
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Escopo para mensagens enviadas
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Escopo para mensagens com falha
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Lembretes de PIX não pago (automáticos e manuais).
     *
     * A tabela não tem coluna de tipo, então reconhece pelo texto: todo
     * lembrete é ligado a um pagamento e traz PARAR; a confirmação de
     * pagamento também traz PARAR, mas sempre com "Pagamento confirmado".
     */
    public function scopeReminders($query)
    {
        return $query->whereNotNull('payment_id')
            ->where('message', 'like', '%PARAR%')
            ->where('message', 'not like', '%Pagamento confirmado%');
    }

    /**
     * Marcar como enviada
     */
    public function markAsSent($messageId = null)
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'message_id' => $messageId,
        ]);
    }

    /**
     * Marcar como falha
     */
    public function markAsFailed($errorMessage)
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
        ]);
    }

    /**
     * Formatar telefone para WhatsApp (55 + DDD + número)
     */
    public static function formatPhone($phone)
    {
        // Remove tudo que não é número
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Já está no formato internacional completo (55 + DDD + 9 dígitos = 13)
        // ou 55 + DDD + 8 dígitos = 12
        if (strlen($phone) === 13 && substr($phone, 0, 2) === '55') {
            return $phone;
        }
        if (strlen($phone) === 12 && substr($phone, 0, 2) === '55') {
            return $phone;
        }

        // Número local com DDD: 11 dígitos (DDD + 9 + 8) ou 10 (DDD + 8)
        // Adiciona o código do país 55
        if (strlen($phone) === 11 || strlen($phone) === 10) {
            return '55' . $phone;
        }

        // Fallback: retorna como está
        return $phone;
    }
}
