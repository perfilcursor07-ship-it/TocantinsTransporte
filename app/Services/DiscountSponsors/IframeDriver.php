<?php

namespace App\Services\DiscountSponsors;

use Illuminate\Http\Request;

/**
 * Patrocinador de PÁGINA embutida (iframe num modal do portal).
 *
 * config:
 *   page_url   — URL da página do parceiro
 *   height     — altura do modal em px (padrão 520)
 *
 * EXIGÊNCIA DO PARCEIRO: o servidor dele precisa permitir o embed.
 * Se mandar "X-Frame-Options: DENY" ou "frame-ancestors 'none'", o
 * navegador bloqueia e o modal abre em branco — não há contorno nosso.
 * Ver docs/INTEGRACAO-PARCEIROS.md, Nível 2.
 */
class IframeDriver extends BaseDriver
{
    public function isReady(): bool
    {
        return (bool) $this->sponsor->cfg('page_url');
    }

    public function offer(): array
    {
        return array_merge(parent::offer(), [
            'page_url' => $this->sponsor->cfg('page_url'),
            'height' => (int) $this->sponsor->cfg('height', 520),
        ]);
    }

    public function start(Request $request, array $input): array
    {
        $lead = $this->createLead($request, $input, [
            'cpf' => !empty($input['cpf']) ? preg_replace('/\D/', '', $input['cpf']) : null,
        ]);

        $url = $this->sponsor->cfg('page_url');

        // Parceiro pode querer o CPF na query (ex.: pré-preencher o formulário).
        if ($this->sponsor->collects_cpf && $this->sponsor->cfg('cpf_query_param') && !empty($input['cpf'])) {
            $url .= (str_contains($url, '?') ? '&' : '?')
                . $this->sponsor->cfg('cpf_query_param') . '=' . urlencode(preg_replace('/\D/', '', $input['cpf']));
        }

        return [
            'ok' => true,
            'lead_id' => $lead->id,
            'mode' => 'iframe',
            'url' => $url,
            'height' => (int) $this->sponsor->cfg('height', 520),
            'min_seconds' => $this->sponsor->minSeconds(),
        ];
    }
}
