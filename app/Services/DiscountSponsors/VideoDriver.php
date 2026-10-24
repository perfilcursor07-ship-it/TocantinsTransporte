<?php

namespace App\Services\DiscountSponsors;

use App\Models\DiscountLead;
use Illuminate\Http\Request;

/**
 * Patrocinador de VÍDEO. Integração zero: o parceiro só entrega o arquivo.
 *
 * config:
 *   video_url  — URL do MP4 (asset local ou URL externa liberada no walled garden)
 *   duration   — duração em segundos (define o tempo mínimo se min_seconds vazio)
 *
 * O desconto sai quando o vídeo termina. Como o player é nosso, aqui a
 * verificação é confiável de verdade: o evento 'ended' é prova.
 */
class VideoDriver extends BaseDriver
{
    public function isReady(): bool
    {
        return (bool) $this->sponsor->cfg('video_url');
    }

    public function offer(): array
    {
        $duracao = (int) $this->sponsor->cfg('duration', 0);

        return array_merge(parent::offer(), [
            'video_url' => $this->sponsor->cfg('video_url'),
            'duration' => $duracao ?: null,
            'subheadline' => $this->sponsor->subheadline
                ?: ($duracao ? "Assista um vídeo de {$duracao}s" : 'Assista um vídeo curto'),
        ]);
    }

    public function start(Request $request, array $input): array
    {
        $lead = $this->createLead($request, $input);

        return [
            'ok' => true,
            'lead_id' => $lead->id,
            'mode' => 'video',
            'url' => $this->sponsor->cfg('video_url'),
            'min_seconds' => $this->minimo(),
        ];
    }

    public function verify(DiscountLead $lead): array
    {
        // O player é nosso: o tempo mínimo é a duração do vídeo.
        return parent::verify($lead);
    }

    private function minimo(): int
    {
        return (int) ($this->sponsor->min_seconds
            ?: $this->sponsor->cfg('duration')
            ?: $this->sponsor->minSeconds());
    }
}
