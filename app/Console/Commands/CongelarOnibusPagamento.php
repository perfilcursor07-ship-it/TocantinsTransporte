<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Preenche payments.bus_mikrotik_id onde ainda está nulo.
 *
 * Serve a dois propósitos:
 *
 * 1. Backfill do histórico. ATENÇÃO: para pagamentos antigos isso congela a
 *    atribuição de HOJE, que já pode ter migrado de carro. Não recupera a
 *    divisão original — não existe trilha disso no banco. O ganho é parar a
 *    oscilação a partir de agora.
 *
 * 2. Rede de segurança dos pagamentos novos. O congelamento normal acontece na
 *    criação do pagamento e na detecção do ônibus pelo IP; se algum caminho
 *    escapar, rodar isto de minutos em minutos fecha a janela enquanto o
 *    passageiro ainda está no mesmo carro.
 */
class CongelarOnibusPagamento extends Command
{
    protected $signature = 'pagamentos:congelar-onibus
                            {--desde= : Só pagamentos criados a partir desta data (AAAA-MM-DD)}
                            {--ate= : Só pagamentos criados até esta data (AAAA-MM-DD)}
                            {--aplicar : Grava (sem isto, apenas simula)}';

    protected $description = 'Congela o ônibus nos pagamentos que ainda não têm (payments.bus_mikrotik_id)';

    /** Tempo para a viagem identificar o carro antes de travar em 'desconhecido'. */
    private const HORAS_PARA_TRAVAR_DESCONHECIDO = 6;

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');

        $base = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->whereNull('payments.bus_mikrotik_id')
            ->whereNotNull('users.last_mikrotik_id')
            ->when($this->option('desde'), fn ($q, $d) => $q->whereDate('payments.created_at', '>=', $d))
            ->when($this->option('ate'), fn ($q, $d) => $q->whereDate('payments.created_at', '<=', $d));

        $porOnibus = (clone $base)
            ->groupBy('users.last_mikrotik_id')
            ->selectRaw('users.last_mikrotik_id as serial, COUNT(*) as qtd, SUM(payments.amount) as total')
            ->orderByDesc('qtd')
            ->get();

        $semOnibus = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->whereNull('payments.bus_mikrotik_id')
            ->whereNull('users.last_mikrotik_id')
            ->when($this->option('desde'), fn ($q, $d) => $q->whereDate('payments.created_at', '>=', $d))
            ->when($this->option('ate'), fn ($q, $d) => $q->whereDate('payments.created_at', '<=', $d))
            ->count();

        // Pagamento cujo passageiro nunca foi identificado em carro nenhum. Sem
        // trava, ele fica em "desconhecido" só até o passageiro se conectar num
        // ônibus algum dia: aí last_mikrotik_id aparece e o pagamento antigo
        // muda de carro num mês já fechado (o total não muda, o carro muda).
        // Passado o prazo para a própria viagem identificar o carro, trava
        // como 'desconhecido' — a soma do relatório lê esse valor direto.
        $travarDesconhecidos = DB::table('payments')
            ->whereNull('bus_mikrotik_id')
            ->where('created_at', '<', now()->subHours(self::HORAS_PARA_TRAVAR_DESCONHECIDO))
            ->whereIn('user_id', DB::table('users')->whereNull('last_mikrotik_id')->select('id'))
            ->when($this->option('desde'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($this->option('ate'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d));

        if ($aplicar) {
            $travados = $travarDesconhecidos->update(['bus_mikrotik_id' => 'desconhecido']);
            if ($travados > 0) {
                $this->info("{$travados} pagamento(s) sem ônibus travados como 'desconhecido'.");
            }
            $semOnibus -= $travados;
        } elseif (($qtd = $travarDesconhecidos->count()) > 0) {
            $this->line("{$qtd} pagamento(s) sem ônibus seriam travados como 'desconhecido'.");
        }

        if ($porOnibus->isEmpty()) {
            $this->info('Nada a congelar: todos os pagamentos do filtro já têm ônibus congelado.');

            if ($semOnibus > 0) {
                $this->warn("{$semOnibus} pagamento(s) seguem sem ônibus: o passageiro nunca teve ônibus registrado.");
            }

            return self::SUCCESS;
        }

        $this->line($aplicar ? '<options=bold>APLICANDO</>' : '<options=bold>SIMULAÇÃO</>');
        $this->newLine();

        $this->table(
            ['Ônibus (serial)', 'Pagamentos', 'Valor'],
            $porOnibus->map(fn ($l) => [
                $l->serial,
                $l->qtd,
                'R$ ' . number_format((float) $l->total, 2, ',', '.'),
            ])->all()
        );

        $totalLinhas = (int) $porOnibus->sum('qtd');
        $this->line("  Total a congelar: {$totalLinhas} pagamento(s).");

        if ($semOnibus > 0) {
            $this->warn("  {$semOnibus} pagamento(s) ficam de fora: o passageiro não tem ônibus registrado.");
        }

        if (! $aplicar) {
            $this->newLine();
            $this->comment('Simulação — nada gravado. Repita com --aplicar.');

            return self::SUCCESS;
        }

        // Em lotes, agrupando por serial: um UPDATE por ônibus por lote.
        // Evita UPDATE ... JOIN, que o MySQL aceita mas o SQLite não — assim o
        // comando é testável e não depende do banco.
        $afetados = 0;
        $barra = $this->output->createProgressBar($totalLinhas);
        $barra->start();

        do {
            $lote = (clone $base)
                ->orderBy('payments.id')
                ->limit(500)
                ->get(['payments.id as payment_id', 'users.last_mikrotik_id as serial']);

            if ($lote->isEmpty()) {
                break;
            }

            foreach ($lote->groupBy('serial') as $serial => $linhas) {
                $afetados += DB::table('payments')
                    ->whereIn('id', $linhas->pluck('payment_id'))
                    ->whereNull('bus_mikrotik_id')
                    ->update(['bus_mikrotik_id' => $serial]);
            }

            $barra->advance($lote->count());
        } while ($lote->count() === 500);

        $barra->finish();

        $this->newLine(2);
        $this->info("Congelado em {$afetados} pagamento(s).");

        return self::SUCCESS;
    }
}
