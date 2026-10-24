<?php

namespace App\Services\DiscountSponsors;

use App\Models\DiscountLead;
use App\Models\DiscountSponsor;
use Illuminate\Http\Request;

/**
 * Comportamento comum aos drivers: criar o lead e decidir o desconto
 * pelo tempo de exposição medido NO SERVIDOR.
 */
abstract class BaseDriver implements SponsorDriver
{
    public function __construct(protected DiscountSponsor $sponsor)
    {
    }

    public function sponsor(): DiscountSponsor
    {
        return $this->sponsor;
    }

    public function offer(): array
    {
        $amount = $this->sponsor->discountAmount();

        return [
            'sponsor' => $this->sponsor->name,
            'type' => $this->sponsor->type,
            'collects_cpf' => (bool) $this->sponsor->collects_cpf,
            'discount_amount' => $amount,
            'min_seconds' => $this->sponsor->minSeconds(),
            'headline' => $this->sponsor->headline
                ?: 'Tenha agora R$' . number_format($amount, 2, ',', '.') . ' de desconto',
            'subheadline' => $this->sponsor->subheadline
                ?: 'Veja o conteúdo do nosso parceiro',
        ];
    }

    /**
     * Cria o registro de exposição. É o relógio do desconto: o tempo é
     * contado a partir de link_opened_at, no servidor.
     */
    protected function createLead(Request $request, array $input, array $extra = []): DiscountLead
    {
        return DiscountLead::create(array_merge([
            'sponsor_id' => $this->sponsor->id,
            'user_id' => $input['user_id'] ?? null,
            'phone' => $input['phone'] ?? null,
            'mac_address' => strtoupper(trim((string) ($input['mac_address'] ?? ''))) ?: null,
            'status' => DiscountLead::STATUS_CREATED,
            'link_opened_at' => now(),
        ], $extra));
    }

    /**
     * Regra padrão do desconto: ficou o tempo mínimo exposto ao conteúdo.
     * O navegador não participa da conta.
     */
    public function verify(DiscountLead $lead): array
    {
        if ($lead->discount_granted_at) {
            return ['granted' => true, 'discount_amount' => (float) $lead->discount_amount];
        }

        $min = $this->sponsor->minSeconds();
        $dwell = $lead->link_opened_at ? (int) $lead->link_opened_at->diffInSeconds(now()) : 0;

        $lead->update(['returned_at' => now(), 'dwell_seconds' => $dwell]);

        if ($dwell < $min) {
            return [
                'granted' => false,
                'min_seconds' => $min,
                'dwell_seconds' => $dwell,
                'remaining_seconds' => $min - $dwell,
                'message' => 'Você voltou antes do tempo. Veja o conteúdo até o fim para ganhar o desconto.',
            ];
        }

        $amount = $this->sponsor->discountAmount();
        $lead->update(['discount_granted_at' => now(), 'discount_amount' => $amount]);

        return ['granted' => true, 'discount_amount' => $amount];
    }
}
