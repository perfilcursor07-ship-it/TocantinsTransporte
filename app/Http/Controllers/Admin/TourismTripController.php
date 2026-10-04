<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Bus;
use App\Models\TourismTrip;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Módulo Turismo: a equipe cadastra a viagem (ônibus, período, forma de
 * pagamento e plano) e o administrador decide depois como configurar o carro.
 * Por enquanto é só registro — nada é enviado ao MikroTik.
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
            'trip' => new TourismTrip(['payment_mode' => 'individual', 'status' => 'pending']),
            'buses' => $this->buses(),
            'pricing' => TourismTrip::pricing(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;
        $data['status'] = 'pending';

        TourismTrip::create($data);

        return redirect()->route('admin.tourism.index')
            ->with('success', 'Viagem cadastrada! Agora o administrador vai configurar o ônibus.');
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

    private function validated(Request $request, ?TourismTrip $trip = null): array
    {
        $data = $request->validate([
            'contract_number' => ['nullable', 'string', 'max:30'],
            'title' => ['required', 'string', 'max:120'],
            'bus_id' => ['required', 'integer', 'exists:buses,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'payment_mode' => ['required', Rule::in(array_keys(TourismTrip::PAYMENT_MODES))],
            // Só quando uma pessoa paga tudo: período da internet e como será o plano.
            // (exclude_unless: campos escondidos na tela não contam quando não se aplicam)
            'coverage' => ['exclude_unless:payment_mode,group', 'required', Rule::in(array_keys(TourismTrip::COVERAGES))],
            'outbound_arrives_at' => ['exclude_unless:payment_mode,group', 'exclude_unless:coverage,round_trip', 'required', 'date'],
            'return_departs_at' => ['exclude_unless:payment_mode,group', 'exclude_unless:coverage,round_trip', 'required', 'date'],
            'plan_details' => ['exclude_unless:payment_mode,group', 'required', 'string', 'max:2000'],
            'responsible_name' => ['exclude_unless:payment_mode,group', 'required', 'string', 'max:120'],
            'responsible_phone' => ['exclude_unless:payment_mode,group', 'required', 'string', 'max:20'],
            'agreed_amount' => ['exclude_unless:payment_mode,group', 'nullable', 'numeric', 'min:0', 'max:999999'],
            'passengers_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'ends_at.after' => 'A chegada da volta precisa ser depois da saída da ida.',
            'coverage.required' => 'Escolha se a pessoa paga a internet de todos os dias ou só da ida e da volta.',
            'outbound_arrives_at.required' => 'Informe a chegada da ida.',
            'return_departs_at.required' => 'Informe a saída da volta.',
            'plan_details.required' => 'Descreva como será o plano de internet.',
            'responsible_name.required' => 'Informe quem vai pagar por todos.',
            'responsible_phone.required' => 'Informe o telefone de quem vai pagar por todos.',
        ], [
            'contract_number' => 'número do contrato', 'title' => 'contratante / grupo', 'bus_id' => 'ônibus',
            'starts_at' => 'saída da ida', 'ends_at' => 'chegada da volta', 'payment_mode' => 'forma de pagamento',
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

        // Cada passageiro paga o seu: ele escolhe o plano no portal; não há
        // responsável, valor combinado nem período/plano definido aqui.
        if ($data['payment_mode'] === 'individual') {
            foreach (['responsible_name', 'responsible_phone', 'agreed_amount', 'coverage',
                'outbound_arrives_at', 'return_departs_at', 'plan_details'] as $field) {
                $data[$field] = null;
            }
        }
        $data['plan'] = null; // substituído por "coverage" + "plan_details"

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
