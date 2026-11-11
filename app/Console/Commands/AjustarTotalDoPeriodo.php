<?php

namespace App\Console\Commands;

use App\Models\BusRevenueAdjustment;
use App\Services\BusRevenueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Leva a receita de um período do relatório a um total informado, quando só
 * se conhece o total (sem a divisão por ônibus).
 *
 * A diferença entra como ajuste de conciliação (bus_revenue_adjustments),
 * dividida pelos dias do período. Não cria nem apaga pagamento de passageiro,
 * não libera internet e não aparece na lista de pagamentos.
 *
 * Como o ônibus dessa diferença não é conhecido, ela vai por padrão para
 * "desconhecido" — a mesma linha em que o relatório já põe os pagamentos sem
 * ônibus. Assim nenhum carro ganha receita que não se sabe se era dele.
 *
 * Simula por padrão. Só grava com --aplicar. --desfazer remove os ajustes.
 */
class AjustarTotalDoPeriodo extends Command
{
    protected $signature = 'relatorio:ajustar-total-do-periodo
                            {inicio : Primeiro dia do período (AAAA-MM-DD)}
                            {fim : Último dia do período (AAAA-MM-DD), inclusive}
                            {total? : Receita que o período deve mostrar, ex.: 1263.89}
                            {--onibus=desconhecido : Serial do ônibus que recebe a diferença}
                            {--valor= : Valor de cada pagamento do ajuste (padrão: o mais comum no período)}
                            {--aplicar : Grava as alterações (sem isto, apenas simula)}
                            {--desfazer : Remove os ajustes que este comando lançou no período}';

    protected $description = 'Ajusta a receita de um período do relatório para um total informado, dividindo a diferença pelos dias';

    private Carbon $inicio;

    /** Mesmo corte da tela: último dia às 23:59:00. */
    private Carbon $fim;

    public function handle(): int
    {
        $formato = '/^\d{4}-\d{2}-\d{2}$/';
        if (! preg_match($formato, (string) $this->argument('inicio')) || ! preg_match($formato, (string) $this->argument('fim'))) {
            $this->error('Informe início e fim no formato AAAA-MM-DD, ex.: 2026-02-09 2026-03-26.');

            return self::FAILURE;
        }

        $this->inicio = Carbon::parse($this->argument('inicio'))->startOfDay();
        $this->fim = Carbon::parse($this->argument('fim'))->setTime(23, 59, 0);

        if ($this->fim->lte($this->inicio)) {
            $this->error('O fim do período tem de ser depois do início.');

            return self::FAILURE;
        }

        if ($this->option('desfazer')) {
            return $this->desfazer();
        }

        if (! is_numeric($this->argument('total')) || (float) $this->argument('total') < 0) {
            $this->error('Informe o total com ponto nos centavos, ex.: 1263.89.');

            return self::FAILURE;
        }

        if (Storage::disk('local')->exists($this->arquivoDeBackup())) {
            $this->warn('Este período já foi ajustado por este comando. Use --desfazer antes de repetir.');

            return self::SUCCESS;
        }

        $alvo = round((float) $this->argument('total'), 2);
        [$atual, $pagamentos] = $this->receitaAtual();
        $diferenca = round($alvo - $atual, 2);

        $this->info(($this->option('aplicar') ? 'APLICANDO' : 'SIMULAÇÃO —') . ' ajuste de ' . $this->rotulo());
        $this->line(sprintf('Hoje o relatório mostra %s em %d pagamento(s).', $this->brl($atual), $pagamentos));
        $this->line(sprintf('Deve mostrar %s. Diferença: %s.', $this->brl($alvo), $this->brl($diferenca)));

        if (abs($diferenca) < 0.005) {
            $this->info('Já está no total informado. Nada a fazer.');

            return self::SUCCESS;
        }

        $unitario = $this->valorUnitario();
        $quantidade = (int) round($diferenca / $unitario);

        // Só lança se a diferença for um número inteiro de pagamentos: senão o
        // ticket médio do relatório passaria a mostrar um valor que não existe.
        if ($quantidade === 0 || abs($quantidade * $unitario - $diferenca) >= 0.005) {
            $this->newLine();
            $this->error(sprintf(
                'A diferença de %s não é múltipla de %s. Nada foi alterado.',
                $this->brl($diferenca),
                $this->brl($unitario)
            ));
            $this->line('Confira o total ou informe o valor de cada pagamento com --valor=.');

            return self::FAILURE;
        }

        $porDia = $this->dividirPelosDias($quantidade);

        $this->line(sprintf(
            'Ajuste: %s%d pagamento(s) de %s no ônibus "%s", divididos em %d dia(s).',
            $quantidade > 0 ? '+' : '',
            $quantidade,
            $this->brl($unitario),
            $this->option('onibus'),
            count($porDia)
        ));
        $this->newLine();
        $this->table(['Dia', 'Pagamentos', 'Valor'], collect($porDia)->map(fn ($qtd, $dia) => [
            Carbon::parse($dia)->format('d/m'),
            ($qtd > 0 ? '+' : '') . $qtd,
            $this->brl(round($qtd * $unitario, 2)),
        ])->values()->all());

        if (! $this->option('aplicar')) {
            $this->comment('Simulação — nada foi gravado. Repita com --aplicar para efetivar.');

            return self::SUCCESS;
        }

        return $this->gravar($porDia, $unitario, $alvo);
    }

    private function rotulo(): string
    {
        return $this->inicio->format('d/m/Y') . ' a ' . $this->fim->format('d/m/Y');
    }

    private function arquivoDeBackup(): string
    {
        return 'receita-por-dia/ajuste-total-' . $this->inicio->toDateString() . '_' . $this->fim->toDateString() . '.json';
    }

    /** [receita, pagamentos] pelo MESMO cálculo da tela do relatório. */
    private function receitaAtual(): array
    {
        $porOnibus = app(BusRevenueService::class)->byBus($this->inicio, $this->fim);

        return [round((float) $porOnibus->sum('total'), 2), (int) $porOnibus->sum('count')];
    }

    /** Valor de cada pagamento do ajuste: o informado ou o mais comum no período. */
    private function valorUnitario(): float
    {
        if (is_numeric($this->option('valor')) && (float) $this->option('valor') > 0) {
            return round((float) $this->option('valor'), 2);
        }

        $maisComum = DB::table('payments')
            ->where('status', 'completed')
            ->whereBetween('created_at', [$this->inicio, $this->fim])
            ->groupBy('amount')
            ->orderByRaw('COUNT(*) DESC')
            ->value('amount');

        return round((float) ($maisComum ?: 5.99), 2);
    }

    /**
     * Espalha os pagamentos em intervalos iguais do primeiro ao último dia.
     *
     * @return array<string, int> ['2026-02-09' => 2, ...]
     */
    private function dividirPelosDias(int $quantidade): array
    {
        $primeiro = $this->inicio->copy()->startOfDay();
        $dias = (int) $primeiro->diffInDays($this->fim->copy()->startOfDay()) + 1;
        $partes = abs($quantidade);
        $sinal = $quantidade < 0 ? -1 : 1;

        $porDia = [];
        for ($i = 0; $i < $partes; $i++) {
            $dia = $primeiro->copy()->addDays((int) floor(($i + 0.5) * $dias / $partes))->toDateString();
            $porDia[$dia] = ($porDia[$dia] ?? 0) + $sinal;
        }

        return $porDia;
    }

    private function gravar(array $porDia, float $unitario, float $alvo): int
    {
        $nota = 'Conciliação do período ' . $this->rotulo() . ' para o total de ' . $this->brl($alvo);

        try {
            $ids = DB::transaction(function () use ($porDia, $unitario, $alvo, $nota) {
                $ids = [];
                foreach ($porDia as $dia => $qtd) {
                    $ids[] = BusRevenueAdjustment::create([
                        'bus_mikrotik_id' => (string) $this->option('onibus'),
                        'quantity' => $qtd,
                        'unit_amount' => $unitario,
                        'total' => round($qtd * $unitario, 2),
                        'reference_at' => $dia . ' 12:00:00',
                        'note' => $nota,
                        'created_by' => null,
                    ])->id;
                }

                [$depois] = $this->receitaAtual();
                if (abs($depois - $alvo) >= 0.005) {
                    throw new \RuntimeException('o período não fechou no total informado.');
                }

                return $ids;
            });
        } catch (\Throwable $e) {
            $this->error('Nada foi alterado: ' . $e->getMessage());

            return self::FAILURE;
        }

        Storage::disk('local')->put($this->arquivoDeBackup(), json_encode([
            'gerado_em' => now()->toDateTimeString(),
            'ajustes_criados' => $ids,
        ]));

        [$depois, $pagamentos] = $this->receitaAtual();
        $this->info(sprintf('Gravado e conferido: %s em %d pagamento(s).', $this->brl($depois), $pagamentos));
        $this->line('Para voltar atrás, repita com --desfazer no lugar do total e de --aplicar.');

        return self::SUCCESS;
    }

    private function desfazer(): int
    {
        if (! Storage::disk('local')->exists($this->arquivoDeBackup())) {
            $this->error('Não há ajuste registrado para esse período neste servidor.');

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($this->arquivoDeBackup()), true);
        $removidos = DB::table('bus_revenue_adjustments')->whereIn('id', $backup['ajustes_criados'] ?? [])->delete();
        Storage::disk('local')->delete($this->arquivoDeBackup());

        $this->info("Desfeito: {$removidos} ajuste(s) removido(s).");

        return self::SUCCESS;
    }

    private function brl(float $valor): string
    {
        return ($valor < 0 ? '−' : '') . 'R$ ' . number_format(abs($valor), 2, ',', '.');
    }
}
