<?php

namespace App\Console\Commands;

use App\Models\DriverPixProfileMonth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Espalha pelos dias do período os pagamentos lançados em bloco num dia só.
 *
 * Contexto: pagamentos concluídos de vários períodos de 2026 foram
 * recarregados no banco todos com a mesma data e hora (ex.: dia 15 às 12:00,
 * ou 01/06 às 12:00), então o gráfico "Receita por dia" do relatório mostra
 * um ponto só. O dia real de cada pagamento não existe mais no banco.
 *
 * Aceita um mês cheio (AAAA-MM) ou um período do relatório
 * (--inicio=AAAA-MM-DD --fim=AAAA-MM-DD, os dois dias inclusive).
 *
 * O que este comando faz é uma ESTIMATIVA: cada pagamento recebe a data e a
 * hora de uma conexão real (wifi_sessions) do mesmo ônibus no mesmo período, de
 * modo que os dias com mais movimento recebem mais pagamentos.
 *
 * O que NÃO muda: valor, ônibus, status e quantidade; nenhum pagamento sai do
 * período informado.
 * Logo, receita total, receita por ônibus, ticket médio e prêmio de motorista
 * ficam idênticos — o comando confere isso e desfaz tudo se algo divergir.
 *
 * Simula por padrão. Só grava com --aplicar. --desfazer volta à data do bloco.
 */
class DistribuirReceitaPorDia extends Command
{
    protected $signature = 'relatorio:distribuir-receita-por-dia
                            {mes? : Mês a distribuir, no formato AAAA-MM}
                            {--inicio= : Primeiro dia do período (AAAA-MM-DD), no lugar do mês}
                            {--fim= : Último dia do período (AAAA-MM-DD), inclusive}
                            {--aplicar : Grava as alterações (sem isto, apenas simula)}
                            {--desfazer : Devolve à data original o que este comando distribuiu}';

    protected $description = 'Distribui (estimativa) pelos dias do período os pagamentos carregados em bloco num dia só';

    private Carbon $inicio;

    /** Exclusivo: primeiro instante DEPOIS do período. */
    private Carbon $fim;

    /** Identifica o período no arquivo de backup: "2026-10" ou "2026-05-21_2026-06-20". */
    private string $chave;

    /** Um bloco é uma mesma data/hora repetida em pelo menos tantos pagamentos. */
    private const MINIMO_DO_BLOCO = 10;

    public function handle(): int
    {
        if (! $this->lerPeriodo()) {
            return self::FAILURE;
        }

        if ($this->option('desfazer')) {
            return $this->desfazer();
        }

        $pagamentos = $this->pagamentosEmBloco();

        if ($pagamentos->isEmpty()) {
            $this->warn('Nenhum pagamento em bloco (mesma data e hora) nesse período. Nada a fazer.');

            return self::SUCCESS;
        }

        $novasDatas = $this->estimarDatas($pagamentos);
        $this->relatorio($pagamentos, $novasDatas);

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->comment('Simulação — nada foi gravado. Repita com --aplicar para efetivar.');

            return self::SUCCESS;
        }

        return $this->gravar($pagamentos, $novasDatas);
    }

    /** Mês cheio (argumento) ou período do relatório (--inicio/--fim). */
    private function lerPeriodo(): bool
    {
        $inicio = (string) $this->option('inicio');
        $fim = (string) $this->option('fim');

        if ($inicio !== '' || $fim !== '') {
            $formato = '/^\d{4}-\d{2}-\d{2}$/';
            if (! preg_match($formato, $inicio) || ! preg_match($formato, $fim)) {
                $this->error('Informe --inicio e --fim no formato AAAA-MM-DD, ex.: --inicio=2026-05-21 --fim=2026-06-20.');

                return false;
            }

            $this->inicio = Carbon::parse($inicio)->startOfDay();
            $this->fim = Carbon::parse($fim)->startOfDay()->addDay();
            $this->chave = $inicio . '_' . $fim;

            if ($this->fim->lte($this->inicio)) {
                $this->error('O fim do período tem de ser depois do início.');

                return false;
            }

            return true;
        }

        if (! DriverPixProfileMonth::isValidMonthInput($this->argument('mes'))) {
            $this->error('Informe o mês (AAAA-MM, ex.: 2026-10) ou o período com --inicio e --fim.');

            return false;
        }

        // normalizeMonth monta a data por ano/mês: não transborda em dias 29-31.
        $this->inicio = DriverPixProfileMonth::normalizeMonth($this->argument('mes'));
        $this->fim = $this->inicio->copy()->addMonthNoOverflow();
        $this->chave = $this->inicio->format('Y-m');

        return true;
    }

    private function rotuloDoPeriodo(): string
    {
        return $this->inicio->format('d/m/Y') . ' a ' . $this->fim->copy()->subDay()->format('d/m/Y');
    }

    private function arquivoDeBackup(): string
    {
        return 'receita-por-dia/' . $this->chave . '.json';
    }

    /** Concluídos sem dados de PIX e com criação = pagamento: o formato da carga em bloco. */
    private function candidatos()
    {
        return DB::table('payments')
            ->where('status', 'completed')
            ->where('created_at', '>=', $this->inicio)
            ->where('created_at', '<', $this->fim)
            ->whereColumn('created_at', 'paid_at')
            ->whereNull('payment_data')
            ->whereNull('pix_emv_string');
    }

    /**
     * Só os registros da carga em bloco: além do formato acima, a mesma data e
     * hora tem de se repetir em vários pagamentos. Pagamento real, com dados
     * de PIX e horário próprio, nunca casa com isso.
     */
    private function pagamentosEmBloco()
    {
        $blocos = $this->candidatos()
            ->groupBy('created_at')
            ->havingRaw('COUNT(*) >= ?', [self::MINIMO_DO_BLOCO])
            ->pluck('created_at');

        if ($blocos->isEmpty()) {
            return collect();
        }

        return $this->candidatos()
            ->whereIn('created_at', $blocos)
            ->orderBy('id')
            ->get(['id', 'amount', 'bus_mikrotik_id', 'created_at']);
    }

    /**
     * Horários reais de conexão no período, por ônibus: [serial => [datetime, ...]].
     * A chave '*' junta todos, para ônibus sem conexão registrada.
     */
    private function conexoesPorOnibus(): array
    {
        $linhas = DB::table('wifi_sessions')
            ->leftJoin('users', 'users.id', '=', 'wifi_sessions.user_id')
            ->where('wifi_sessions.created_at', '>=', $this->inicio)
            ->where('wifi_sessions.created_at', '<', $this->fim)
            ->orderBy('wifi_sessions.created_at')
            ->orderBy('wifi_sessions.id')
            ->get(['wifi_sessions.created_at as quando', 'users.last_mikrotik_id as serial']);

        $pools = ['*' => []];
        foreach ($linhas as $l) {
            $pools['*'][] = $l->quando;
            if ($l->serial) {
                $pools[$l->serial][] = $l->quando;
            }
        }

        return $pools;
    }

    /** @return array<int, string> [id do pagamento => nova data] */
    private function estimarDatas($pagamentos): array
    {
        $pools = $this->conexoesPorOnibus();
        $novas = [];

        foreach ($pagamentos->groupBy('bus_mikrotik_id') as $serial => $doOnibus) {
            // Poucas conexões no ônibus distorcem a curva: usa o movimento geral.
            $pool = count($pools[$serial] ?? []) >= 10 ? $pools[$serial] : $pools['*'];
            $n = $doOnibus->count();
            $m = count($pool);

            foreach ($doOnibus->values() as $i => $pagamento) {
                $novas[$pagamento->id] = $m > 0
                    // Percorre as conexões em passos iguais: dia movimentado recebe mais.
                    ? $this->dentroDoMes($pool[(int) floor(($i + 0.5) * $m / $n)])
                    : $this->uniforme($i, $n);
            }
        }

        return $novas;
    }

    /**
     * O relatório filtra até "último dia 23:59" (segundo 00): um pagamento às
     * 23:59:30 ficaria de fora e mudaria o total. Puxa para 23:58 nesse caso.
     */
    private function dentroDoMes(string $quando): string
    {
        $data = Carbon::parse($quando);
        $limite = $this->fim->copy()->subMinute(); // último dia, 23:59:00

        return ($data->gte($limite) ? $limite->copy()->subMinute() : $data)->format('Y-m-d H:i:s');
    }

    /** Sem nenhuma conexão no período: espalha por igual, ao meio-dia. */
    private function uniforme(int $i, int $n): string
    {
        $dia = (int) floor($i * $this->inicio->diffInDays($this->fim) / $n);

        return $this->inicio->copy()->addDays($dia)->setTime(12, 0)->format('Y-m-d H:i:s');
    }

    private function relatorio($pagamentos, array $novas): void
    {
        $this->info(sprintf(
            '%s %s — %d pagamento(s), R$ %s',
            $this->option('aplicar') ? 'APLICANDO' : 'SIMULAÇÃO —',
            $this->rotuloDoPeriodo(),
            $pagamentos->count(),
            number_format((float) $pagamentos->sum('amount'), 2, ',', '.')
        ));
        $this->line('Hoje estão todos em: ' . $pagamentos->pluck('created_at')->unique()
            ->map(fn ($d) => Carbon::parse($d)->format('d/m/Y H:i'))->implode(', ') . '.');
        $this->line('<comment>A distribuição é uma estimativa pelo movimento de conexões, não o histórico real.</comment>');
        $this->newLine();

        $porDia = [];
        foreach ($pagamentos as $p) {
            $dia = substr($novas[$p->id], 0, 10);
            $porDia[$dia]['qtd'] = ($porDia[$dia]['qtd'] ?? 0) + 1;
            $porDia[$dia]['total'] = ($porDia[$dia]['total'] ?? 0) + (float) $p->amount;
        }
        ksort($porDia);

        $this->table(['Dia', 'Pagamentos', 'Receita'], collect($porDia)->map(fn ($d, $dia) => [
            Carbon::parse($dia)->format('d/m'),
            $d['qtd'],
            'R$ ' . number_format($d['total'], 2, ',', '.'),
        ])->values()->all());

        $this->line(sprintf('%d dia(s) com receita. Total por ônibus e do período não mudam.', count($porDia)));
    }

    /** Receita e quantidade por ônibus no período — a foto que não pode mudar. */
    private function totaisPorOnibus(): array
    {
        return DB::table('payments')
            ->where('status', 'completed')
            ->where('created_at', '>=', $this->inicio)
            // Mesmo corte do relatório: até o último dia às 23:59:00.
            ->where('created_at', '<=', $this->fim->copy()->subMinute())
            ->groupBy('bus_mikrotik_id')
            ->orderBy('bus_mikrotik_id')
            ->selectRaw("COALESCE(bus_mikrotik_id, '') as serial, COUNT(*) as qtd, SUM(amount) as total")
            ->get()
            ->mapWithKeys(fn ($l) => [$l->serial => $l->qtd . '|' . number_format((float) $l->total, 2, '.', '')])
            ->all();
    }

    private function gravar($pagamentos, array $novas): int
    {
        $antes = $this->totaisPorOnibus();

        // Guarda os ids antes de mexer: é o que permite o --desfazer.
        Storage::disk('local')->put($this->arquivoDeBackup(), json_encode([
            'periodo' => $this->chave,
            'gerado_em' => now()->toDateTimeString(),
            // id => data original, para o --desfazer devolver cada um ao seu bloco.
            'originais' => $pagamentos->pluck('created_at', 'id')->all(),
        ]));

        try {
            DB::transaction(function () use ($novas, $antes) {
                foreach ($novas as $id => $quando) {
                    // DB::table de propósito: sem eventos de model, nada de
                    // WhatsApp, liberação de internet ou conciliação disparados.
                    DB::table('payments')->where('id', $id)->update([
                        'created_at' => $quando,
                        'paid_at' => $quando,
                        'updated_at' => $quando,
                    ]);
                }

                if ($this->totaisPorOnibus() !== $antes) {
                    throw new \RuntimeException('Os totais por ônibus mudaram. Alteração desfeita.');
                }
            });
        } catch (\Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info(sprintf(
            'Pronto: %d pagamento(s) distribuídos. Totais por ônibus conferidos e iguais.',
            count($novas)
        ));
        $this->line('Para voltar atrás, repita o mesmo comando trocando --aplicar por --desfazer.');

        return self::SUCCESS;
    }

    private function desfazer(): int
    {
        if (! Storage::disk('local')->exists($this->arquivoDeBackup())) {
            $this->error('Não há distribuição registrada para esse período neste servidor.');

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($this->arquivoDeBackup()), true);
        // Backups antigos (só mês) guardavam uma lista de ids e uma data única.
        $originais = $backup['originais']
            ?? array_fill_keys($backup['ids'] ?? [], $backup['data_original'] ?? null);

        $feitos = 0;
        DB::transaction(function () use ($originais, &$feitos) {
            foreach (collect($originais)->filter()->groupBy(fn ($data) => $data, true) as $data => $doBloco) {
                foreach (array_chunk($doBloco->keys()->all(), 500) as $lote) {
                    $feitos += DB::table('payments')
                        ->whereIn('id', $lote)
                        ->where('status', 'completed')
                        ->where('created_at', '>=', $this->inicio)
                        ->where('created_at', '<', $this->fim)
                        ->update(['created_at' => $data, 'paid_at' => $data, 'updated_at' => $data]);
                }
            }
        });

        Storage::disk('local')->delete($this->arquivoDeBackup());
        $this->info("Desfeito: {$feitos} pagamento(s) de volta à data original.");

        return self::SUCCESS;
    }
}
