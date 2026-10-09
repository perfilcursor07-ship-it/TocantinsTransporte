<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\TourismTrip;
use App\Services\TourismPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Módulo Turismo: a equipe cadastra a viagem (ônibus, período e quem paga a
 * internet de todos), o sistema calcula o valor e gera o link de pagamento PIX.
 * O administrador decide depois como configurar o carro — nada vai ao MikroTik.
 */
class TourismTripController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');
        $status = array_key_exists((string) $status, TourismTrip::STATUSES) ? $status : null;

        $trips = TourismTrip::with(['bus', 'creator'])
            ->when($status, fn ($q) => $q->where('status', $status))
            // Próximas/em andamento primeiro, depois as que já terminaram.
            ->orderByRaw('CASE WHEN ends_at < ? THEN 1 ELSE 0 END', [now()])
            ->orderBy('starts_at')
            ->paginate(20)
            ->withQueryString();

        $counts = TourismTrip::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $pricing = TourismTrip::pricing();

        return view('admin.tourism.index', compact('trips', 'status', 'counts', 'pricing'));
    }

    public function create()
    {
        return view('admin.tourism.form', [
            'trip' => new TourismTrip(['payment_mode' => 'group', 'status' => 'pending']),
            'buses' => $this->buses(),
            'pricing' => TourismTrip::pricing(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'pending';

        $trip = TourismTrip::create($data);

        return redirect()->route('admin.tourism.edit', $trip)
            ->with('success', 'Viagem cadastrada! Agora gere o link de pagamento e envie para quem vai pagar.');
    }

    public function edit(Request $request, TourismTrip $trip)
    {
        $this->authorizeChange($request, $trip);

        return view('admin.tourism.form', [
            'trip' => $trip, 'buses' => $this->buses($trip->bus_id), 'pricing' => TourismTrip::pricing(),
        ]);
    }

    public function update(Request $request, TourismTrip $trip)
    {
        $this->authorizeChange($request, $trip);

        $data = $this->validated($request, $trip);

        // Só o administrador muda o status e escreve as anotações dele.
        if ($request->user()->role === 'admin') {
            $admin = $request->validate([
                'status' => ['required', Rule::in(array_keys(TourismTrip::STATUSES))],
                'admin_notes' => ['nullable', 'string', 'max:2000'],
            ]);
            $data += $admin;
        }

        $trip->update($data);

        return redirect()->route('admin.tourism.index')->with('success', 'Viagem atualizada.');
    }

    public function destroy(Request $request, TourismTrip $trip)
    {
        $this->authorizeChange($request, $trip);

        $trip->delete();

        return redirect()->route('admin.tourism.index')->with('success', 'Viagem excluída.');
    }

    /** Gera o link de pagamento PIX (PagBank) para enviar a quem vai pagar. */
    public function generatePaymentLink(TourismTrip $trip, TourismPaymentService $payments)
    {
        if ($trip->status === 'cancelled') {
            return back()->with('error', 'Viagem cancelada não recebe pagamento.');
        }

        try {
            $payments->createLink($trip);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Link de pagamento gerado! Copie e envie para '.$trip->responsible_name.'.');
    }

    /** Consulta o PagBank agora (caso o aviso automático ainda não tenha chegado). */
    public function checkPayment(TourismTrip $trip, TourismPaymentService $payments)
    {
        $payments->refreshStatus($trip);

        return back()->with($trip->isPaid() ? 'success' : 'error',
            $trip->isPaid() ? 'Pagamento confirmado!' : 'O pagamento ainda não foi identificado no PagBank.');
    }

    private function validated(Request $request, ?TourismTrip $trip = null): array
    {
        $data = $request->validate([
            'contract_number' => ['nullable', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:120'],
            'bus_id' => ['required', 'integer', 'exists:buses,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            // Uma pessoa paga a internet de todos: período coberto, quem paga e desconto.
            // (exclude_unless: campos escondidos na tela não contam quando não se aplicam)
            'coverage' => ['required', Rule::in(array_keys(TourismTrip::COVERAGES))],
            'outbound_arrives_at' => ['exclude_unless:coverage,round_trip', 'required', 'date'],
            'return_departs_at' => ['exclude_unless:coverage,round_trip', 'required', 'date'],
            'discount_percent' => ['exclude_unless:coverage,all_days', 'nullable', 'numeric', 'min:0', 'max:'.TourismTrip::MAX_DISCOUNT],
            'plan_details' => ['nullable', 'string', 'max:2000'],
            'responsible_name' => ['required', 'string', 'max:120'],
            'responsible_phone' => ['required', 'string', 'max:20'],
            'passengers_count' => ['required', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'ends_at.after' => 'A chegada da volta precisa ser depois da saída da ida.',
            'coverage.required' => 'Escolha se a pessoa paga a internet de todos os dias ou só da ida e da volta.',
            'outbound_arrives_at.required' => 'Informe a chegada da ida.',
            'return_departs_at.required' => 'Informe a saída da volta.',
            'discount_percent.max' => 'O desconto é de no máximo '.TourismTrip::MAX_DISCOUNT.'%.',
            'passengers_count.required' => 'Informe quantos passageiros — o valor total depende disso.',
            'responsible_name.required' => 'Informe quem vai pagar por todos.',
            'responsible_phone.required' => 'Informe o telefone de quem vai pagar por todos.',
        ], [
            'contract_number' => 'número do contrato', 'title' => 'contratante / grupo', 'bus_id' => 'ônibus',
            'starts_at' => 'saída da ida', 'ends_at' => 'chegada da volta', 'passengers_count' => 'passageiros',
            'discount_percent' => 'desconto',
        ]);

        $data['starts_at'] = Carbon::parse($data['starts_at']);
        $data['ends_at'] = Carbon::parse($data['ends_at']);

        if (($data['coverage'] ?? null) === 'round_trip') {
            $data['outbound_arrives_at'] = Carbon::parse($data['outbound_arrives_at']);
            $data['return_departs_at'] = Carbon::parse($data['return_departs_at']);
            if (! ($data['starts_at'] < $data['outbound_arrives_at']
                && $data['outbound_arrives_at'] <= $data['return_departs_at']
                && $data['return_departs_at'] < $data['ends_at'])) {
                throw ValidationException::withMessages(['return_departs_at' =>
                    'Confira os horários: saída da ida → chegada da ida → saída da volta → chegada da volta.']);
            }
        } else {
            $data['outbound_arrives_at'] = null;
            $data['return_departs_at'] = null;
        }

        $conflict = TourismTrip::overlapping((int) $data['bus_id'], $data['starts_at'], $data['ends_at'], $trip?->id)
            ->first();
        if ($conflict) {
            throw ValidationException::withMessages(['bus_id' => sprintf(
                'Este ônibus já tem a viagem "%s" de %s a %s.',
                $conflict->title, $conflict->starts_at->format('d/m H:i'), $conflict->ends_at->format('d/m H:i')
            )]);
        }

        // Valor calculado pelo sistema: diárias de 24h x passageiros, com desconto
        // de até 10% só quando a internet cobre todos os dias da viagem.
        $data['payment_mode'] = 'group';
        $data['plan'] = null; // substituído por "coverage" + "plan_details"
        $data['discount_percent'] = $data['coverage'] === 'all_days' ? (float) ($data['discount_percent'] ?? 0) : 0;
        // Já paga: mantém o preço da diária usado na cobrança.
        $pricing = $trip?->isPaid() && $trip->price_24h ? ['price_24h' => (float) $trip->price_24h] : TourismTrip::pricing();
        $quote = (new TourismTrip($data))->quote($pricing);
        $data['price_24h'] = $pricing['price_24h'];
        $data['subtotal_amount'] = $quote['subtotal'];
        $data['agreed_amount'] = $quote['total'];

        if ($trip?->isPaid() && abs((float) $trip->agreed_amount - $quote['total']) >= 0.005) {
            throw ValidationException::withMessages(['passengers_count' => sprintf(
                'O pagamento de R$ %s já foi confirmado. Passageiros, datas e desconto não podem mudar o valor.',
                number_format((float) $trip->agreed_amount, 2, ',', '.')
            )]);
        }

        return $data;
    }

    private function authorizeChange(Request $request, TourismTrip $trip): void
    {
        abort_unless($trip->canBeChangedBy($request->user()), 403,
            'Esta viagem já foi tratada pelo administrador. Fale com ele para alterar.');
    }

    /** Ônibus ativos (mais o da viagem, se tiver sido desativado depois). */
    private function buses(?int $includeId = null)
    {
        return Bus::where('is_active', true)
            ->when($includeId, fn ($q) => $q->orWhere('id', $includeId))
            ->orderBy('name')
            ->get(['id', 'name', 'plate', 'route_description']);
    }
}
