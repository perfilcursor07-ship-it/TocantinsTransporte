<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\DriverPixProfileMonth;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Receita e prêmio por CARRO no mês.
 *
 * O prêmio é do carro, não do motorista: se o carro tem 3 motoristas no mês,
 * o valor é dividido entre os 3.
 *
 * ATENÇÃO à atribuição da receita: o pagamento não guarda em qual ônibus foi
 * feito. O vínculo é users.last_mikrotik_id, ou seja, o ÚLTIMO ônibus em que o
 * passageiro se conectou. Para quem viaja uma vez (a maioria) é exato; para
 * quem anda em carros diferentes no mesmo mês, a receita fica no último.
 */
class DriverPrizeService
{
    /**
     * Faixas de premiação, da maior para a menor.
     * [receita mínima do carro no mês, prêmio do carro]
     */
    public const FAIXAS = [
        [1000.00, 100.00],
        [800.00, 80.00],
        [600.00, 50.00],
    ];

    /** Prêmio do carro para uma receita no mês. */
    public static function premioPara(float $receita): float
    {
        foreach (self::FAIXAS as [$minimo, $premio]) {
            if ($receita >= $minimo) {
                return $premio;
            }
        }

        return 0.0;
    }

    /** Quanto falta para a próxima faixa (null se já está na maior). */
    public static function faltaParaProximaFaixa(float $receita): ?array
    {
        $faixas = array_reverse(self::FAIXAS); // da menor para a maior

        foreach ($faixas as [$minimo, $premio]) {
            if ($receita < $minimo) {
                return ['falta' => round($minimo - $receita, 2), 'premio' => $premio, 'alvo' => $minimo];
            }
        }

        return null;
    }

    public static function mesAtual(): string
    {
        return now()->format('Y-m');
    }

    /** "2026-10" => Carbon do primeiro dia. Inválido cai no mês atual. */
    public static function mesValido(?string $mes): string
    {
        return preg_match('/^\d{4}-\d{2}$/', (string) $mes) ? $mes : self::mesAtual();
    }

    /**
     * Mês da receita que banca o prêmio de uma competência.
     *
     * O pagamento sai no mês seguinte ao mês trabalhado: a competência de
     * Outubro paga os ganhos de Setembro. Então o prêmio sempre olha a receita
     * do mês ANTERIOR à competência.
     */
    public static function mesDaReceita(?string $mes): string
    {
        return self::primeiroDia($mes)->subMonthNoOverflow()->format('Y-m');
    }

    /** Rótulo pronto para a tela: "Outubro de 2026 · ganhos de Setembro". */
    public static function rotuloCompetencia(?string $mes): string
    {
        $receita = self::primeiroDia(self::mesDaReceita($mes));

        return DriverPixProfileMonth::labelFor(self::primeiroDia($mes))
            . ' · ganhos de ' . DriverPixProfileMonth::MONTH_NAMES[$receita->month];
    }

    /**
     * "2026-09" => 01/09/2026.
     *
     * Carbon::createFromFormat('Y-m', ...) completa o dia com o dia de HOJE:
     * em 31/10 isso virava 31/09, que não existe, e transbordava para outubro.
     * normalizeMonth monta a data pelo ano/mês, sem dia nenhum envolvido.
     */
    private static function primeiroDia(?string $mes): Carbon
    {
        return DriverPixProfileMonth::normalizeMonth(self::mesValido($mes));
    }

    /**
     * Receita do mês agrupada pelo MikroTik (serial) do ônibus.
     * Retorna [serial => ['receita' => float, 'pagamentos' => int]].
     */
    public static function receitaPorSerial(string $mes): array
    {
        $inicio = self::primeiroDia($mes);

        $linhas = DB::table('payments')
            ->join('users', 'users.id', '=', 'payments.user_id')
            ->where('payments.status', 'completed')
            // Intervalo em vez de DATE_FORMAT: aproveita índice e não é só MySQL.
            ->where('payments.paid_at', '>=', $inicio)
            ->where('payments.paid_at', '<', $inicio->copy()->addMonthNoOverflow())
            ->whereNotNull('users.last_mikrotik_id')
            ->groupBy('users.last_mikrotik_id')
            ->selectRaw('users.last_mikrotik_id as serial, COUNT(*) as pagamentos, SUM(payments.amount) as receita')
            ->get();

        $saida = [];
        foreach ($linhas as $l) {
            $saida[$l->serial] = [
                'receita' => (float) $l->receita,
                'pagamentos' => (int) $l->pagamentos,
            ];
        }

        return $saida;
    }

    /**
     * Número do carro a partir do nome cadastrado do ônibus.
     * "5043 -  HGD09YS6037" => "5043"; "5041- HH50A914NK5" => "5041".
     */
    public static function numeroDoCarro(?string $nome): ?string
    {
        if (!$nome) {
            return null;
        }

        return preg_match('/^\s*([A-Za-z0-9]+)\s*-/', $nome, $m)
            ? trim($m[1])
            : trim(explode(' ', trim($nome))[0]);
    }

    /** Motoristas por número de carro: [bus_number => qtd]. */
    public static function motoristasPorCarro(string $mes): array
    {
        // reference_month é DATE: comparar com "2026-10" nunca casa. Tem de ser
        // o primeiro dia do mês, senão o resultado vem vazio e o prêmio acaba
        // dividido por todos os motoristas já cadastrados no carro.
        $inicio = self::primeiroDia($mes);

        // Só os aprovados: são exatamente os que podem receber, então o divisor
        // bate com o número de pagamentos que vão sair.
        return DB::table('driver_pix_profile_months')
            ->where('driver_pix_profile_months.reference_month', $inicio->toDateString())
            ->where('driver_pix_profile_months.status', 'approved')
            ->whereNotNull('driver_pix_profile_months.bus_number')
            ->groupBy('driver_pix_profile_months.bus_number')
            ->selectRaw('driver_pix_profile_months.bus_number as carro, COUNT(DISTINCT driver_pix_profile_months.driver_pix_profile_id) as qtd')
            ->pluck('qtd', 'carro')
            ->toArray();
    }

    /**
     * Quadro completo do mês: um item por ônibus cadastrado, com receita,
     * prêmio do carro, motoristas e quanto cabe a cada um.
     */
    public static function quadroDoMes(?string $mes = null): Collection
    {
        $mes = self::mesValido($mes);
        // Receita do mês trabalhado (anterior); motoristas da competência.
        $receitas = self::receitaPorSerial(self::mesDaReceita($mes));
        $motoristas = self::motoristasPorCarro($mes);

        return Bus::orderBy('name')->get()->map(function (Bus $bus) use ($receitas, $motoristas) {
            $carro = self::numeroDoCarro($bus->name);
            $dados = $receitas[$bus->mikrotik_serial] ?? ['receita' => 0.0, 'pagamentos' => 0];

            $receita = (float) $dados['receita'];
            $premio = self::premioPara($receita);
            $qtdMotoristas = (int) ($motoristas[$carro] ?? 0);

            return [
                'bus_id' => $bus->id,
                'carro' => $carro,
                'nome' => $bus->name,
                'serial' => $bus->mikrotik_serial,
                'ativo' => (bool) $bus->is_active,
                'receita' => $receita,
                'pagamentos' => (int) $dados['pagamentos'],
                'premio' => $premio,
                'motoristas' => $qtdMotoristas,
                'premio_por_motorista' => $qtdMotoristas > 0 ? round($premio / $qtdMotoristas, 2) : 0.0,
                'proxima_faixa' => self::faltaParaProximaFaixa($receita),
            ];
        })->sortByDesc('receita')->values();
    }

    /**
     * Situação de UM carro no mês — usado na tela do motorista.
     * Aceita o número do carro ("5031"), não o serial.
     */
    public static function situacaoDoCarro(?string $numeroCarro, ?string $mes = null): ?array
    {
        $numeroCarro = trim((string) $numeroCarro);
        if ($numeroCarro === '') {
            return null;
        }

        return self::quadroDoMes($mes)
            ->firstWhere('carro', $numeroCarro);
    }

    public static function totais(Collection $quadro): array
    {
        return [
            'receita' => round($quadro->sum('receita'), 2),
            'premios' => round($quadro->sum('premio'), 2),
            'carros_premiados' => $quadro->where('premio', '>', 0)->count(),
            'pagamentos' => $quadro->sum('pagamentos'),
        ];
    }
}
