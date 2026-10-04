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

        return view('admin.tourism.index', compact('trips', 'status', 'counts'));
    }

    public function create()
    {
        return view('admin.tourism.form', [
            'trip' => new TourismTrip(['payment_mode' => 'individual', 'plan' => 'varios_dias', 'status' => 'pending']),
            'buses' => $this->buses(),
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

        return view('admin.tourism.form', ['trip' => $trip, 'buses' => $this->buses($trip->bus_id)]);
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
            'title' => ['required', 'string', 'max:120'],
            'bus_id' => ['required', 'integer', 'exists:buses,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'payment_mode' => ['required', Rule::in(array_keys(TourismTrip::PAYMENT_MODES))],
            'plan' => ['required', Rule::in(array_keys(TourismTrip::PLANS))],
            'responsible_name' => ['nullable', 'required_if:payment_mode,group', 'string', 'max:120'],
            'responsible_phone' => ['nullable', 'required_if:payment_mode,group', 'string', 'max:20'],
            'agreed_amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'passengers_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], [
            'ends_at.after' => 'O fim da viagem precisa ser depois do início.',
            'responsible_name.required_if' => 'Informe quem vai pagar por todos.',
            'responsible_phone.required_if' => 'Informe o telefone de quem vai pagar por todos.',
        ], [
            'title' => 'nome da viagem', 'bus_id' => 'ônibus', 'starts_at' => 'início', 'ends_at' => 'fim',
            'payment_mode' => 'forma de pagamento', 'plan' => 'plano',
        ]);

        $data['starts_at'] = Carbon::parse($data['starts_at']);
        $data['ends_at'] = Carbon::parse($data['ends_at']);

        $conflict = TourismTrip::overlapping((int) $data['bus_id'], $data['starts_at'], $data['ends_at'], $trip?->id)
            ->first();
        if ($conflict) {
            throw ValidationException::withMessages(['bus_id' => sprintf(
                'Este ônibus já tem a viagem "%s" de %s a %s.',
                $conflict->title, $conflict->starts_at->format('d/m H:i'), $conflict->ends_at->format('d/m H:i')
            )]);
        }

        // Pagamento individual não tem responsável nem valor combinado.
        if ($data['payment_mode'] === 'individual') {
            $data['responsible_name'] = null;
            $data['responsible_phone'] = null;
            $data['agreed_amount'] = null;
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
