<?php

namespace App\Http\Controllers;

use App\Models\DriverPixProfile;
use App\Models\DriverPixProfileMonth;
use App\Models\DriverPixPayment;
use App\Models\DriverPixRegistrationLink;
use App\Services\DriverPrizeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DriverPixRegistrationController extends Controller
{
    public function show(string $token)
    {
        if (! Schema::hasTable('driver_pix_registration_links')) {
            return view('driver-pix.invalid', [
                'reason' => 'Cadastro temporariamente indisponível. O administrador precisa atualizar o sistema. Tente novamente em alguns minutos.',
            ]);
        }

        $link = DriverPixRegistrationLink::where('token', $token)->firstOrFail();

        if (! $link->isUsable()) {
            return view('driver-pix.invalid', ['reason' => 'Este link de cadastro expirou ou foi desativado. Peça um novo link ao administrador.']);
        }

        // A competência vem do link: permite cobrar/confirmar um mês passado.
        $competencia = $link->competencia();

        return view('driver-pix.register', [
            'link' => $link,
            'currentMonth' => $competencia->format('Y-m'),
            'currentMonthLabel' => DriverPixProfileMonth::labelFor($competencia),
            'ehMesPassado' => $link->ehMesPassado(),
        ]);
    }

    /**
     * Busca um cadastro existente pelo CPF ou telefone para pré-preencher o formulário.
     * Nunca devolve a chave PIX completa — apenas mascarada.
     */
    public function lookup(Request $request, string $token): JsonResponse
    {
        if (! Schema::hasTable('driver_pix_profiles')) {
            return response()->json(['found' => false, 'message' => 'Consulta indisponível no momento.'], 503);
        }

        $link = DriverPixRegistrationLink::where('token', $token)->first();

        if (! $link || ! $link->isUsable()) {
            return response()->json(['found' => false, 'message' => 'Link de cadastro inválido ou expirado.'], 422);
        }

        $validated = $request->validate([
            'identifier' => 'required|string|min:5|max:120',
        ], [
            'identifier.required' => 'Informe seu CPF ou telefone.',
        ]);

        $profile = DriverPixProfile::findByIdentifier($validated['identifier']);

        if (! $profile) {
            return response()->json([
                'found' => false,
                'message' => 'Nenhum cadastro encontrado com esse dado. Preencha o formulário abaixo para se cadastrar.',
            ]);
        }

        // Prefere o ônibus do último envio; o motorista ainda pode alterar no formulário
        $latestBus = Schema::hasTable('driver_pix_profile_months')
            ? ($profile->monthEntries()->orderByDesc('reference_month')->orderByDesc('id')->value('bus_number') ?: $profile->bus_number)
            : $profile->bus_number;

        return response()->json([
            'found' => true,
            'profile' => [
                'full_name' => $profile->full_name,
                'cpf' => $profile->cpf ? $profile->formattedCpf() : null,
                'phone' => $profile->formattedPhone() !== '—' ? $profile->formattedPhone() : null,
                'bus_number' => $latestBus,
                'has_pix_key' => filled($profile->pix_key),
                // Chave completa: o motorista precisa conferir se está certa antes
                // de confirmar o pagamento. O acesso já exige o token do link e
                // conhecer o CPF/telefone do próprio cadastro.
                'pix_key' => $profile->pix_key ?: null,
                'pix_key_masked' => $profile->pix_key ? $profile->maskedPixKey() : null,
                'pix_key_type' => $profile->pix_key
                    ? $this->pixKeyTypeLabel($profile->effectivePixKeyType())
                    : null,
                'status' => $profile->status,
                'status_label' => match ($profile->status) {
                    'approved' => 'Cadastro aprovado',
                    'rejected' => 'Cadastro rejeitado — atualize seus dados',
                    default => 'Cadastro em análise',
                },
            ],
            // Quanto o carro dele fez no mês e qual prêmio isso dá.
            'carro' => $this->situacaoDoCarro($latestBus, $link->competencia()->format('Y-m')),
            'pagamento' => $this->pagamentoExistente($profile, $link->competencia()),
        ]);
    }

    public function store(Request $request, string $token)
    {
        if (! Schema::hasTable('driver_pix_registration_links') || ! Schema::hasTable('driver_pix_profiles')) {
            return back()->withErrors(['link' => 'Cadastro temporariamente indisponível. Tente novamente em alguns minutos.']);
        }

        if (! Schema::hasColumn('driver_pix_profiles', 'phone') || ! Schema::hasTable('driver_pix_profile_months')) {
            return back()->withErrors(['link' => 'Sistema desatualizado. Avise o administrador para rodar as migrations no servidor.']);
        }

        $link = DriverPixRegistrationLink::where('token', $token)->firstOrFail();

        if (! $link->isUsable()) {
            return back()->withErrors(['link' => 'Este link de cadastro não está mais disponível.']);
        }

        // Competência = mês do LINK. Um link gerado para setembro confirma
        // setembro, mesmo sendo aberto em outubro. Link sem mês = mês corrente.
        $monthStart = $link->competencia();

        $validated = $request->validate([
            'full_name' => 'required|string|min:3|max:120',
            'cpf' => ['required', 'string', 'min:11', 'max:14', function ($attribute, $value, $fail) {
                if (! DriverPixProfile::isValidCpf($value)) {
                    $fail('Informe um CPF válido.');
                }
            }],
            'phone' => 'required|string|min:10|max:20',
            'bus_number' => 'required|string|max:20',
        ], [
            'phone.required' => 'Informe seu telefone com DDD.',
            'bus_number.required' => 'Informe o número do ônibus (carro) deste mês.',
        ]);
        $cpfDigits = preg_replace('/\D/', '', $validated['cpf']);
        $phoneDigits = preg_replace('/\D/', '', $validated['phone']);
        $busNumber = mb_strtoupper(trim($validated['bus_number']), 'UTF-8');
        $fullName = mb_strtoupper(trim($validated['full_name']), 'UTF-8');

        $carro = $this->situacaoDoCarro($busNumber, $monthStart->format('Y-m'));
        if (! $carro) {
            throw ValidationException::withMessages([
                'bus_number' => 'O carro informado não foi encontrado. Confira o número antes de continuar.',
            ]);
        }

        if ((float) $carro['premio'] <= 0) {
            $metaMinima = min(array_column(DriverPrizeService::FAIXAS, 0));
            throw ValidationException::withMessages([
                'bus_number' => 'Este carro ainda não atingiu a meta mínima de R$ '
                    . number_format($metaMinima, 2, ',', '.')
                    . ' em vendas no mês. Você poderá confirmar os dados quando a meta for alcançada.',
            ]);
        }

        $profile = $this->resolveProfile($cpfDigits, $phoneDigits);
        $pagamento = $this->pagamentoExistente($profile, $monthStart);
        if ($pagamento) {
            throw ValidationException::withMessages([
                'bus_number' => $pagamento['message'],
            ]);
        }

        $keepPixKey = $request->boolean('keep_pix_key') && $profile && $profile->pix_key;

        if ($keepPixKey) {
            $pixKey = $profile->pix_key;
            $pixKeyType = $profile->pix_key_type ?: DriverPixProfile::detectPixKeyType($pixKey, $phoneDigits);
        } else {
            $pixData = $request->validate([
                'pix_key' => 'required|string|min:5|max:120',
                'pix_key_confirmation' => 'required|same:pix_key',
            ], [
                'pix_key.required' => 'Informe a chave PIX que vai receber o pagamento.',
                'pix_key_confirmation.required' => 'Confirme a chave PIX.',
                'pix_key_confirmation.same' => 'A confirmação da chave PIX deve ser igual à chave informada.',
            ]);

            $pixKeyType = DriverPixProfile::detectPixKeyType($pixData['pix_key'], $phoneDigits);
            $pixKey = DriverPixProfile::normalizePixKey($pixData['pix_key'], $pixKeyType);
        }

        // A mesma chave PIX não pode pertencer a dois motoristas diferentes
        $conflict = DriverPixProfile::where('pix_key', $pixKey)
            ->when($profile, fn ($q) => $q->where('id', '!=', $profile->id))
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($conflict) {
            throw ValidationException::withMessages([
                'pix_key' => 'Esta chave PIX já está cadastrada para outro motorista. Confira os dados ou fale com o administrador.',
            ]);
        }

        $isUpdate = (bool) $profile;
        $changedFields = [];
        $pixChanged = false;

        DB::transaction(function () use (
            &$profile, &$changedFields, &$pixChanged, $link, $fullName, $cpfDigits,
            $phoneDigits, $busNumber, $pixKey, $pixKeyType, $monthStart
        ) {
            if (! $profile) {
                $profile = DriverPixProfile::create([
                    'registration_link_id' => $link->id,
                    'full_name' => $fullName,
                    'cpf' => $cpfDigits,
                    'phone' => $phoneDigits,
                    'pix_key' => $pixKey,
                    'pix_key_type' => $pixKeyType,
                    'bus_number' => $busNumber,
                    'last_confirmed_month' => $monthStart,
                    'status' => 'pending',
                ]);
            } else {
                $changedFields = array_filter([
                    'full_name' => $profile->full_name !== $fullName ? $profile->full_name : null,
                    'phone' => preg_replace('/\D/', '', (string) $profile->phone) !== $phoneDigits ? $profile->phone : null,
                    'bus_number' => $profile->bus_number !== $busNumber ? $profile->bus_number : null,
                    'pix_key' => $profile->pix_key !== $pixKey ? $profile->maskedPixKey() : null,
                ], fn ($value) => $value !== null);

                $pixChanged = $profile->pix_key !== $pixKey;

                $updates = [
                    'full_name' => $fullName,
                    'cpf' => $cpfDigits,
                    'phone' => $phoneDigits,
                    'bus_number' => $busNumber,
                ];

                if (! $profile->last_confirmed_month || $profile->last_confirmed_month->lt($monthStart)) {
                    $updates['last_confirmed_month'] = $monthStart;
                }

                // Cadastro aprovado só troca a chave PIX depois que o admin aprovar a alteração.
                if (! $profile->isApproved()) {
                    $updates['pix_key'] = $pixKey;
                    $updates['pix_key_type'] = $pixKeyType;
                    $updates['status'] = 'pending';
                    $updates['rejected_reason'] = null;
                }

                $profile->update($updates);
            }

            $entryApproved = $profile->isApproved() && ! $pixChanged;

            $existing = DriverPixProfileMonth::where('driver_pix_profile_id', $profile->id)
                ->whereDate('reference_month', $monthStart)
                ->where('bus_number', $busNumber)
                ->first();

            $payload = [
                'pix_key' => $pixKey,
                'pix_key_type' => $pixKeyType,
                'is_update' => (bool) $existing || $profile->wasRecentlyCreated === false,
                'changed_fields' => $changedFields ?: null,
                'source' => 'driver',
                'registration_link_id' => $link->id,
                'submitted_at' => now(),
                'rejected_reason' => null,
            ];

            if ($entryApproved) {
                $payload['status'] = 'approved';
                $payload['approved_at'] = $existing?->approved_at ?? now();
            } else {
                $payload['status'] = 'pending';
                $payload['approved_at'] = null;
                $payload['approved_by'] = null;
            }

            if ($existing) {
                $existing->update($payload);
            } else {
                DriverPixProfileMonth::create($payload + [
                    'driver_pix_profile_id' => $profile->id,
                    'reference_month' => $monthStart,
                    'bus_number' => $busNumber,
                ]);
            }
        });

        $link->increment('uses_count');

        return redirect()
            ->route('driver-pix.success', $token)
            ->with('driver_pix_result', [
                'is_update' => $isUpdate,
                'month_label' => DriverPixProfileMonth::labelFor($monthStart),
                'bus_number' => $busNumber,
                'profile_status' => $profile->status,
                'pix_changed' => $pixChanged,
                'needs_review' => ! $profile->isApproved() || $pixChanged,
                'changed_fields' => array_keys($changedFields),
            ]);
    }

    /**
     * Situação do carro no mês atual — receita e prêmio.
     * Usado quando o motorista digita ou troca o número do carro.
     */
    public function busStatus(Request $request, string $token): JsonResponse
    {
        $link = DriverPixRegistrationLink::where('token', $token)->first();

        if (! $link || ! $link->isUsable()) {
            return response()->json(['found' => false], 422);
        }

        $validated = $request->validate([
            'bus_number' => 'required|string|max:20',
            'identifier' => 'nullable|string|min:5|max:120',
        ]);

        $carro = $this->situacaoDoCarro($validated['bus_number'], $link->competencia()->format('Y-m'));
        $profile = filled($validated['identifier'] ?? null)
            ? DriverPixProfile::findByIdentifier($validated['identifier'])
            : null;

        return response()->json([
            'found' => (bool) $carro,
            'carro' => $carro,
            'pagamento' => $this->pagamentoExistente($profile, $link->competencia()),
        ]);
    }

    /** Monta o resumo do carro no mês corrente para exibir ao motorista. */
    private function situacaoDoCarro(?string $numeroCarro, ?string $mes = null): ?array
    {
        $dados = \App\Services\DriverPrizeService::situacaoDoCarro($numeroCarro, $mes);

        if (! $dados) {
            return null;
        }

        return [
            'carro' => $dados['carro'],
            'receita' => $dados['receita'],
            'receita_fmt' => 'R$ ' . number_format($dados['receita'], 2, ',', '.'),
            'premio' => $dados['premio'],
            'meta_minima_fmt' => 'R$ ' . number_format(min(array_column(DriverPrizeService::FAIXAS, 0)), 2, ',', '.'),
            'premio_fmt' => 'R$ ' . number_format($dados['premio'], 2, ',', '.'),
            'motoristas' => $dados['motoristas'],
            'premio_por_motorista' => $dados['premio_por_motorista'],
            'premio_por_motorista_fmt' => 'R$ ' . number_format($dados['premio_por_motorista'], 2, ',', '.'),
            'falta_fmt' => $dados['proxima_faixa']
                ? 'R$ ' . number_format($dados['proxima_faixa']['falta'], 2, ',', '.')
                : null,
            'proximo_premio_fmt' => $dados['proxima_faixa']
                ? 'R$ ' . number_format($dados['proxima_faixa']['premio'], 2, ',', '.')
                : null,
            'mes' => \App\Models\DriverPixProfileMonth::labelFor(
                \App\Services\DriverPrizeService::mesValido($mes) . '-01'
            ),
        ];
    }

    public function success(string $token)
    {
        DriverPixRegistrationLink::where('token', $token)->firstOrFail();

        return view('driver-pix.success', [
            'result' => session('driver_pix_result'),
        ]);
    }

    /* ------------------------------------------------------------------ */

    private function resolveProfile(string $cpfDigits, string $phoneDigits): ?DriverPixProfile
    {
        $byCpf = DriverPixProfile::whereRaw("REPLACE(REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', ''), ' ', '') = ?", [$cpfDigits])
            ->orderByDesc('id')
            ->first();

        if ($byCpf) {
            return $byCpf;
        }

        // Fallback por telefone: só reaproveita se o cadastro ainda não tem CPF
        // OU se o CPF (normalizado) é o mesmo. Se o telefone pertence a outro CPF,
        // retorna null → cria cadastro novo, sem sobrescrever o motorista anterior.
        return DriverPixProfile::where('phone', $phoneDigits)
            ->where(function ($query) use ($cpfDigits) {
                $query->whereNull('cpf')
                    ->orWhere('cpf', '')
                    ->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(COALESCE(cpf, ''), '.', ''), '-', ''), ' ', '') = ?",
                        [$cpfDigits]
                    );
            })
            ->orderByDesc('id')
            ->first();
    }

    private function pagamentoExistente(?DriverPixProfile $profile, $monthStart): ?array
    {
        if (! $profile) {
            return null;
        }

        $hasPaymentsTable = Schema::hasTable('driver_pix_payments');
        $hasDirectMonth = $hasPaymentsTable
            && Schema::hasColumn('driver_pix_payments', 'reference_month');
        $hasMonthEntry = Schema::hasTable('driver_pix_profile_months')
            && Schema::hasColumn('driver_pix_profile_months', 'reference_month');
        $hasMonthRelation = $hasPaymentsTable && $hasMonthEntry
            && Schema::hasColumn('driver_pix_payments', 'driver_pix_profile_month_id');

        if ($hasPaymentsTable && ($hasDirectMonth || $hasMonthRelation)) {
            $query = DriverPixPayment::query()
                ->where('driver_pix_profile_id', $profile->id)
                ->whereIn('status', ['pending', 'paid'])
                ->where(function ($month) use ($monthStart, $hasDirectMonth, $hasMonthRelation) {
                    if ($hasDirectMonth) {
                        $month->whereDate('reference_month', $monthStart);
                    }

                    if ($hasMonthRelation) {
                        $month->orWhereHas('monthEntry', fn ($entry) => $entry->whereDate('reference_month', $monthStart));
                    }
                });

            $payment = (clone $query)->where('status', 'pending')->latest('id')->first()
                ?: (clone $query)->where('status', 'paid')->latest('id')->first();

            if ($payment) {
                return [
                    'status' => $payment->status,
                    'message' => $payment->status === 'pending'
                        ? 'Já existe um pagamento para este usuário neste mês. Caso ainda não tenha recebido, aguarde o pagamento.'
                        : 'O pagamento deste mês já consta como realizado para este usuário. Se houver divergência, fale com a administração.',
                ];
            }
        }

        $entry = $hasMonthEntry && Schema::hasColumn('driver_pix_profile_months', 'status')
            ? DriverPixProfileMonth::query()
                ->where('driver_pix_profile_id', $profile->id)
                ->whereDate('reference_month', $monthStart)
                ->whereIn('status', ['pending', 'approved'])
                ->latest('id')
                ->first()
            : null;

        if (! $entry) {
            return null;
        }

        return [
            'status' => $entry->status === 'pending' ? 'submitted' : 'approved',
            'message' => $entry->status === 'pending'
                ? 'Seus dados deste mês já foram enviados e estão em análise. Não é necessário solicitar novamente.'
                : 'Seus dados deste mês já foram confirmados. Caso ainda não tenha recebido, aguarde o pagamento.',
        ];
    }

    private function pixKeyTypeLabel(string $type): string
    {
        return match ($type) {
            'cpf' => 'CPF',
            'cnpj' => 'CNPJ',
            'email' => 'E-mail',
            'phone' => 'Telefone',
            default => 'Chave aleatória',
        };
    }
}
