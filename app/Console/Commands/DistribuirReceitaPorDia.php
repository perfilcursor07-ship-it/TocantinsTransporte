<?php

namespace App\Console\Commands;

use App\Models\DriverPixProfileMonth;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Espalha pelos dias do mês os pagamentos lançados em bloco no dia 15 às 12:00.
 *
 * Contexto: os pagamentos concluídos de Setembro e Outubro/2026 foram
 * recarregados no banco todos com a mesma data (dia 15, 12:00), então o
 * gráfico "Receita por dia" do relatório mostra um ponto só. O dia real de
 * cada pagamento não existe mais no banco.
 *
 * O que este comando faz é uma ESTIMATIVA: cada pagamento recebe a data e a
 * hora de uma conexão real (wifi_sessions) do mesmo ônibus no mesmo mês, de
 * modo que os dias com mais movimento recebem mais pagamentos.
 *
 * O que NÃO muda: valor, ônibus, status, quantidade e o mês de cada pagamento.
 * Logo, receita total, receita por ônibus, ticket médio e prêmio de motorista
 * ficam idênticos — o comando confere isso e desfaz tudo se algo divergir.
 *
 * Simula por padrão. Só grava com --aplicar. --desfazer volta ao dia 15.
 */
class DistribuirReceitaPorDia extends Command
{
    protected $signature = 'relatorio:distribuir-receita-por-dia
                            {mes : Mês a distribuir, no formato AAAA-MM}
                            {--aplicar : Grava as alterações (sem isto, apenas simula)}
                            {--desfazer : Devolve ao dia 15 às 12:00 o que este comando distribuiu}';

    protected $description = 'Distribui (estimativa) pelos dias do mês os pagamentos carregados em bloco no dia 15';

    private Carbon $inicio;

    private Carbon $fim;

    public function handle(): int
    {
        if (! DriverPixProfileMonth::isValidMonthInput($this->argument('mes'))) {
            $this->error('Mês inválido. Use o formato AAAA-MM, ex.: 2026-10.');

            return self::FAILURE;
        }

        // normalizeMonth monta a data por ano/mês: não transborda em dias 29-31.
        $this->inicio = DriverPixProfileMonth::normalizeMonth($this->argument('mes'));
        $this->fim = $this->inicio->copy()->addMonthNoOverflow();

        if ($this->option('desfazer')) {
            return $this->desfazer();
        }

        $pagamentos = $this->pagamentosEmBloco();

        if ($pagamentos->isEmpty()) {
            $this->warn('Nenhum pagamento em bloco (dia 15 às 12:00) nesse mês. Nada a fazer.');

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

    private function dataDoBloco(): string
    {
        return $this->inicio->format('Y-m') . '-15 12:00:00';
    }

    private function arquivoDeBackup(): string
    {
        return 'receita-por-dia/' . $this->inicio->format('Y-m') . '.json';
    }

    /**
     * Só os registros da carga em bloco: concluídos, criados e pagos exatamente
     * no dia 15 às 12:00 e sem dados de PIX. Pagamento real nunca casa com isso.
     */
    private function pagamentosEmBloco()
    {
        return DB::table('payments')
            ->where('status', 'completed')
            ->where('created_at', $this->dataDoBloco())
            ->where('paid_at', $this->dataDoBloco())
            ->whereNull('payment_data')
            ->whereNull('pix_emv_string')
            ->orderBy('id')
            ->get(['id', 'amount', 'bus_mikrotik_id']);
    }

    /**
     * Horários reais de conexão no mês, por ônibus: [serial => [datetime, ...]].
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

    /** Sem nenhuma conexão no mês: espalha por igual, ao meio-dia. */
    private function uniforme(int $i, int $n): string
    {
        $dia = (int) floor($i * $this->inicio->daysInMonth / $n);

        return $this->inicio->copy()->addDays($dia)->setTime(12, 0)->format('Y-m-d H:i:s');
    }

    private function relatorio($pagamentos, array $novas): void
    {
        $this->info(sprintf(
            '%s %s — %d pagamento(s), R$ %s',
            $this->option('aplicar') ? 'APLICANDO' : 'SIMULAÇÃO —',
            DriverPixProfileMonth::labelFor($this->inicio),
            $pagamentos->count(),
            number_format((float) $pagamentos->sum('amount'), 2, ',', '.')
        ));
        $this->line('Hoje estão todos em ' . Carbon::parse($this->dataDoBloco())->format('d/m/Y H:i') . '.');
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

        $this->line(sprintf('%d dia(s) com receita. Total por ônibus e do mês não mudam.', count($porDia)));
    }

    /** Receita e quantidade por ônibus no mês — a foto que não pode mudar. */
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
            'mes' => $this->inicio->format('Y-m'),
            'data_original' => $this->dataDoBloco(),
            'gerado_em' => now()->toDateTimeString(),
            'ids' => $pagamentos->pluck('id')->all(),
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
        $this->line('Para voltar atrás: php artisan relatorio:distribuir-receita-por-dia '
            . $this->inicio->format('Y-m') . ' --desfazer');

        return self::SUCCESS;
    }

    private function desfazer(): int
    {
        if (! Storage::disk('local')->exists($this->arquivoDeBackup())) {
            $this->error('Não há distribuição registrada para esse mês neste servidor.');

            return self::FAILURE;
        }

        $backup = json_decode(Storage::disk('local')->get($this->arquivoDeBackup()), true);
        $ids = $backup['ids'] ?? [];
        $original = $backup['data_original'] ?? $this->dataDoBloco();

        $feitos = 0;
        DB::transaction(function () use ($ids, $original, &$feitos) {
            foreach (array_chunk($ids, 500) as $lote) {
                $feitos += DB::table('payments')
                    ->whereIn('id', $lote)
                    ->where('status', 'completed')
                    ->where('created_at', '>=', $this->inicio)
                    ->where('created_at', '<', $this->fim)
                    ->update(['created_at' => $original, 'paid_at' => $original, 'updated_at' => $original]);
            }
        });

        Storage::disk('local')->delete($this->arquivoDeBackup());
        $this->info("Desfeito: {$feitos} pagamento(s) de volta em " . Carbon::parse($original)->format('d/m/Y H:i') . '.');

        return self::SUCCESS;
    }
}
