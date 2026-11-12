<?php

namespace App\Http\Controllers\Admin;

use App\Helpers\SettingsHelper;
use App\Http\Controllers\Controller;
use App\Models\DiscountLead;
use App\Models\Payment;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;

/**
 * Admin > Sistema > Descontos.
 *
 * Mostra cada proposta enviada ao parceiro e o que aconteceu com o desconto
 * (aguardando, liberado, usado, vencido), e guarda as duas regras de validade.
 */
class DiscountControlController extends Controller
{
    private const SITUACOES = ['ativo', 'usado', 'vencido', 'sem_desconto'];

    public function index(Request $request)
    {
        $this->somenteAdmin();

        $dias = in_array((int) $request->input('dias'), [1, 7, 30, 90], true) ? (int) $request->input('dias') : 30;
        $situacao = in_array($request->input('situacao'), self::SITUACOES, true) ? $request->input('situacao') : null;
        $busca = preg_replace('/\D/', '', (string) $request->input('busca'));

        // Só propostas de verdade (com CPF, enviadas ao parceiro).
        $query = DiscountLead::query()
            ->whereNotNull('cpf')
            ->whereNotNull('handoff_id')
            ->where('created_at', '>=', now()->subDays($dias))
            ->when($busca !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('phone', 'like', "%{$busca}%")
                ->orWhere('cpf', 'like', "%{$busca}%")))
            ->orderByDesc('id');

        // A situação depende de pagamento e prazo, então é calculada aqui e a
        // lista é paginada depois do filtro. O período é limitado.
        $todas = $this->comSituacao($query->limit(2000)->get());

        $resumo = [
            'propostas' => $todas->count(),
            'liberados' => $todas->where('situacao', '!=', 'sem_desconto')->count(),
            'usados' => $todas->where('situacao', 'usado')->count(),
            'ativos' => $todas->where('situacao', 'ativo')->count(),
            'vencidos' => $todas->where('situacao', 'vencido')->count(),
            'valor_usado' => (float) $todas->where('situacao', 'usado')->sum('discount_amount'),
        ];

        $filtradas = $situacao ? $todas->where('situacao', $situacao)->values() : $todas;

        $porPagina = 30;
        $pagina = max(1, (int) $request->input('page', 1));
        $leads = new \Illuminate\Pagination\LengthAwarePaginator(
            $filtradas->forPage($pagina, $porPagina)->values(),
            $filtradas->count(),
            $porPagina,
            $pagina,
            ['path' => $request->url(), 'query' => $request->except('page')]
        );

        return view('admin.discounts.index', [
            'leads' => $leads,
            'resumo' => $resumo,
            'dias' => $dias,
            'situacao' => $situacao,
            'busca' => $request->input('busca'),
            'settings' => [
                'discount_validity_minutes' => SettingsHelper::getDiscountValidityMinutes(),
                'discount_repeat_days' => SettingsHelper::getDiscountRepeatDays(),
                'discount_amount' => SettingsHelper::getVideoDiscountAmount(),
                'min_seconds' => SettingsHelper::getCashCashMinSeconds(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->somenteAdmin();

        $validated = $request->validate([
            'discount_validity_minutes' => 'required|integer|min:5|max:1440',
            'discount_repeat_days' => 'required|integer|min:0|max:365',
        ]);

        SystemSetting::setValue('discount_validity_minutes', (int) $validated['discount_validity_minutes']);
        SystemSetting::setValue('discount_repeat_days', (int) $validated['discount_repeat_days']);
        SettingsHelper::clearCache();

        return redirect()->route('admin.discounts.index')->with('success', 'Regras do desconto atualizadas.');
    }

    /**
     * Marca em cada proposta a situação do desconto e o pagamento que o usou.
     *
     * Mesma regra de DiscountLead::discountState()/usedPayment(), mas buscando
     * passageiros e pagamentos de uma vez só, em vez de consultar linha a linha.
     */
    private function comSituacao(Collection $leads): Collection
    {
        $liberados = $leads->filter(fn (DiscountLead $lead) => $lead->discount_granted_at);

        $usuariosPorMac = collect();
        $pagamentosPorUsuario = collect();

        if ($liberados->isNotEmpty()) {
            $usuariosPorMac = User::whereIn('mac_address', $liberados->pluck('mac_address')->filter()->unique())
                ->get(['id', 'mac_address'])
                ->groupBy('mac_address');

            $idsUsuarios = $liberados->pluck('user_id')
                ->merge($usuariosPorMac->flatten()->pluck('id'))
                ->filter()->unique()->values();

            $pagamentosPorUsuario = Payment::whereIn('user_id', $idsUsuarios)
                ->where('status', 'completed')
                ->where('paid_at', '>=', $liberados->min('discount_granted_at'))
                ->orderBy('paid_at')
                ->get(['id', 'user_id', 'amount', 'paid_at'])
                ->groupBy('user_id');
        }

        return $leads->map(function (DiscountLead $lead) use ($usuariosPorMac, $pagamentosPorUsuario) {
            $lead->situacao = 'sem_desconto';
            $lead->pagamento = null;

            if (! $lead->discount_granted_at) {
                return $lead;
            }

            $venceEm = $lead->discountExpiresAt();
            $limite = $venceEm->copy()->addMinutes(30);
            $ids = collect([$lead->user_id])
                ->merge(($usuariosPorMac->get($lead->mac_address) ?? collect())->pluck('id'))
                ->filter()->unique();

            $lead->pagamento = $ids
                ->flatMap(fn ($id) => $pagamentosPorUsuario->get($id) ?? [])
                ->filter(fn ($pagamento) => $pagamento->paid_at
                    && $pagamento->paid_at->gte($lead->discount_granted_at)
                    && $pagamento->paid_at->lte($limite))
                ->sortBy('paid_at')
                ->first();

            $lead->situacao = $lead->pagamento ? 'usado' : ($venceEm->isFuture() ? 'ativo' : 'vencido');

            return $lead;
        });
    }

    private function somenteAdmin(): void
    {
        abort_unless(auth()->user()?->role === 'admin', 403);
    }
}
