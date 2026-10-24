<?php

namespace App\Services\DiscountSponsors;

use App\Models\DiscountLead;
use App\Models\DiscountSponsor;
use Illuminate\Http\Request;

/**
 * Contrato que todo tipo de patrocinador implementa.
 *
 * O portal não sabe quem é o parceiro — só pergunta ao driver
 * "o que eu mostro?" e "como o passageiro ganha o desconto?".
 */
interface SponsorDriver
{
    /** O patrocinador está configurado o bastante para aparecer? */
    public function isReady(): bool;

    /**
     * Dados que o portal precisa para montar o botão da oferta.
     * Nada sensível aqui: isso vai para o HTML.
     */
    public function offer(): array;

    /**
     * Prepara a exibição para este passageiro.
     *
     * Retorna:
     *   ['ok' => true, 'lead_id' => int, 'mode' => 'video|iframe|redirect',
     *    'url' => string|null, 'min_seconds' => int]
     *   ['ok' => false, 'reason' => string, 'message' => string]
     */
    public function start(Request $request, array $input): array;

    /**
     * O passageiro cumpriu o requisito para o desconto?
     * Quem decide é o servidor — o navegador só avisa que terminou.
     */
    public function verify(DiscountLead $lead): array;

    public function sponsor(): DiscountSponsor;
}
