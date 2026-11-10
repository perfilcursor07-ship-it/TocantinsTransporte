<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\SettingsHelper;
use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\BusRevenueAdjustment;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ajuste da receita por ônibus no relatório (/admin/reports). Só administrador.
 *
 * Tudo fica preso ao período que o admin está vendo:
 *  - acrescentar: lança um BusRevenueAdjustment com data DENTRO do período.
 *    Não cria pagamento — não tem passageiro e não libera internet;
 *  - retirar: exclui pagamentos que já existem, daquele ônibus, criados dentro
 *    do período. Pagamentos de outros meses nunca entram na conta.
 */
class BusRevenueAdjustmentController extends Controller
{
    /** Dados do modal: quantos podem ser retirados e os acréscimos do período. */
    public function show(Request $request, string $serial)
    {
        if ($erro = $this->negarSeNaoForAdmin()) {
            return response()->json(['message' => $erro], 403);
        }

        [$inicio, $fim] = $this->periodo($request);
        if (!$inicio) {
            return response()->json(['message' => 'Período inválido.'], 422);
        }

        $pagos = $this->pagosDoOnibus($serial, $inicio, $fim)->count();
        $removiveis = $this->removiveis($serial, $inicio, $fim)->count();

        $acrescimos = BusRevenueAdjustment::with('author:id,name')
            ->where('bus_mikrotik_id', $serial)
            ->noPeriodo($inicio, $fim)
            ->orderByDesc('reference_at')
            ->get()
            ->map(fn (BusRevenueAdjustment $a) => [
                'id' => $a->id,
                'quantity' => $a->quantity,
                'unit_amount' => (float) $a->unit_amount,
                'total' => (float) $a->total,
                'reference_at' => $a->reference_at->format('d/m/Y H:i'),
                'note' => $a->note,
                'author' => $a->author?->name,
            ]);

        return response()->json([
            'paid' => $pagos,
            'removable' => $removiveis,
            'protected' => max(0, $pagos - $removiveis),
            'adjustments' => $acrescimos,
        ]);
    }

    public function store(Request $request, string $serial)
    {
        if ($erro = $this->negarSeNaoForAdmin()) {
            return back()->with('error', $erro);
        }

        $dados = $request->validate([
            'quantity' => 'required|integer|min:1|max:1000',
            'unit_amount' => 'required|numeric|min:0.01|max:999.99',
            'reference_at' => 'required|date',
            'note' => 'nullable|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        [$inicio, $fim] = $this->periodo($request);
        $bus = Bus::where('mikrotik_serial', $serial)->first();
        if (!$inicio || !$bus) {
            return back()->with('error', 'Ônibus ou período inválido.');
        }

        // A data do acréscimo tem de cair dentro do período que o admin está
        // vendo — é o que garante que os outros meses não mudam.
        $referencia = Carbon::parse($dados['reference_at']);
        if ($referencia->lt($inicio) || $referencia->gt($fim)) {
            return back()->with('error', 'A data do acréscimo precisa estar dentro do período do relatório ('
                . $inicio->format('d/m/Y H:i') . ' — ' . $fim->format('d/m/Y H:i') . ').');
        }

        $quantidade = (int) $dados['quantity'];
        $valor = round((float) $dados['unit_amount'], 2);

        $ajuste = BusRevenueAdjustment::create([
            'bus_mikrotik_id' => $serial,
            'quantity' => $quantidade,
            'unit_amount' => $valor,
            'total' => round($quantidade * $valor, 2),
            'reference_at' => $referencia,
            'note' => $dados['note'] ?? null,
            'created_by' => auth()->id(),
        ]);

        Log::info('📊 Relatório: acréscimo manual na receita do ônibus', [
            'adjustment_id' => $ajuste->id,
            'bus' => $serial,
            'quantity' => $quantidade,
            'unit_amount' => $valor,
            'reference_at' => $referencia->toDateTimeString(),
            'admin_id' => auth()->id(),
        ]);

        return back()->with('success', "{$bus->name}: +{$quantidade} pagamento(s) de R$ "
            . number_format($valor, 2, ',', '.') . ' (R$ ' . number_format($ajuste->total, 2, ',', '.') . ') lançados em '
            . $referencia->format('d/m/Y') . '.');
    }

    /** Desfaz um acréscimo manual. */
    public function destroy(BusRevenueAdjustment $adjustment)
    {
        if ($erro = $this->negarSeNaoForAdmin()) {
            return back()->with('error', $erro);
        }

        $adjustment->delete();

        Log::info('📊 Relatório: acréscimo manual desfeito', [
            'adjustment_id' => $adjustment->id,
            'bus' => $adjustment->bus_mikrotik_id,
            'quantity' => $adjustment->quantity,
            'total' => (float) $adjustment->total,
            'admin_id' => auth()->id(),
        ]);

        return back()->with('success', "Acréscimo de {$adjustment->quantity} pagamento(s) desfeito.");
    }

    /** Exclui N pagamentos existentes do ônibus, dentro do período. */
    public function removePayments(Request $request, string $serial)
    {
        if ($erro = $this->negarSeNaoForAdmin()) {
            return back()->with('error', $erro);
        }

        $dados = $request->validate([
            'quantity' => 'required|integer|min:1|max:1000',
            'start_date' => 'required|date',
            'end_date' => 'required|date',
        ]);

        [$inicio, $fim] = $this->periodo($request);
        if (!$inicio) {
            return back()->with('error', 'Período inválido.');
        }

        $quantidade = (int) $dados['quantity'];
        $disponiveis = $this->removiveis($serial, $inicio, $fim)->count();
        if ($disponiveis === 0) {
            return back()->with('error', 'Não há pagamentos que possam ser excluídos deste ônibus no período.');
        }
        if ($quantidade > $disponiveis) {
            return back()->with('error', "Só é possível excluir até {$disponiveis} pagamento(s) deste ônibus no período.");
        }

        try {
            [$ids, $total] = DB::transaction(function () use ($serial, $inicio, $fim, $quantidade) {
                // Os mais antigos primeiro. lockForUpdate evita que dois cliques
                // ao mesmo tempo excluam mais do que o pedido.
                $pagamentos = $this->removiveis($serial, $inicio, $fim)
                    ->orderBy('created_at')
                    ->orderBy('id')
                    ->limit($quantidade)
                    ->lockForUpdate()
                    ->get();

                foreach ($pagamentos as $pagamento) {
                    $pagamento->delete();
                }

                return [$pagamentos->pluck('id')->all(), (float) $pagamentos->sum('amount')];
            });
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Não foi possível excluir os pagamentos neste momento.');
        }

        Log::info('📊 Relatório: pagamentos excluídos da receita do ônibus', [
            'bus' => $serial,
            'payment_ids' => $ids,
            'total' => $total,
            'period' => [$inicio->toDateTimeString(), $fim->toDateTimeString()],
            'admin_id' => auth()->id(),
        ]);

        $nome = Bus::where('mikrotik_serial', $serial)->value('name') ?? $serial;

        return back()->with('success', "{$nome}: " . count($ids) . ' pagamento(s) excluído(s) (R$ '
            . number_format($total, 2, ',', '.') . ').');
    }

    // ---------- Apoio ----------

    private function negarSeNaoForAdmin(): ?string
    {
        return auth()->user()?->role === 'admin'
            ? null
            : 'Apenas administradores podem ajustar a receita por ônibus.';
    }

    /** Período do relatório, lido do mesmo jeito que o ReportsController lê. */
    private function periodo(Request $request): array
    {
        try {
            $inicio = Carbon::parse((string) $request->input('start_date'));
            $fim = Carbon::parse((string) $request->input('end_date'));
        } catch (\Throwable) {
            return [null, null];
        }

        return $inicio->lte($fim) ? [$inicio, $fim] : [null, null];
    }

    /**
     * Pagos do ônibus no período, pelo mesmo critério da receita por ônibus
     * (created_at no período, atribuição de BusRevenueService, com passageiro).
     */
    private function pagosDoOnibus(string $serial, Carbon $inicio, Carbon $fim)
    {
        return Payment::where('status', 'completed')
            ->whereBetween('created_at', [$inicio, $fim])
            ->doOnibus($serial)
            ->whereHas('user');
    }

    /**
     * Dos pagos, os que podem ser excluídos sem atingir ninguém:
     *  - fora as contas administrativas (mesma trava da exclusão da tabela);
     *  - fora o pagamento que ainda pode estar valendo: o MikroTik recupera o
     *    acesso de quem tem pagamento recente, o "Recuperar acesso" aceita
     *    pagamentos de até 7 dias, há planos de até 168h e o plano por
     *    intervalo perde os dias junto com o pagamento. Excluir esses poderia
     *    derrubar quem pagou. Por isso nada dos últimos 7 dias sai.
     */
    private function removiveis(string $serial, Carbon $inicio, Carbon $fim)
    {
        $duracao = max((float) SettingsHelper::getSessionDuration(), 168);
        $hoje = now()->toDateString();

        return $this->pagosDoOnibus($serial, $inicio, $fim)
            ->whereHas('user', fn ($u) => $u->where(
                fn ($q) => $q->whereNull('role')->orWhereNotIn('role', ['admin', 'manager'])
            ))
            ->where(fn ($q) => $q->whereNull('paid_at')->orWhere('paid_at', '<=', now()->subMinutes((int) round($duracao * 60))))
            ->where(fn ($q) => $q->whereNull('payment_data->plan_type')
                ->orWhere('payment_data->plan_type', '!=', 'interval')
                ->orWhere('payment_data->interval->end', '<', $hoje));
    }
}
