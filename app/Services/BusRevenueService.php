<?php

namespace App\Services;

use App\Models\Bus;
use App\Models\Payment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BusRevenueService
{
    /**
     * Como se descobre o ônibus de um pagamento, em ordem de prioridade:
     *
     * 1. transferred_mikrotik_id — transferência manual feita pelo admin, manda
     *    sempre, é uma decisão explícita.
     * 2. bus_mikrotik_id — o ônibus congelado no momento do pagamento. É o
     *    valor estável: não muda quando o passageiro viaja de novo.
     * 3. users.last_mikrotik_id — último ônibus do passageiro. Só entra nos
     *    pagamentos antigos, anteriores ao congelamento. É MUTÁVEL: enquanto um
     *    pagamento depender daqui, a receita dele migra de carro quando o
     *    passageiro embarcar em outro.
     *
     * O mesmo critério vale para o prêmio do motorista
     * (DriverPrizeService::receitaPorSerial), senão os dois números divergem.
     */
    public static function busIdExpression(string $fallbackDesconhecido = "'desconhecido'"): string
    {
        return "COALESCE("
            . 'NULLIF(' . self::transferidoJson() . ", ''), "
            . "NULLIF(payments.bus_mikrotik_id, ''), "
            . "users.last_mikrotik_id"
            . ($fallbackDesconhecido !== '' ? ", {$fallbackDesconhecido}" : '')
            . ")";
    }

    /**
     * Leitura do transferred_mikrotik_id de dentro do JSON.
     *
     * O MySQL precisa de JSON_UNQUOTE para tirar as aspas que JSON_EXTRACT
     * devolve; o json_extract do SQLite já entrega o escalar cru e não tem
     * JSON_UNQUOTE. Produção é MySQL — o ramo SQLite existe para os testes
     * poderem exercitar esta mesma expressão em vez de uma cópia aproximada.
     */
    private static function transferidoJson(): string
    {
        $caminho = "payments.payment_data, '$.transferred_mikrotik_id'";

        return DB::connection()->getDriverName() === 'sqlite'
            ? "json_extract({$caminho})"
            : "JSON_UNQUOTE(JSON_EXTRACT({$caminho}))";
    }

    /**
     * Receita líquida por ônibus (pagos − estornos) no período.
     * Todos os ônibus cadastrados aparecem, mesmo sem receita (R$ 0,00);
     * ônibus não cadastrados só aparecem se tiverem movimento.
     */
    public function byBus($startDateTime, $endDateTime): Collection
    {
        $dateRange = [$startDateTime, $endDateTime];
        $busNames = Bus::getSerialNameMap();
        $busIdExpression = self::busIdExpression();

        $totalsFor = fn (string $status) => Payment::where('payments.status', $status)
            ->whereBetween('payments.created_at', $dateRange)
            ->join('users', 'payments.user_id', '=', 'users.id')
            ->select(
                DB::raw("$busIdExpression as bus_id"),
                DB::raw('SUM(payments.amount) as total'),
                DB::raw('COUNT(payments.id) as count')
            )
            ->groupBy('bus_id')
            ->get()
            ->keyBy('bus_id');

        $completed = $totalsFor('completed');
        $refunded = $totalsFor('refunded');

        $busIds = collect($busNames)->keys()
            ->merge($completed->keys())
            ->merge($refunded->keys())
            ->unique();

        return $busIds->map(function ($busId) use ($completed, $refunded, $busNames) {
            $paid = (float) ($completed[$busId]->total ?? 0);
            $refund = (float) ($refunded[$busId]->total ?? 0);

            return (object) [
                'bus_id' => $busId,
                'bus_name' => $busNames[$busId] ?? $busId,
                'total' => $paid - $refund,
                'count' => (int) ($completed[$busId]->count ?? 0),
                'refunded_count' => (int) ($refunded[$busId]->count ?? 0),
                'refunded_total' => $refund,
            ];
        })
            ->sortBy([['total', 'desc'], ['bus_name', 'asc']])
            ->values();
    }
}
