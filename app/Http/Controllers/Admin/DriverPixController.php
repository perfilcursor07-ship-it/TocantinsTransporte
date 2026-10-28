<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DriverPixPayment;
use App\Models\DriverPixProfile;
use App\Models\DriverPixProfileMonth;
use App\Models\DriverPixRegistrationLink;
use App\Services\PixQRCodeService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DriverPixController extends Controller
{
    public function index(Request $request)
    {
        if (! $this->driverPixSchemaReady()) {
            return view('admin.driver-pix.setup-required');
        }

        $tab = $request->get('tab', 'drivers');

        $monthStart = DriverPixProfileMonth::normalizeMonth(
            DriverPixProfileMonth::isValidMonthInput($request->get('month')) ? $request->get('month') : null
        );
        $month = $monthStart->format('Y-m');
        $monthLabel = DriverPixProfileMonth::labelFor($monthStart);
        $monthOptions = $this->monthOptions($monthStart);

        $links = DriverPixRegistrationLink::with('creator')
            ->withCount('profiles')
            ->orderByDesc('created_at')
            ->paginate(15, ['*'], 'links_page');

        $board = $this->buildDriversBoard($request, $monthStart);

        $payments = DriverPixPayment::with(['profile', 'payer', 'monthEntry'])
            ->when($request->get('payments_month') !== 'all', fn ($q) => $q->whereDate('reference_month', $monthStart))
            ->orderByRaw("FIELD(status, 'pending', 'paid', 'cancelled')")
            ->orderByDesc('created_at')
            ->paginate(20, ['*'], 'payments_page');

        $stats = $this->stats($monthStart);

        // compatibilidade com trechos antigos da view
        $profiles = $board['cards']->pluck('profile');
        $statusFilter = $request->get('status');

        // Quanto cada CARRO fez no mês e qual prêmio ele ganha.
        $carros = \App\Services\DriverPrizeService::quadroDoMes($month);

        return view('admin.driver-pix.index', array_merge($board, compact(
            'tab', 'links', 'payments', 'stats', 'statusFilter', 'profiles',
            'month', 'monthStart', 'monthLabel', 'monthOptions', 'carros'
        )));
    }

    /* ------------------------------------------------------------------ */
    /* Montagem do painel de motoristas por competência                    */
    /* ------------------------------------------------------------------ */

    private function buildDriversBoard(Request $request, Carbon $monthStart): array
    {
        $profilesQuery = $this->driverProfilesQuery();

        if ($request->filled('status') && in_array($request->status, ['pending', 'approved', 'rejected'], true)) {
            $profilesQuery->where('status', $request->status);
        }

        if ($request->filled('bus')) {
            $profilesQuery->where('bus_number', 'like', '%' . mb_strtoupper(trim($request->bus), 'UTF-8') . '%');
        }

        if ($request->filled('q')) {
            $term = trim($request->q);
            $digits = preg_replace('/\D/', '', $term);

            $profilesQuery->where(function ($query) use ($term, $digits) {
                $query->where('full_name', 'like', '%' . $term . '%');

                if ($digits !== '') {
                    $query->orWhere('phone', 'like', '%' . $digits . '%')
                        ->orWhere('cpf', 'like', '%' . $digits . '%');
                }
            });
        }

        $allProfiles = $profilesQuery
            ->orderByRaw("FIELD(status, 'pending', 'approved', 'rejected')")
            ->orderBy('full_name')
            ->get();

        $ids = $allProfiles->pluck('id');

        $entriesByProfile = DriverPixProfileMonth::with('approver')
            ->whereIn('driver_pix_profile_id', $ids)
            ->whereDate('reference_month', $monthStart)
            ->orderBy('bus_number')
            ->get()
            ->groupBy('driver_pix_profile_id');

        $paymentsByProfile = DriverPixPayment::whereIn('driver_pix_profile_id', $ids)
            ->whereDate('reference_month', $monthStart)
            ->get()
            ->groupBy('driver_pix_profile_id');

        $cards = collect();

        foreach ($allProfiles as $profile) {
            $entries = $entriesByProfile->get($profile->id, collect());
            $payments = $paymentsByProfile->get($profile->id, collect());

            if ($entries->isEmpty()) {
                $cards->push($this->makeCard($profile, null, $payments));
                continue;
            }

            foreach ($entries as $entry) {
                $entryPayments = $payments->filter(function (DriverPixPayment $payment) use ($entry) {
                    if ($payment->driver_pix_profile_month_id) {
                        return $payment->driver_pix_profile_month_id === $entry->id;
                    }

                    return $payment->bus_number === null || $payment->bus_number === $entry->bus_number;
                })->values();

                $cards->push($this->makeCard($profile, $entry, $entryPayments));
            }
        }

        $newProfiles = $cards->where('state', 'new')->values();
        $updates = $cards->where('state', 'update')->values();
        $missing = $cards->where('state', 'missing')->values();
        $rejected = $cards->where('state', 'rejected')->values();

        $readyByBus = $cards->where('state', 'ready')
            ->sortBy(fn ($card) => $card['profile']->full_name)
            ->groupBy('bus')
            ->sortKeys();

        return [
            'cards' => $cards,
            'newProfiles' => $newProfiles,
            'updates' => $updates,
            'missing' => $missing,
            'rejectedCards' => $rejected,
            'readyByBus' => $readyByBus,
            'hasDrivers' => $cards->isNotEmpty(),
            'monthTotals' => [
                'ready' => $cards->where('state', 'ready')->count(),
                'missing' => $missing->count(),
                'updates' => $updates->count(),
                'new' => $newProfiles->count(),
                'paid' => $cards->sum('paid_total'),
                'pending' => $cards->sum(fn ($card) => $card['pending_payment']?->amount ?? 0),
            ],
        ];
    }

    private function makeCard(DriverPixProfile $profile, ?DriverPixProfileMonth $entry, Collection $payments): array
    {
        $state = match (true) {
            $profile->status === 'pending' => 'new',
            $profile->status === 'rejected' => 'rejected',
            $entry === null => 'missing',
            $entry->status === 'pending' => 'update',
            $entry->status === 'rejected' => 'rejected',
            default => 'ready',
        };

        return [
            'profile' => $profile,
            'entry' => $entry,
            'bus' => $entry?->bus_number ?: $profile->bus_number,
            'state' => $state,
            'payments' => $payments,
            'paid_total' => (float) $payments->where('status', 'paid')->sum('amount'),
            'pending_payment' => $payments->firstWhere('status', 'pending'),
            'paid_payment' => $payments->where('status', 'paid')->sortByDesc('paid_at')->first(),
            'pix_changed' => $entry && $entry->pix_key !== $profile->pix_key,
        ];
    }

    private function driverProfilesQuery()
    {
        return DriverPixProfile::with(['approver', 'registrationLink'])
            ->withSum(['payments as total_paid' => fn ($q) => $q->where('status', 'paid')], 'amount')
            ->withCount(['payments as pending_payments_count' => fn ($q) => $q->where('status', 'pending')]);
    }

    private function driverPixSchemaReady(): bool
    {
        return Schema::hasTable('driver_pix_profiles')
            && Schema::hasTable('driver_pix_registration_links')
            && Schema::hasTable('driver_pix_payments')
            && Schema::hasTable('driver_pix_profile_months')
            && Schema::hasColumn('driver_pix_profiles', 'cpf')
            && Schema::hasColumn('driver_pix_profiles', 'phone')
            && Schema::hasColumn('driver_pix_payments', 'reference_month');
    }

    private function stats(Carbon $monthStart): array
    {
        $approvedProfiles = DriverPixProfile::where('status', 'approved')->count();

        $confirmedInMonth = DriverPixProfileMonth::whereDate('reference_month', $monthStart)
            ->where('status', 'approved')
            ->distinct('driver_pix_profile_id')
            ->count('driver_pix_profile_id');

        return [
            'pending_profiles' => DriverPixProfile::where('status', 'pending')->count(),
            'approved_profiles' => $approvedProfiles,
            'pending_payments' => DriverPixPayment::where('status', 'pending')->count(),
            'total_paid' => DriverPixPayment::where('status', 'paid')->sum('amount'),
            'month_confirmed' => $confirmedInMonth,
            'month_missing' => max(0, $approvedProfiles - $confirmedInMonth),
            'month_updates' => DriverPixProfileMonth::whereDate('reference_month', $monthStart)
                ->where('status', 'pending')
                ->count(),
            'month_paid' => DriverPixPayment::whereDate('reference_month', $monthStart)
                ->where('status', 'paid')
                ->sum('amount'),
            'month_pending_payments' => DriverPixPayment::whereDate('reference_month', $monthStart)
                ->where('status', 'pending')
                ->count(),
        ];
    }

    /** @return array<string, string> */
    private function monthOptions(Carbon $selected): array
    {
        $options = DriverPixProfileMonth::monthOptions(11);

        foreach (DriverPixProfileMonth::query()->select('reference_month')->distinct()->pluck('reference_month') as $value) {
            $date = $value instanceof Carbon ? $value : Carbon::parse($value);
            $options[$date->format('Y-m')] = DriverPixProfileMonth::labelFor($date);
        }

        foreach (DriverPixPayment::query()->whereNotNull('reference_month')->select('reference_month')->distinct()->pluck('reference_month') as $value) {
            $date = $value instanceof Carbon ? $value : Carbon::parse($value);
            $options[$date->format('Y-m')] = DriverPixProfileMonth::labelFor($date);
        }

        $options[$selected->format('Y-m')] = DriverPixProfileMonth::labelFor($selected);

        krsort($options);

        return $options;
    }

    private function backToBoard(Request $request, string $tab = 'drivers')
    {
        return redirect()->route('admin.driver-pix.index', array_filter([
            'tab' => $tab,
            'month' => $request->get('month'),
            'bus' => $request->get('bus'),
            'status' => $request->get('status'),
            'q' => $request->get('q'),
        ]));
    }

    /* ------------------------------------------------------------------ */
    /* PIX / QR Code                                                       */
    /* ------------------------------------------------------------------ */

    public function pixQr(Request $request, DriverPixProfile $profile, PixQRCodeService $pixQRCodeService)
    {
        if (! $profile->isApproved()) {
            return response()->json(['error' => 'Motorista não aprovado.'], 422);
        }

        $amount = $request->query('amount');
        $parsedAmount = is_numeric($amount) && (float) $amount > 0 ? (float) $amount : null;

        $bus = $request->query('bus') ?: $profile->bus_number;

        $emv = $pixQRCodeService->generateStaticPixEmv(
            $profile->pix_key,
            $profile->full_name,
            $parsedAmount,
            reference: 'BUS' . preg_replace('/[^A-Za-z0-9]/', '', (string) $bus),
            pixKeyType: $profile->effectivePixKeyType()
        );

        return response()->json([
            'emv' => $emv,
            'qr_url' => $pixQRCodeService->generateQRCodeImageUrl($emv),
            'amount' => $parsedAmount,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Links                                                               */
    /* ------------------------------------------------------------------ */

    public function storeLink(Request $request)
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:80',
            'expires_at' => 'nullable|date|after:now',
            'reference_month' => 'nullable|date_format:Y-m',
        ], [
            'reference_month.date_format' => 'Informe a competência no formato AAAA-MM.',
        ]);

        // Mês que este link vai coletar. Sem escolha, vale o mês corrente.
        $competencia = ! empty($validated['reference_month'])
            ? DriverPixProfileMonth::normalizeMonth($validated['reference_month'])
            : null;

        $link = DriverPixRegistrationLink::create([
            'token' => DriverPixRegistrationLink::generateToken(),
            'label' => $validated['label'] ?? null,
            'reference_month' => $competencia,
            'is_active' => true,
            'expires_at' => $validated['expires_at'] ?? null,
            'created_by' => auth()->id(),
        ]);

        $aviso = $competencia
            ? 'Link gerado para ' . DriverPixProfileMonth::labelFor($competencia) . '.'
            : 'Link gerado para o mês corrente.';

        return back()->with('success', $aviso)->with('new_link_url', $link->publicUrl());
    }

    public function toggleLink(DriverPixRegistrationLink $link)
    {
        $link->update(['is_active' => ! $link->is_active]);

        return back()->with('success', $link->is_active ? 'Link ativado.' : 'Link desativado.');
    }

    public function destroyLink(DriverPixRegistrationLink $link)
    {
        $label = $link->label ?: 'Link sem nome';
        $link->delete();

        return redirect()
            ->route('admin.driver-pix.index', ['tab' => 'links'])
            ->with('success', "Link \"{$label}\" excluído.");
    }

    /* ------------------------------------------------------------------ */
    /* Cadastros                                                           */
    /* ------------------------------------------------------------------ */

    public function approve(Request $request, DriverPixProfile $profile)
    {
        if ($profile->status !== 'pending') {
            return back()->with('error', 'Este cadastro já foi processado.');
        }

        DB::transaction(function () use ($profile) {
            $profile->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);

            // Aprovar o cadastro aprova também as competências pendentes já enviadas
            $profile->monthEntries()->where('status', 'pending')->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);
        });

        return $this->backToBoard($request)->with('success', "Cadastro de {$profile->full_name} aprovado!");
    }

    public function reject(Request $request, DriverPixProfile $profile)
    {
        if ($profile->status !== 'pending') {
            return back()->with('error', 'Este cadastro já foi processado.');
        }

        $validated = $request->validate([
            'rejected_reason' => 'nullable|string|max:255',
        ]);

        $reason = $validated['rejected_reason'] ?? 'Cadastro rejeitado pelo administrador.';

        DB::transaction(function () use ($profile, $reason) {
            $profile->update([
                'status' => 'rejected',
                'rejected_reason' => $reason,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
            ]);

            $profile->monthEntries()->where('status', 'pending')->update([
                'status' => 'rejected',
                'rejected_reason' => $reason,
            ]);
        });

        return $this->backToBoard($request)->with('success', "Cadastro de {$profile->full_name} rejeitado.");
    }

    public function updateProfile(Request $request, DriverPixProfile $profile)
    {
        $validated = $request->validate([
            'full_name' => 'required|string|min:3|max:120',
            'cpf' => ['nullable', 'string', 'max:14', function ($attribute, $value, $fail) {
                if ($value && ! DriverPixProfile::isValidCpf($value)) {
                    $fail('CPF inválido.');
                }
            }],
            'phone' => 'required|string|min:10|max:20',
            'bus_number' => 'required|string|max:20',
            'pix_key' => 'nullable|string|min:5|max:120',
            'admin_notes' => 'nullable|string|max:500',
        ], [
            'bus_number.required' => 'Informe o ônibus atual.',
        ]);

        $phone = preg_replace('/\D/', '', $validated['phone']);
        $updates = [
            'full_name' => mb_strtoupper(trim($validated['full_name']), 'UTF-8'),
            'cpf' => $validated['cpf'] ? preg_replace('/\D/', '', $validated['cpf']) : null,
            'phone' => $phone,
            'bus_number' => mb_strtoupper(trim($validated['bus_number']), 'UTF-8'),
            'admin_notes' => $validated['admin_notes'] ?? null,
        ];

        if (! empty($validated['pix_key'])) {
            $type = DriverPixProfile::detectPixKeyType($validated['pix_key'], $phone);
            $newKey = DriverPixProfile::normalizePixKey($validated['pix_key'], $type);

            $conflict = DriverPixProfile::where('pix_key', $newKey)
                ->where('id', '!=', $profile->id)
                ->whereIn('status', ['pending', 'approved'])
                ->exists();

            if ($conflict) {
                return back()->with('error', 'Esta chave PIX já pertence a outro motorista.');
            }

            $updates['pix_key'] = $newKey;
            $updates['pix_key_type'] = $type;
        }

        $profile->update($updates);

        return $this->backToBoard($request)->with('success', "Dados de {$profile->full_name} atualizados.");
    }

    public function destroyProfile(Request $request, DriverPixProfile $profile)
    {
        $name = $profile->full_name;
        $profile->delete();

        return $this->backToBoard($request)->with('success', "Cadastro de {$name} excluído permanentemente.");
    }

    /* ------------------------------------------------------------------ */
    /* Competências (mês)                                                  */
    /* ------------------------------------------------------------------ */

    public function storeMonth(Request $request, DriverPixProfile $profile)
    {
        if (! $profile->isApproved()) {
            return back()->with('error', 'Aprove o cadastro do motorista antes de confirmar a competência.');
        }

        $validated = $request->validate([
            'reference_month' => ['required', 'string', function ($attribute, $value, $fail) {
                if (! DriverPixProfileMonth::isValidMonthInput($value)) {
                    $fail('Mês inválido.');
                }
            }],
            'bus_number' => 'required|string|max:20',
        ], [
            'bus_number.required' => 'Informe o ônibus do mês.',
        ]);

        $monthStart = DriverPixProfileMonth::normalizeMonth($validated['reference_month']);
        $busNumber = mb_strtoupper(trim($validated['bus_number']), 'UTF-8');

        $entry = DriverPixProfileMonth::where('driver_pix_profile_id', $profile->id)
            ->whereDate('reference_month', $monthStart)
            ->where('bus_number', $busNumber)
            ->first();

        $payload = [
            'pix_key' => $profile->pix_key,
            'pix_key_type' => $profile->pix_key_type,
            'status' => 'approved',
            'source' => 'admin',
            'is_update' => (bool) $entry,
            'submitted_at' => now(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'rejected_reason' => null,
        ];

        if ($entry) {
            $entry->update($payload);
        } else {
            DriverPixProfileMonth::create($payload + [
                'driver_pix_profile_id' => $profile->id,
                'reference_month' => $monthStart,
                'bus_number' => $busNumber,
            ]);
        }

        if (! $profile->last_confirmed_month || $profile->last_confirmed_month->lt($monthStart)) {
            $profile->update(['last_confirmed_month' => $monthStart]);
        }

        return $this->backToBoard($request)->with(
            'success',
            "{$profile->full_name} confirmado em " . DriverPixProfileMonth::labelFor($monthStart) . " (ônibus {$busNumber})."
        );
    }

    public function approveMonth(Request $request, DriverPixProfileMonth $monthEntry)
    {
        if ($monthEntry->status === 'approved') {
            return back()->with('error', 'Esta competência já está aprovada.');
        }

        DB::transaction(function () use ($monthEntry) {
            $profile = $monthEntry->profile;

            $monthEntry->update([
                'status' => 'approved',
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'rejected_reason' => null,
            ]);

            if (! $profile) {
                return;
            }

            $profileUpdates = [];

            if ($profile->status !== 'approved') {
                $profileUpdates['status'] = 'approved';
                $profileUpdates['approved_by'] = auth()->id();
                $profileUpdates['approved_at'] = now();
                $profileUpdates['rejected_reason'] = null;
            }

            // A alteração de chave PIX só passa a valer depois da aprovação
            if ($monthEntry->pix_key && $monthEntry->pix_key !== $profile->pix_key) {
                $profileUpdates['pix_key'] = $monthEntry->pix_key;
                $profileUpdates['pix_key_type'] = $monthEntry->pix_key_type;
            }

            $latest = $profile->monthEntries()->orderByDesc('reference_month')->first();

            if ($latest && $latest->id === $monthEntry->id) {
                $profileUpdates['bus_number'] = $monthEntry->bus_number;
            }

            if ($profileUpdates) {
                $profile->update($profileUpdates);
            }
        });

        return $this->backToBoard($request)->with(
            'success',
            "Competência {$monthEntry->monthLabel()} de {$monthEntry->profile?->full_name} aprovada."
        );
    }

    public function rejectMonth(Request $request, DriverPixProfileMonth $monthEntry)
    {
        $validated = $request->validate([
            'rejected_reason' => 'nullable|string|max:255',
        ]);

        $monthEntry->update([
            'status' => 'rejected',
            'rejected_reason' => $validated['rejected_reason'] ?? 'Dados do mês rejeitados pelo administrador.',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);

        return $this->backToBoard($request)->with('success', 'Competência rejeitada. Peça um novo envio ao motorista.');
    }

    public function destroyMonth(Request $request, DriverPixProfileMonth $monthEntry)
    {
        $label = $monthEntry->monthLabel();
        $name = $monthEntry->profile?->full_name ?? 'motorista';
        $monthEntry->delete();

        return $this->backToBoard($request)->with('success', "Competência {$label} de {$name} removida.");
    }

    /* ------------------------------------------------------------------ */
    /* Pagamentos                                                          */
    /* ------------------------------------------------------------------ */

    public function storePayment(Request $request, DriverPixProfile $profile)
    {
        if (! $profile->isApproved()) {
            return back()->with('error', 'Só é possível pagar motoristas com cadastro aprovado.');
        }

        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:99999.99',
            'description' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
            'month_entry_id' => 'nullable|integer',
            'reference_month' => 'nullable|string',
            'bus_number' => 'nullable|string|max:20',
        ]);

        $entry = null;

        if (! empty($validated['month_entry_id'])) {
            $entry = DriverPixProfileMonth::where('id', $validated['month_entry_id'])
                ->where('driver_pix_profile_id', $profile->id)
                ->first();
        }

        $monthStart = $entry
            ? $entry->reference_month->copy()->startOfMonth()
            : DriverPixProfileMonth::normalizeMonth(
                DriverPixProfileMonth::isValidMonthInput($validated['reference_month'] ?? null)
                    ? $validated['reference_month']
                    : null
            );

        $busNumber = $entry?->bus_number
            ?: ($validated['bus_number'] ? mb_strtoupper(trim($validated['bus_number']), 'UTF-8') : $profile->bus_number);

        $monthLabel = DriverPixProfileMonth::labelFor($monthStart);

        DriverPixPayment::create([
            'driver_pix_profile_id' => $profile->id,
            'driver_pix_profile_month_id' => $entry?->id,
            'reference_month' => $monthStart,
            'bus_number' => $busNumber,
            'amount' => $validated['amount'],
            'description' => $validated['description'] ?? "Pagamento {$monthLabel} — ônibus {$busNumber}",
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        $next = $entry ? $this->nextEntryToPay($entry) : null;

        $message = $next
            ? "Pagamento de {$profile->full_name} registrado! Abrindo {$next->profile?->full_name} (ônibus {$busNumber})..."
            : "Pagamento de {$profile->full_name} registrado! Clique em \"Pagar\" para escanear o QR Code.";

        return $this->backToBoard($request)
            ->with('success', $message)
            ->with('open_register_entry', $next?->id)
            ->with('open_register_profile', $next?->driver_pix_profile_id)
            ->with('open_register_amount', $validated['amount'])
            ->with('open_register_description', $validated['description'] ?? '');
    }

    private function nextEntryToPay(DriverPixProfileMonth $entry): ?DriverPixProfileMonth
    {
        return DriverPixProfileMonth::with('profile')
            ->whereDate('reference_month', $entry->reference_month)
            ->where('bus_number', $entry->bus_number)
            ->where('status', 'approved')
            ->where('id', '!=', $entry->id)
            ->whereHas('profile', fn ($q) => $q->where('status', 'approved'))
            ->whereDoesntHave('payments', fn ($q) => $q->whereIn('status', ['pending', 'paid']))
            ->get()
            ->sortBy(fn ($item) => $item->profile?->full_name)
            ->first();
    }

    public function markPaymentPaid(Request $request, DriverPixPayment $payment)
    {
        if ($payment->status !== 'pending') {
            return back()->with('error', 'Este pagamento já foi processado.');
        }

        $payment->update([
            'status' => 'paid',
            'paid_by' => auth()->id(),
            'paid_at' => now(),
        ]);

        return $this->backToBoard($request, $request->get('tab', 'drivers'))
            ->with('success', 'Pagamento marcado como realizado.');
    }

    public function cancelPayment(Request $request, DriverPixPayment $payment)
    {
        if ($payment->status !== 'pending') {
            return back()->with('error', 'Só pagamentos pendentes podem ser cancelados.');
        }

        $payment->update(['status' => 'cancelled']);

        return back()->with('success', 'Pagamento cancelado.');
    }

    public function destroyPayment(Request $request, DriverPixPayment $payment)
    {
        $amount = number_format($payment->amount, 2, ',', '.');
        $payment->delete();

        return $this->backToBoard($request, $request->get('tab', 'payments'))
            ->with('success', "Pagamento de R$ {$amount} excluído.");
    }
}
