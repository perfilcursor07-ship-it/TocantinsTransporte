<?php

namespace App\Console\Commands;

use App\Models\DriverPixProfileMonth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Move os cadastros/pagamentos de motorista de uma competência para outra.
 *
 * Nasceu de um link de cadastro gerado com o mês errado: os motoristas
 * confirmaram em Outubro/2026 dados que eram de Setembro/2026.
 *
 * Roda em modo simulação por padrão. Só grava com --aplicar.
 */
class MoverCompetenciaDriverPix extends Command
{
    protected $signature = 'driver-pix:mover-competencia
                            {de : Competência de origem, no formato AAAA-MM}
                            {para : Competência de destino, no formato AAAA-MM}
                            {--link=* : Restringe aos cadastros vindos destes IDs de link}
                            {--pular-conflitos : Em vez de abortar, deixa parado o que colide no destino}
                            {--aplicar : Grava as alterações (sem isto, apenas simula)}';

    protected $description = 'Move competência (mês) dos cadastros e pagamentos de motorista';

    private Carbon $de;

    private Carbon $para;

    /** @var array<int> */
    private array $links = [];

    public function handle(): int
    {
        if (! $this->lerArgumentos()) {
            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');

        $this->info(sprintf(
            '%s %s  ->  %s',
            $aplicar ? 'APLICANDO' : 'SIMULAÇÃO —',
            DriverPixProfileMonth::labelFor($this->de),
            DriverPixProfileMonth::labelFor($this->para)
        ));

        if ($this->links) {
            $this->line('Restrito aos links: ' . implode(', ', $this->links));
        }

        $this->newLine();

        $entries = $this->competencias()->get();
        $payments = $this->pagamentos();
        $linksAlvo = $this->linksDaCompetencia();

        if ($entries->isEmpty() && $payments->isEmpty() && $linksAlvo->isEmpty()) {
            $this->warn('Nada encontrado nessa competência. Nenhuma alteração a fazer.');

            return self::SUCCESS;
        }

        [$mover, $conflitos] = $this->separarConflitos($entries);

        $this->relatorio($mover, $conflitos, $payments, $linksAlvo);

        if ($conflitos->isNotEmpty() && ! $this->option('pular-conflitos')) {
            $this->newLine();
            $this->error(
                $conflitos->count() . ' cadastro(s) já existem no destino com o mesmo ônibus. '
                . 'Nada foi alterado.'
            );
            $this->line('Resolva no painel, ou rode de novo com --pular-conflitos para mover só o restante.');

            return self::FAILURE;
        }

        if (! $aplicar) {
            $this->newLine();
            $this->comment('Simulação — nada foi gravado. Repita com --aplicar para efetivar.');

            return self::SUCCESS;
        }

        $this->gravar($mover, $payments, $linksAlvo);

        return self::SUCCESS;
    }

    private function lerArgumentos(): bool
    {
        foreach (['de', 'para'] as $arg) {
            if (! DriverPixProfileMonth::isValidMonthInput($this->argument($arg))) {
                $this->error("Competência inválida em '{$arg}'. Use o formato AAAA-MM, ex.: 2026-09.");

                return false;
            }
        }

        $this->de = DriverPixProfileMonth::normalizeMonth($this->argument('de'));
        $this->para = DriverPixProfileMonth::normalizeMonth($this->argument('para'));

        if ($this->de->equalTo($this->para)) {
            $this->error('Origem e destino são a mesma competência.');

            return false;
        }

        $this->links = array_values(array_filter(array_map(
            'intval',
            (array) $this->option('link')
        )));

        return true;
    }

    private function competencias()
    {
        return DriverPixProfileMonth::query()
            ->with('profile')
            ->whereDate('reference_month', $this->de)
            ->when($this->links, fn ($q) => $q->whereIn('registration_link_id', $this->links))
            ->orderBy('bus_number')
            ->orderBy('id');
    }

    private function pagamentos()
    {
        return DB::table('driver_pix_payments')
            ->whereDate('reference_month', $this->de)
            ->when($this->links, fn ($q) => $q->whereIn(
                'driver_pix_profile_month_id',
                $this->competencias()->select('driver_pix_profile_months.id')
            ))
            ->orderBy('id')
            ->get();
    }

    private function linksDaCompetencia()
    {
        return DB::table('driver_pix_registration_links')
            ->whereDate('reference_month', $this->de)
            ->when($this->links, fn ($q) => $q->whereIn('id', $this->links))
            ->orderBy('id')
            ->get();
    }

    /**
     * O destino tem índice único (perfil, competência, ônibus): separa o que
     * cabe do que esbarraria num cadastro já existente no mês de destino.
     *
     * @return array{0: \Illuminate\Support\Collection, 1: \Illuminate\Support\Collection}
     */
    private function separarConflitos($entries): array
    {
        $ocupados = DriverPixProfileMonth::query()
            ->whereDate('reference_month', $this->para)
            ->whereIn('driver_pix_profile_id', $entries->pluck('driver_pix_profile_id')->unique())
            ->get()
            ->map(fn ($e) => $e->driver_pix_profile_id . '|' . $e->bus_number)
            ->flip();

        return $entries->partition(
            fn ($entry) => ! $ocupados->has($entry->driver_pix_profile_id . '|' . $entry->bus_number)
        )->all();
    }

    private function relatorio($mover, $conflitos, $payments, $linksAlvo): void
    {
        $this->line('<options=bold>Cadastros por competência</>');

        if ($mover->isEmpty()) {
            $this->line('  (nenhum)');
        }

        foreach ($mover as $entry) {
            $this->line(sprintf(
                '  #%-5d ônibus %-6s %-38s %s',
                $entry->id,
                $entry->bus_number,
                mb_strimwidth((string) $entry->profile?->full_name, 0, 38, '…'),
                $entry->status
            ));
        }

        if ($conflitos->isNotEmpty()) {
            $this->newLine();
            $this->line('<options=bold;fg=yellow>Conflitos no destino (mesmo motorista + ônibus já existe)</>');

            foreach ($conflitos as $entry) {
                $this->line(sprintf(
                    '  #%-5d ônibus %-6s %s',
                    $entry->id,
                    $entry->bus_number,
                    $entry->profile?->full_name
                ));
            }
        }

        $this->newLine();
        $this->line('<options=bold>Pagamentos</>');
        $this->line(sprintf(
            '  %d registro(s), total R$ %s',
            $payments->count(),
            number_format((float) $payments->sum('amount'), 2, ',', '.')
        ));

        $this->newLine();
        $this->line('<options=bold>Links de cadastro</>');

        if ($linksAlvo->isEmpty()) {
            $this->line('  (nenhum apontando para essa competência)');
        }

        foreach ($linksAlvo as $link) {
            $this->line(sprintf('  #%-4d %s', $link->id, $link->label ?: 'sem rótulo'));
        }
    }

    private function gravar($mover, $payments, $linksAlvo): void
    {
        $destino = $this->para->toDateString();

        DB::transaction(function () use ($mover, $payments, $linksAlvo, $destino) {
            if ($mover->isNotEmpty()) {
                DriverPixProfileMonth::whereIn('id', $mover->pluck('id'))
                    ->update(['reference_month' => $destino]);
            }

            if ($payments->isNotEmpty()) {
                DB::table('driver_pix_payments')
                    ->whereIn('id', $payments->pluck('id'))
                    ->update(['reference_month' => $destino]);
            }

            if ($linksAlvo->isNotEmpty()) {
                DB::table('driver_pix_registration_links')
                    ->whereIn('id', $linksAlvo->pluck('id'))
                    ->update(['reference_month' => $destino]);
            }

            $this->recalcularUltimaConfirmacao($mover->pluck('driver_pix_profile_id')->unique());
        });

        $this->newLine();
        $this->info(sprintf(
            'Pronto: %d cadastro(s), %d pagamento(s) e %d link(s) movidos para %s.',
            $mover->count(),
            $payments->count(),
            $linksAlvo->count(),
            DriverPixProfileMonth::labelFor($this->para)
        ));
    }

    /**
     * last_confirmed_month só anda para frente no fluxo normal, então depois de
     * puxar a competência para trás ele fica apontando para um mês que não
     * existe mais. Refaz a partir das competências que sobraram.
     */
    private function recalcularUltimaConfirmacao($profileIds): void
    {
        foreach ($profileIds as $profileId) {
            $ultimo = DriverPixProfileMonth::where('driver_pix_profile_id', $profileId)
                ->max('reference_month');

            DB::table('driver_pix_profiles')
                ->where('id', $profileId)
                ->update(['last_confirmed_month' => $ultimo]);
        }
    }
}
