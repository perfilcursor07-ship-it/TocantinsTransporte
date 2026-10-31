<?php

namespace App\Console\Commands;

use App\Models\DriverPixProfileMonth;
use App\Services\DriverPrizeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fotografia das competências do módulo de pagamento de motorista.
 *
 * Serve para responder uma pergunta só: o mês gravado em reference_month é o
 * mês dos GANHOS ou o mês do PAGAMENTO? A resposta está na relação entre a
 * competência e a data em que o dinheiro saiu (paid_at).
 *
 * Somente leitura.
 */
class DiagnosticoCompetenciasDriverPix extends Command
{
    protected $signature = 'driver-pix:diagnostico-competencias
                            {--receita : Também calcula a receita de cada mês (mais lento)}';

    protected $description = 'Mostra competências, datas de envio e pagamentos para conferir o alinhamento dos meses';

    public function handle(): int
    {
        $this->confirmacoes();
        $this->newLine();
        $this->pagamentos();
        $this->newLine();
        $this->links();

        if ($this->option('receita')) {
            $this->newLine();
            $this->receitas();
        }

        return self::SUCCESS;
    }

    private function confirmacoes(): void
    {
        $this->line('<options=bold>CONFIRMAÇÕES (driver_pix_profile_months)</>');
        $this->line('  A competência gravada x quando o motorista realmente enviou.');
        $this->newLine();

        $linhas = DB::table('driver_pix_profile_months')
            ->selectRaw('reference_month, COUNT(*) as qtd')
            ->selectRaw('MIN(submitted_at) as primeiro, MAX(submitted_at) as ultimo')
            ->groupBy('reference_month')
            ->orderBy('reference_month')
            ->get();

        if ($linhas->isEmpty()) {
            $this->line('  (nenhuma)');

            return;
        }

        $this->table(
            ['Competência', 'Qtd', 'Primeiro envio', 'Último envio', 'Mês do envio'],
            $linhas->map(fn ($l) => [
                $this->rotulo($l->reference_month),
                $l->qtd,
                $this->dataHora($l->primeiro),
                $this->dataHora($l->ultimo),
                $this->mesesDoEnvio($l->reference_month),
            ])->all()
        );
    }

    /**
     * Em que mês caíram os envios dessa competência. Se competência e mês de
     * envio batem, o selo é "mês do acesso"; se o envio é sempre um mês
     * depois, o selo é o mês dos ganhos.
     */
    private function mesesDoEnvio($referenceMonth): string
    {
        // Agrupado em PHP de propósito: DATE_FORMAT é só do MySQL e este
        // diagnóstico precisa rodar igual em qualquer banco.
        $meses = DB::table('driver_pix_profile_months')
            ->where('reference_month', $referenceMonth)
            ->whereNotNull('submitted_at')
            ->pluck('submitted_at')
            ->map(fn ($d) => Carbon::parse($d)->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        return $meses->isEmpty() ? '—' : $meses->implode(', ');
    }

    private function pagamentos(): void
    {
        $this->line('<options=bold>PAGAMENTOS (driver_pix_payments)</>');
        $this->line('  A competência do pagamento x quando o dinheiro saiu. ESTA é a tabela que responde a pergunta.');
        $this->newLine();

        $linhas = DB::table('driver_pix_payments')
            ->selectRaw('reference_month, status, COUNT(*) as qtd, SUM(amount) as total')
            ->selectRaw('MIN(paid_at) as primeiro_pago, MAX(paid_at) as ultimo_pago')
            ->groupBy('reference_month', 'status')
            ->orderBy('reference_month')
            ->orderBy('status')
            ->get();

        if ($linhas->isEmpty()) {
            $this->line('  (nenhum)');

            return;
        }

        $this->table(
            ['Competência', 'Status', 'Qtd', 'Total', 'Primeiro pago', 'Último pago'],
            $linhas->map(fn ($l) => [
                $this->rotulo($l->reference_month),
                $l->status,
                $l->qtd,
                'R$ ' . number_format((float) $l->total, 2, ',', '.'),
                $this->dataHora($l->primeiro_pago),
                $this->dataHora($l->ultimo_pago),
            ])->all()
        );
    }

    private function links(): void
    {
        $this->line('<options=bold>LINKS DE CADASTRO</>');
        $this->newLine();

        $linhas = DB::table('driver_pix_registration_links')
            ->select('id', 'label', 'reference_month', 'is_active', 'uses_count', 'created_at')
            ->orderBy('id')
            ->get();

        if ($linhas->isEmpty()) {
            $this->line('  (nenhum)');

            return;
        }

        $this->table(
            ['#', 'Rótulo', 'Competência', 'Ativo', 'Usos', 'Criado'],
            $linhas->map(fn ($l) => [
                $l->id,
                mb_strimwidth((string) $l->label, 0, 28, '…'),
                $l->reference_month
                    ? $this->rotulo($l->reference_month)
                    : '<fg=red>MÊS CORRENTE (NULL)</>',
                $l->is_active ? 'sim' : 'não',
                $l->uses_count,
                $this->dataHora($l->created_at),
            ])->all()
        );
    }

    private function receitas(): void
    {
        $this->line('<options=bold>RECEITA POR MÊS (calculada ao vivo de payments.paid_at)</>');
        $this->newLine();

        $meses = DB::table('driver_pix_profile_months')
            ->selectRaw('DISTINCT reference_month')
            ->orderBy('reference_month')
            ->pluck('reference_month');

        $dados = [];

        foreach ($meses as $mes) {
            $ym = Carbon::parse($mes)->format('Y-m');
            $receita = collect(DriverPrizeService::receitaPorSerial($ym))->sum('receita');

            $dados[] = [
                $this->rotulo($mes),
                'R$ ' . number_format((float) $receita, 2, ',', '.'),
            ];
        }

        $this->table(['Mês', 'Receita total'], $dados);
    }

    private function rotulo($valor): string
    {
        return $valor
            ? DriverPixProfileMonth::labelFor(Carbon::parse($valor))
            : '—';
    }

    private function dataHora($valor): string
    {
        return $valor ? Carbon::parse($valor)->format('d/m/Y H:i') : '—';
    }
}
