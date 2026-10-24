<?php

namespace App\Services\DiscountSponsors;

use App\Helpers\SettingsHelper;
use App\Models\DiscountSponsor;

/**
 * Resolve o driver do patrocinador ativo.
 *
 * Para suportar um parceiro com API fora do padrão, basta criar uma classe
 * que estenda BaseDriver e registrá-la em $extras.
 */
class SponsorManager
{
    /** Drivers adicionais para APIs fora do padrão: slug => classe. */
    private array $extras = [];

    public function driver(): ?SponsorDriver
    {
        if (!SettingsHelper::isVideoDiscountEnabled()) {
            return null;
        }

        $sponsor = DiscountSponsor::active();
        if (!$sponsor) {
            return null;
        }

        $driver = $this->resolver($sponsor);

        return $driver && $driver->isReady() ? $driver : null;
    }

    public function driverParaLead(\App\Models\DiscountLead $lead): ?SponsorDriver
    {
        $sponsor = $lead->sponsor ?: DiscountSponsor::active();
        return $sponsor ? $this->resolver($sponsor) : null;
    }

    private function resolver(DiscountSponsor $sponsor): ?SponsorDriver
    {
        if (isset($this->extras[$sponsor->slug])) {
            $classe = $this->extras[$sponsor->slug];
            return new $classe($sponsor);
        }

        return match ($sponsor->type) {
            DiscountSponsor::TYPE_VIDEO => new VideoDriver($sponsor),
            DiscountSponsor::TYPE_IFRAME => new IframeDriver($sponsor),
            DiscountSponsor::TYPE_API => new ApiHandoffDriver($sponsor),
            default => null,
        };
    }

    public function registrar(string $slug, string $classe): void
    {
        $this->extras[$slug] = $classe;
    }
}
