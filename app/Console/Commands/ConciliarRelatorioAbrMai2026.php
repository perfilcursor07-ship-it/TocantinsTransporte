<?php

namespace App\Console\Commands;

use App\Models\Bus;
use App\Models\BusRevenueAdjustment;
use App\Services\BusRevenueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Concilia a receita por ônibus de 20/04 a 20/05/2026 com o relatório
 * financeiro emitido em 20/05/2026.
 *
 * Por que existe: desde maio, pagamentos daquele período saíram do banco e
 * alguns valores foram editados à mão para tentar fechar a conta. O relatório
 * do sistema passou a divergir do que foi emitido na época.
 *
 * O que faz, nesta ordem:
 *  1. devolve ao valor original os pagamentos do período com valor editado em
 *     10/11/2026 (o original está em payment_data.amount_edited_from);
 *  2. remove os acréscimos manuais que já existiam no período;
 *  3. lança, por ônibus, a diferença exata para o relatório de 20/05,
 *     dividida pelos dias do período (20/04 a 20/05) e identificada na
 *     observação como conciliação.
 *
 * Nenhum pagamento de passageiro é criado ou apagado. O ajuste é registro
 * próprio (bus_revenue_adjustments): não libera internet nem aparece na lista
 * de pagamentos.
 *
 * Simula por padrão. Só grava com --aplicar. --desfazer devolve tudo.
 */
class ConciliarRelatorioAbrMai2026 extends Command
{
    protected $signature = 'relatorio:conciliar-abr-mai-2026
                            {--aplicar : Grava as alterações (sem isto, apenas simula)}
                            {--desfazer : Desfaz a conciliação gravada por este comando}';

    protected $description = 'Concilia a receita por ônibus de 20/04 a 20/05/2026 com o relatório emitido em 20/05/2026';

    private const INICIO = '2026-04-20 00:00:00';

    private const FIM = '2026-05-20 23:59:00';

    private const NOTA = 'Conciliação com o relatório financeiro de 20/05/2026 (período 20/04 a 20/05)';

    private const BACKUP = 'receita-por-dia/conciliacao-abr-mai-2026.json';

    /**
     * Relatório de 20/05/2026: [serial => [receita, pagamentos]].
     * Total: R$ 3.076,31 em 469 pagamentos.
     */
    private const ALVO = [
        'HH50A2ER2JB' => [629.05, 95],
        'HGJ09X2F8FD' => [614.08, 92],
        'HGK09Q76FMP' => [528.18, 82],
        'HGD09YS6037' => [442.33, 67],
        'HH60A2NSBE7' => [300.53, 47],
        'HH50A7TMT8M' => [222.66, 34],
        'HH50AB8F056' => [193.72, 28],
        'HH50A914NK5' => [145.76, 24],
    ];

    public function handle(): int
    {
        if ($this->option('desfazer')) {
            return $this->desfazer();
        }

        if (Storage::disk('local')->exists(self::BACKUP)) {
            $this->warn('Esta conciliação já foi aplicada neste servidor. Use --desfazer antes de repetir.');

            return self::SUCCESS;
        }

        $aplicar = (bool) $this->option('aplicar');
        $backup = null;

        // A simulação é a execução de verdade, desfeita no final: o que ela
        // mostra é exatamente o que o --aplicar grava.
        DB::beginTransaction();

        try {
            $backup = $this->executar();
            $this->conferir();

            if ($aplicar) {
                Storage::disk('local')->put(self::BACKUP, json_encode($backup));
                DB::commit();
            } else {
                DB::rollBack();
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->newLine();
            $this->error('Nada foi alterado: ' . $e->getMessage());

            if (str_contains($e->getMessage(), 'Out of range')) {
                $this->line('Rode "php artisan migrate" antes: o ajuste negativo depende da migration nova.');
            }

            return self::FAILURE;
        }

        $this->newLine();
        $aplicar
            ? $this->info('Conciliação gravada. Para voltar atrás: php artisan relatorio:conciliar-abr-mai-2026 --desfazer')
            : $this->comment('Simulação — nada foi gravado. Repita com --aplicar para efetivar.');

        return self::SUCCESS;
    }

    private function executar(): array
    {
        $this->info(($this->option('aplicar') ? 'APLICANDO' : 'SIMULAÇÃO —') . ' conciliação de 20/04 a 20/05/2026');
        $this->newLine();

        return [
            'gerado_em' => now()->toDateTimeString(),
            'pagamentos' => $this->restaurarValoresEditados(),
            'ajustes_removidos' => $this->removerAjustesAntigos(),
            'ajustes_criados' => $this->lancarAjustes(),
        ];
    }

    /** Passo 1: valores editados em 10/11 voltam ao que o passageiro pagou. */
    private function restaurarValoresEditados(): array
    {
        $editados = DB::table('payments')
            ->whereBetween('created_at', [self::INICIO, self::FIM])
            ->whereNotNull('payment_data->amount_edited_from')
            ->where('payment_data->amount_edited_at', '>=', '2026-11-10 00:00:00')
            ->orderBy('id')
            ->get(['id', 'amount', 'payment_data']);

        $restaurados = [];
        $linhas = [];

        foreach ($editados as $p) {
            $original = round((float) (json_decode($p->payment_data, true)['amount_edited_from'] ?? 0), 2);
            if ($original <= 0 || abs($original - (float) $p->amount) < 0.005) {
                continue;
            }

            // Só o valor: payment_data fica como está, com o registro da edição.
            DB::table('payments')->where('id', $p->id)->update(['amount' => $original]);

            $restaurados[] = ['id' => $p->id, 'valor_editado' => (float) $p->amount];
            $linhas[] = ['#' . $p->id, $this->brl((float) $p->amount), $this->brl($original)];
        }

        $this->line('<options=bold>1. Pagamentos com valor editado em 10/11, de volta ao original</>');
        $linhas ? $this->table(['Pagamento', 'Estava', 'Volta para'], $linhas) : $this->line('  (nenhum)');

        return $restaurados;
    }

    /** Passo 2: acréscimos que já existiam no período saem, para não somar duas vezes. */
    private function removerAjustesAntigos(): array
    {
        $antigos = DB::table('bus_revenue_adjustments')
            ->whereBetween('reference_at', [self::INICIO, self::FIM])
            ->orderBy('id')
            ->get();

        $this->newLine();
        $this->line('<options=bold>2. Acréscimos manuais que já existiam no período (removidos)</>');

        if ($antigos->isEmpty()) {
            $this->line('  (nenhum)');
        }

        foreach ($antigos as $a) {
            $this->line(sprintf('  #%d  %s  %d pgto(s)  %s', $a->id, $a->bus_mikrotik_id, $a->quantity, $this->brl((float) $a->total)));
        }

        DB::table('bus_revenue_adjustments')->whereIn('id', $antigos->pluck('id'))->delete();

        return $antigos->map(fn ($a) => (array) $a)->all();
    }

    /** Passo 3: a diferença exata de cada ônibus para o relatório, dividida pelos dias. */
    private function lancarAjustes(): array
    {
        $atual = $this->receitaAtual();
        $nomes = Bus::getSerialNameMap();
        $criados = [];
        $linhas = [];

        // Ônibus fora do relatório de 20/05 têm de fechar em zero.
        $seriais = collect(array_keys(self::ALVO))->merge(array_keys($atual))->unique();

        foreach ($seriais as $serial) {
            [$alvoTotal, $alvoQtd] = self::ALVO[$serial] ?? [0.0, 0];
            [$temTotal, $temQtd] = $atual[$serial] ?? [0.0, 0];

            $difTotal = round($alvoTotal - $temTotal, 2);
            $difQtd = $alvoQtd - $temQtd;

            $linhas[] = [
                $nomes[$serial] ?? $serial,
                $this->brl($temTotal) . ' / ' . $temQtd,
                $this->brl($alvoTotal) . ' / ' . $alvoQtd,
                ($difTotal >= 0 ? '+' : '−') . $this->brl(abs($difTotal)) . ' / ' . ($difQtd >= 0 ? '+' : '') . $difQtd,
            ];

            if (abs($difTotal) < 0.005 && $difQtd === 0) {
                continue;
            }

            foreach ($this->dividirPelosDias($difQtd, $difTotal) as $dia => [$qtd, $total]) {
                $criados[] = BusRevenueAdjustment::create([
                    'bus_mikrotik_id' => $serial,
                    'quantity' => $qtd,
                    'unit_amount' => $qtd !== 0 ? round(abs($total / $qtd), 2) : abs($total),
                    'total' => $total,
                    'reference_at' => $dia . ' 12:00:00',
                    'note' => self::NOTA,
                    'created_by' => null,
                ])->id;
            }
        }

        $this->newLine();
        $this->line('<options=bold>3. Ajuste de conciliação por ônibus (receita / pagamentos)</>');
        $this->table(['Ônibus', 'Banco hoje', 'Relatório 20/05', 'Ajuste (dividido pelos dias)'], $linhas);

        return $criados;
    }

    /**
     * Divide o ajuste de um ônibus pelos dias do período, de 20/04 a 20/05,
     * para o gráfico "Receita por dia" não ganhar um degrau num dia só.
     *
     * Os pagamentos são espalhados em intervalos iguais; cada dia leva a parte
     * do valor proporcional aos pagamentos que recebeu, e os centavos que
     * sobram do arredondamento vão para o último dia — a soma fecha exata.
     *
     * @return array<string, array{0: int, 1: float}> ['2026-04-20' => [qtd, total], ...]
     */
    private function dividirPelosDias(int $qtd, float $total): array
    {
        $inicio = Carbon::parse(self::INICIO)->startOfDay();
        $dias = (int) $inicio->diffInDays(Carbon::parse(self::FIM)->startOfDay()) + 1;

        // Sem pagamentos para espalhar (só diferença de valor): um centavo de
        // quantidade não existe, então o valor é que se divide por igual.
        $partes = $qtd !== 0 ? abs($qtd) : $dias;
        $sinal = $qtd < 0 ? -1 : 1;

        $porDia = [];
        for ($i = 0; $i < $partes; $i++) {
            $dia = $inicio->copy()->addDays((int) floor(($i + 0.5) * $dias / $partes))->toDateString();
            $porDia[$dia] = ($porDia[$dia] ?? 0) + 1;
        }

        $saida = [];
        $distribuido = 0.0;
        $ultimo = array_key_last($porDia);

        foreach ($porDia as $dia => $n) {
            $valor = $dia === $ultimo
                ? round($total - $distribuido, 2)
                : round($total * $n / $partes, 2);
            $distribuido += $valor;

            $saida[$dia] = [$qtd !== 0 ? $sinal * $n : 0, $valor];
        }

        return $saida;
    }

    /** Receita e quantidade por ônibus, pelo MESMO cálculo da tela do relatório. */
    private function receitaAtual(): array
    {
        return app(BusRevenueService::class)
            ->byBus(Carbon::parse(self::INICIO), Carbon::parse(self::FIM))
            ->filter(fn ($b) => abs($b->total) >= 0.005 || $b->count !== 0)
            ->mapWithKeys(fn ($b) => [$b->bus_id => [round((float) $b->total, 2), (int) $b->count]])
            ->all();
    }

    /** Depois de tudo, a tela tem de mostrar exatamente o relatório de 20/05. */
    private function conferir(): void
    {
        $depois = $this->receitaAtual();

        foreach (self::ALVO as $serial => [$total, $qtd]) {
            [$temTotal, $temQtd] = $depois[$serial] ?? [0.0, 0];

            if (abs($temTotal - $total) >= 0.005 || $temQtd !== $qtd) {
                throw new \RuntimeException("o ônibus {$serial} não fechou com o relatório de 20/05.");
            }
        }

        if (count($depois) !== count(self::ALVO)) {
            throw new \RuntimeException('sobrou receita em ônibus fora do relatório de 20/05.');
        }

        $this->info(sprintf(
            'Conferido: %d ônibus, %s em %d pagamentos — igual ao relatório de 20/05/2026.',
            count($depois),
            $this->brl(array_sum(array_column($depois, 0))),
            array_sum(array_column($depois, 1))
        ));
    }

    private function desfazer(): int
    {
        if (! Storage::disk('local')->exists(self::BACKUP)) {
            $this->error('Não há conciliação registrada neste servidor.');

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get(self::BACKUP), true);

        DB::transaction(function () use ($backup) {
            DB::table('bus_revenue_adjustments')->whereIn('id', $backup['ajustes_criados'] ?? [])->delete();

            foreach ($backup['ajustes_removidos'] ?? [] as $ajuste) {
                DB::table('bus_revenue_adjustments')->insert($ajuste);
            }

            foreach ($backup['pagamentos'] ?? [] as $p) {
                DB::table('payments')->where('id', $p['id'])->update(['amount' => $p['valor_editado']]);
            }
        });

        Storage::disk('local')->delete(self::BACKUP);
        $this->info('Conciliação desfeita: ajustes removidos, acréscimos antigos e valores editados de volta.');

        return self::SUCCESS;
    }

    private function brl(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }
}
