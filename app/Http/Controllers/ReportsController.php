<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\Bus;
use App\Models\Payment;
use App\Models\User;
use App\Models\Session;
use Carbon\Carbon;

class ReportsController extends Controller
{
    public function index(Request $request)
    {
        // Helper: normaliza data (aceita 'Y-m-d', 'Y-m-d\TH:i', 'Y-m-d H:i', 'Y-m-d H:i:s')
        // Retorna sempre formato 'Y-m-d H:i:s' usando defaults se não vier hora
        $parseDate = function (?string $value, string $defaultTime = '00:00:00') {
            if (!$value) return null;
            try {
                // datetime-local manda 'Y-m-d\TH:i' -> Carbon::parse aceita
                $carbon = Carbon::parse($value);
                // Se a string original NÃO continha hora (formato puro Y-m-d), aplica o default
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    [$h, $m, $s] = explode(':', $defaultTime);
                    $carbon->setTime((int)$h, (int)$m, (int)$s);
                }
                return $carbon;
            } catch (\Throwable) {
                return null;
            }
        };

        // Filtros padrão
        $startCarbon = $parseDate($request->get('start_date'), '00:00:00') ?? Carbon::now()->startOfMonth();
        $endCarbon   = $parseDate($request->get('end_date'),   '23:59:59') ?? Carbon::now()->endOfDay();

        // Strings completas para uso nas queries (com hora)
        $startDateTime = $startCarbon->format('Y-m-d H:i:s');
        $endDateTime   = $endCarbon->format('Y-m-d H:i:s');

        // Para os inputs do formulário (datetime-local exige 'Y-m-d\TH:i')
        $startDate = $startCarbon->format('Y-m-d\TH:i');
        $endDate   = $endCarbon->format('Y-m-d\TH:i');

        $paymentStatus = $request->get('payment_status', 'all');
        $userStatus = $request->get('user_status', 'all');
        $busFilter = $request->get('bus', 'all');
        $canViewUsersTab = auth()->user()?->role === 'admin';
        
        // Estatísticas gerais
        $stats = $this->getGeneralStats($startDateTime, $endDateTime, $paymentStatus, $userStatus, $busFilter);
        
        // Dados dos pagamentos
        $payments = $this->getPaymentsData($startDateTime, $endDateTime, $paymentStatus, $busFilter);
        
        // Dados dos usuários
        $users = $canViewUsersTab ? $this->getUsersData($startDateTime, $endDateTime, $userStatus) : null;
        
        // Dados para gráficos
        $charts = $this->getChartsData($startDateTime, $endDateTime);

        // Receita por ônibus
        $revenueByBus = $this->getRevenueByBus($startDateTime, $endDateTime);

        // Lista de ônibus para o filtro
        $busList = \App\Models\Bus::orderBy('name')->get();
        
        return view('admin.reports', compact(
            'stats', 
            'payments', 
            'users', 
            'charts',
            'startDate',
            'endDate',
            'paymentStatus',
            'userStatus',
            'busFilter',
            'canViewUsersTab',
            'revenueByBus',
            'busList'
        ));
    }
    
    private function getGeneralStats($startDateTime, $endDateTime, $paymentStatus, $userStatus, $busFilter = 'all')
    {
        $dateRange = [$startDateTime, $endDateTime];

        // Helper: aplica filtro de ônibus a uma query de Payment
        $applyBus = function ($query) use ($busFilter) {
            if ($busFilter !== 'all') {
                $query->doOnibus($busFilter);
            }
            return $query;
        };

        // Receita bruta (pagos) e estornos — líquido abate estornos do total
        $completedRevenue = (float) $applyBus(Payment::where('status', 'completed')->whereBetween('created_at', $dateRange))->sum('amount');
        $refundedRevenue = (float) $applyBus(Payment::where('status', 'refunded')->whereBetween('created_at', $dateRange))->sum('amount');
        // Acréscimos manuais do admin por ônibus: contam como pagos, mas não
        // são pagamentos (não aparecem na lista nem liberam internet).
        $adjusted = collect(\App\Models\BusRevenueAdjustment::totaisPorOnibus(
            $startDateTime, $endDateTime, $busFilter !== 'all' ? $busFilter : null
        ));
        $adjustedRevenue = (float) $adjusted->sum('total');
        $adjustedCount = (int) $adjusted->sum('count');

        $completedRevenue += $adjustedRevenue;
        $totalRevenue = $completedRevenue - $refundedRevenue;

        // Total de pagamentos (respeitando filtro de status)
        $totalQuery = Payment::whereBetween('created_at', $dateRange);
        if ($paymentStatus !== 'all') $totalQuery->where('status', $paymentStatus);
        $totalPayments = $applyBus($totalQuery)->count()
            + (in_array($paymentStatus, ['all', 'completed'], true) ? $adjustedCount : 0);

        // Contagem por status
        $completedPayments = $applyBus(Payment::where('status', 'completed')->whereBetween('created_at', $dateRange))->count()
            + $adjustedCount;
        $pendingPaymentsCount = $applyBus(Payment::where('status', 'pending')->whereBetween('created_at', $dateRange))->count();
        $refundedPaymentsCount = $applyBus(Payment::where('status', 'refunded')->whereBetween('created_at', $dateRange))->count();
        $pendingPayments = $applyBus(Payment::where('status', 'pending')->whereBetween('created_at', $dateRange))->sum('amount');

        // Usuários
        $userQuery = User::whereBetween('created_at', $dateRange);
        if ($busFilter !== 'all') $userQuery->where('last_mikrotik_id', $busFilter);
        $totalUsers = $userQuery->count();

        $connectedQuery = User::where('status', 'connected');
        if ($busFilter !== 'all') $connectedQuery->where('last_mikrotik_id', $busFilter);
        $connectedUsers = $connectedQuery->count();

        // Sessões ativas
        $sessionsQuery = DB::table('wifi_sessions')
            ->whereBetween('started_at', $dateRange)
            ->where('session_status', 'active');
        if ($busFilter !== 'all') {
            $sessionsQuery->whereIn('user_id', User::where('last_mikrotik_id', $busFilter)->pluck('id'));
        }
        $activeSessions = $sessionsQuery->count();

        return [
            'total_revenue' => $totalRevenue,
            'completed_revenue' => $completedRevenue,
            'refunded_revenue' => $refundedRevenue,
            'adjusted_revenue' => $adjustedRevenue,
            'adjusted_count' => $adjustedCount,
            'pending_payments' => $pendingPayments,
            'pending_payments_count' => $pendingPaymentsCount,
            'completed_payments_count' => $completedPayments,
            'refunded_payments_count' => $refundedPaymentsCount,
            'total_payments' => $totalPayments,
            'total_users' => $totalUsers,
            'connected_users' => $connectedUsers,
            'active_sessions' => $activeSessions,
            'avg_payment' => $completedPayments > 0 ? $completedRevenue / $completedPayments : 0,
        ];
    }
    
    private function getPaymentsData($startDateTime, $endDateTime, $paymentStatus, $busFilter = 'all')
    {
        $query = Payment::with(['user'])
            ->whereBetween('created_at', [$startDateTime, $endDateTime]);
        
        if ($paymentStatus !== 'all') {
            $query->where('status', $paymentStatus);
        }

        if ($busFilter !== 'all') {
            $query->doOnibus($busFilter);
        }
        
        return $query->orderBy('created_at', 'desc')
            ->paginate(20)
            ->withQueryString();
    }
    
    private function getUsersData($startDateTime, $endDateTime, $userStatus)
    {
        $query = User::whereBetween('created_at', [$startDateTime, $endDateTime]);
        
        if ($userStatus !== 'all') {
            $query->where('status', $userStatus);
        }
        
        return $query->orderBy('created_at', 'desc')
            ->paginate(50)
            ->withQueryString();
    }
    
    private function getChartsData($startDateTime, $endDateTime)
    {
        // Receita líquida por dia (pagos − estornos)
        $completedByDay = Payment::where('status', 'completed')
            ->whereBetween('created_at', [$startDateTime, $endDateTime])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $refundedByDay = Payment::where('status', 'refunded')
            ->whereBetween('created_at', [$startDateTime, $endDateTime])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('SUM(amount) as total'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        // Acréscimos manuais do admin, no dia em que foram lançados.
        try {
            $adjustedByDay = \App\Models\BusRevenueAdjustment::noPeriodo($startDateTime, $endDateTime)
                ->select(
                    DB::raw('DATE(reference_at) as date'),
                    DB::raw('SUM(total) as total'),
                    DB::raw('SUM(quantity) as count')
                )
                ->groupBy('date')
                ->get()
                ->keyBy('date');
        } catch (\Throwable $e) {
            report($e);
            $adjustedByDay = collect();
        }

        $revenueByDay = $completedByDay->keys()
            ->merge($refundedByDay->keys())
            ->merge($adjustedByDay->keys())
            ->unique()
            ->sort()
            ->map(function ($date) use ($completedByDay, $refundedByDay, $adjustedByDay) {
                $paid = (float) ($completedByDay[$date]->total ?? 0) + (float) ($adjustedByDay[$date]->total ?? 0);
                $refund = (float) ($refundedByDay[$date]->total ?? 0);
                $count = (int) ($completedByDay[$date]->count ?? 0) + (int) ($adjustedByDay[$date]->count ?? 0);

                return (object) [
                    'date' => $date,
                    'total' => $paid - $refund,
                    'count' => $count,
                ];
            })
            ->values();

        // Pagamentos por status
        $paymentsByStatus = Payment::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->get();

        // Usuários por dia
        $usersByDay = User::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->select(
                DB::raw('DATE(created_at) as date'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Conexões por hora (últimas 24h)
        $connectionsByHour = User::where('connected_at', '>=', Carbon::now()->subDay())
            ->select(
                DB::raw('HOUR(connected_at) as hour'),
                DB::raw('COUNT(*) as count')
            )
            ->groupBy('hour')
            ->orderBy('hour')
            ->get();

        return [
            'revenue_by_day' => $revenueByDay,
            'payments_by_status' => $paymentsByStatus,
            'users_by_day' => $usersByDay,
            'connections_by_hour' => $connectionsByHour,
        ];
    }

    /**
     * Receita líquida agrupada por ônibus (pagos − estornos), incluindo ônibus sem receita
     */
    private function getRevenueByBus($startDateTime, $endDateTime)
    {
        return app(\App\Services\BusRevenueService::class)->byBus($startDateTime, $endDateTime);
    }

    public function export(Request $request)
    {
        // As listas de pagamentos e usuários são só do admin; o gestor vê a receita.
        if (auth()->user()?->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem exportar as listas.');
        }

        // Mesma normalização do index
        $parseDate = function (?string $value, string $defaultTime = '00:00:00') {
            if (!$value) return null;
            try {
                $carbon = Carbon::parse($value);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                    [$h, $m, $s] = explode(':', $defaultTime);
                    $carbon->setTime((int)$h, (int)$m, (int)$s);
                }
                return $carbon;
            } catch (\Throwable) {
                return null;
            }
        };

        $type = $request->get('type', 'payments');
        $format = $request->get('format', 'csv');
        $startCarbon = $parseDate($request->get('start_date'), '00:00:00') ?? Carbon::now()->startOfMonth();
        $endCarbon   = $parseDate($request->get('end_date'),   '23:59:59') ?? Carbon::now()->endOfDay();
        $startDateTime = $startCarbon->format('Y-m-d H:i:s');
        $endDateTime   = $endCarbon->format('Y-m-d H:i:s');

        if ($type === 'users' && auth()->user()?->role !== 'admin') {
            return back()->with('error', 'A aba e a exportação de usuários estão disponíveis apenas para administradores.');
        }
        
        if ($type === 'payments') {
            return $this->exportPayments($startDateTime, $endDateTime, $format, $startCarbon, $endCarbon);
        } elseif ($type === 'users') {
            return $this->exportUsers($startDateTime, $endDateTime, $format, $startCarbon, $endCarbon);
        }
        
        return back()->with('error', 'Tipo de exportação inválido');
    }

    public function destroyPaymentRecord(Payment $payment)
    {
        if (!auth()->check() || auth()->user()->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem excluir registros de pagamento.');
        }

        try {
            DB::transaction(function () use ($payment) {
                $user = $payment->user;

                // Evita remoção acidental de contas administrativas.
                if ($user && in_array($user->role, ['admin', 'manager'])) {
                    throw new \RuntimeException('Não é permitido excluir pagamentos de usuários administrativos por esta tela.');
                }

                // Deletar avaliações vinculadas ao usuário deste pagamento
                if ($user) {
                    \App\Models\ServiceReview::where('user_id', $user->id)->delete();
                }

                // Deletar apenas o pagamento selecionado (NÃO o usuário inteiro).
                // Antes: deletava o usuário, o que por CASCADE removia TODOS os pagamentos
                // e sessões dele — inclusive de meses anteriores.
                $payment->delete();
            });

            return back()->with('success', 'Pagamento removido com sucesso.');
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Não foi possível excluir o registro neste momento.');
        }
    }

    public function updatePaymentRecord(Request $request, Payment $payment)
    {
        if (!auth()->check() || auth()->user()->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem editar pagamentos.');
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99', 'decimal:0,2'],
            'mikrotik_serial' => ['nullable', 'string', 'exists:buses,mikrotik_serial'],
        ], [
            'amount.required' => 'Informe o valor do pagamento.',
            'amount.numeric' => 'Informe um valor válido.',
            'amount.min' => 'O valor deve ser maior que zero.',
            'amount.decimal' => 'Use no máximo duas casas decimais.',
            'mikrotik_serial.exists' => 'Selecione um veículo válido.',
        ]);

        $newAmount = round((float) $validated['amount'], 2);
        $oldAmount = round((float) $payment->amount, 2);
        $targetBus = filled($validated['mikrotik_serial'] ?? null)
            ? Bus::where('mikrotik_serial', $validated['mikrotik_serial'])->firstOrFail()
            : null;
        $currentBus = data_get($payment->payment_data, 'transferred_mikrotik_id')
            ?: $payment->user?->last_mikrotik_id;

        $paymentData = $payment->payment_data ?? [];
        $changes = [];

        if ($oldAmount !== $newAmount) {
            $paymentData['amount_edited_from'] = $oldAmount;
            $paymentData['amount_edited_at'] = now()->toDateTimeString();
            $paymentData['amount_edited_by'] = auth()->id();
            $payment->amount = $newAmount;
            $changes[] = 'valor alterado de R$ ' . number_format($oldAmount, 2, ',', '.')
                . ' para R$ ' . number_format($newAmount, 2, ',', '.');
        }

        if ($targetBus && $targetBus->mikrotik_serial !== $currentBus) {
            $paymentData['transferred_mikrotik_id'] = $targetBus->mikrotik_serial;
            $paymentData['transferred_mikrotik_from'] = $currentBus;
            $paymentData['transferred_at'] = now()->toDateTimeString();
            $paymentData['transferred_by'] = auth()->id();
            $changes[] = "transferido para {$targetBus->name} ({$targetBus->mikrotik_serial})";
        }

        $payment->payment_data = $paymentData;
        $payment->save();

        if ($changes === []) {
            return back()->with('success', "Pagamento #{$payment->id} não teve alterações.");
        }

        return back()->with(
            'success',
            "Pagamento #{$payment->id}: " . implode(' e ', $changes)
                . '. Os totais do relatório foram atualizados.'
        );
    }

    /**
     * Alterna status do pagamento entre Pendente (pending) e Pago (completed).
     * Atualiza os totais do relatório (só admin).
     */
    public function togglePaymentStatus(Request $request, Payment $payment)
    {
        if (!auth()->check() || auth()->user()->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem alterar o status do pagamento.');
        }

        $validated = $request->validate([
            'status' => ['required', 'in:pending,completed'],
        ]);

        $newStatus = $validated['status'];

        if (!in_array($payment->status, ['pending', 'completed', 'failed', 'refunded'], true)) {
            return back()->with('error', 'Status atual do pagamento não permite alteração.');
        }

        if ($payment->status === $newStatus) {
            return back()->with('success', 'O pagamento já está com esse status.');
        }

        $payment->status = $newStatus;
        if ($newStatus === 'completed') {
            // Data de pagamento realista: 10–15 min após a criação do PIX
            $delayMinutes = random_int(10, 15);
            $delaySeconds = random_int(0, 59);
            $payment->paid_at = $payment->created_at
                ->copy()
                ->addMinutes($delayMinutes)
                ->addSeconds($delaySeconds);
            $payment->refunded_at = null;
            // Mantém o comprovante de estorno no histórico se existir
        } else {
            $payment->paid_at = null;
            $payment->refunded_at = null;
        }
        $payment->save();

        $label = $newStatus === 'completed' ? 'Pago' : 'Pendente';

        return back()->with(
            'success',
            "Pagamento #{$payment->id} marcado como {$label}. Os totais do relatório foram atualizados."
        );
    }

    /**
     * Marca pagamento pago como estornado (abate da receita).
     * Aceita comprovante opcional (imagem/PDF). Somente admin.
     */
    public function refundPayment(Request $request, Payment $payment)
    {
        if (! auth()->check() || auth()->user()->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem registrar estorno.');
        }

        if ($payment->status !== 'completed') {
            return back()->with('error', 'Só é possível estornar pagamentos com status Pago.');
        }

        $validated = $request->validate([
            'refund_receipt' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'refund_note' => ['nullable', 'string', 'max:255'],
        ], [
            'refund_receipt.mimes' => 'O comprovante deve ser imagem (JPG, PNG, WEBP) ou PDF.',
            'refund_receipt.max' => 'O comprovante deve ter no máximo 5 MB.',
        ]);

        if ($request->hasFile('refund_receipt')) {
            if ($payment->refund_receipt_path) {
                Storage::disk('local')->delete($payment->refund_receipt_path);
            }
            $path = $request->file('refund_receipt')->store(
                'refund-receipts/' . now()->format('Y/m'),
                'local'
            );
            $payment->refund_receipt_path = $path;
        }

        $payment->status = 'refunded';
        $payment->refunded_at = now();
        $payment->refund_note = $validated['refund_note'] ?? null;
        $payment->save();

        return back()->with(
            'success',
            "Pagamento #{$payment->id} marcado como Estorno. O valor foi abatido da receita líquida."
        );
    }

    /**
     * Download/visualização do comprovante de estorno (admin e gestor).
     */
    public function refundReceipt(Payment $payment)
    {
        if (! auth()->check() || ! in_array(auth()->user()->role, ['admin', 'manager'], true)) {
            abort(403);
        }

        if (! $payment->hasRefundReceipt()) {
            return back()->with('error', 'Este estorno não possui comprovante anexado.');
        }

        $path = $payment->refund_receipt_path;
        if (! Storage::disk('local')->exists($path)) {
            return back()->with('error', 'Arquivo de comprovante não encontrado.');
        }

        $absolute = Storage::disk('local')->path($path);
        $mime = mime_content_type($absolute) ?: 'application/octet-stream';
        $name = 'comprovante-estorno-' . $payment->id . '.' . pathinfo($path, PATHINFO_EXTENSION);

        return response()->file($absolute, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    public function destroyPaymentRecords(Request $request)
    {
        if (!auth()->check() || auth()->user()->role !== 'admin') {
            return back()->with('error', 'Apenas administradores podem excluir registros de pagamento.');
        }

        $validated = $request->validate([
            'payment_ids' => ['required', 'array', 'min:1'],
            'payment_ids.*' => ['integer', 'exists:payments,id'],
        ]);

        $paymentIds = collect($validated['payment_ids'])->unique()->values();
        $payments = Payment::with('user')->whereIn('id', $paymentIds)->get();

        if ($payments->isEmpty()) {
            return back()->with('error', 'Nenhum pagamento válido foi selecionado para exclusão.');
        }

        $deletedUsers = 0;
        $deletedPayments = 0;
        $deletedReviews = 0;
        $blockedRecords = 0;

        try {
            DB::transaction(function () use ($payments, &$deletedUsers, &$deletedPayments, &$deletedReviews, &$blockedRecords) {
                $deletedUserIds = [];

                foreach ($payments as $payment) {
                    $user = $payment->user;

                    if ($user && in_array($user->role, ['admin', 'manager'])) {
                        $blockedRecords++;
                        continue;
                    }

                    // Deletar avaliações vinculadas ao usuário (se ainda não deletou)
                    if ($user && !in_array($user->id, $deletedUserIds)) {
                        $deletedReviews += \App\Models\ServiceReview::where('user_id', $user->id)->delete();
                        $deletedUserIds[] = $user->id;
                    }

                    // Deletar apenas o pagamento (NÃO o usuário inteiro).
                    // Antes: deletava o usuário, o que por CASCADE removia TODOS os
                    // pagamentos e sessões dele — inclusive de meses anteriores.
                    $payment->delete();
                    $deletedPayments++;
                }
            });

            if ($deletedUsers === 0 && $deletedPayments === 0 && $blockedRecords > 0) {
                return back()->with('error', 'Nenhum registro foi removido. Existem itens vinculados a usuários administrativos.');
            }

            $message = "Exclusão concluída. Usuários removidos: {$deletedUsers}. Pagamentos removidos: {$deletedPayments}. Avaliações removidas: {$deletedReviews}.";
            if ($blockedRecords > 0) {
                $message .= " Itens bloqueados por segurança: {$blockedRecords}.";
            }

            return back()->with('success', $message);
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Não foi possível excluir os registros selecionados neste momento.');
        }
    }
    
    private function exportPayments($startDateTime, $endDateTime, $format, $startCarbon = null, $endCarbon = null)
    {
        $payments = Payment::with(['user'])
            ->whereBetween('created_at', [$startDateTime, $endDateTime])
            ->orderBy('created_at', 'desc')
            ->get();

        $startSlug = ($startCarbon ?? Carbon::parse($startDateTime))->format('Y-m-d_Hi');
        $endSlug   = ($endCarbon   ?? Carbon::parse($endDateTime))->format('Y-m-d_Hi');
        $filename = 'pagamentos_' . $startSlug . '_a_' . $endSlug . '.' . $format;
        
        if ($format === 'csv') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];
            
            $callback = function() use ($payments) {
                $file = fopen('php://output', 'w');
                // BOM UTF-8 para Excel reconhecer acentos
                fwrite($file, "\xEF\xBB\xBF");
                // Excel-PT usa ; como separador. Forçamos isso e tambem dica sep=;
                fwrite($file, "sep=;\n");
                fputcsv($file, ['ID', 'Usuario', 'Email', 'Valor', 'Tipo', 'Status', 'Data Pagamento', 'Data Criacao'], ';');
                
                foreach ($payments as $payment) {
                    fputcsv($file, [
                        $payment->id,
                        $payment->user->name ?? 'N/A',
                        $payment->user->email ?? 'N/A',
                        'R$ ' . number_format($payment->amount, 2, ',', '.'),
                        ucfirst($payment->payment_type),
                        ucfirst($payment->status),
                        $payment->paid_at ? $payment->paid_at->format('d/m/Y H:i:s') : 'N/A',
                        $payment->created_at->format('d/m/Y H:i:s'),
                    ], ';');
                }
                fclose($file);
            };
            
            return response()->stream($callback, 200, $headers);
        }
        
        return back()->with('error', 'Formato de exportação não suportado');
    }
    
    private function exportUsers($startDateTime, $endDateTime, $format, $startCarbon = null, $endCarbon = null)
    {
        $users = User::whereBetween('created_at', [$startDateTime, $endDateTime])
            ->orderBy('created_at', 'desc')
            ->get();

        $startSlug = ($startCarbon ?? Carbon::parse($startDateTime))->format('Y-m-d_Hi');
        $endSlug   = ($endCarbon   ?? Carbon::parse($endDateTime))->format('Y-m-d_Hi');
        $filename = 'usuarios_' . $startSlug . '_a_' . $endSlug . '.' . $format;
        
        if ($format === 'csv') {
            $headers = [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ];
            
            $callback = function() use ($users) {
                $file = fopen('php://output', 'w');
                // BOM UTF-8 para Excel reconhecer acentos
                fwrite($file, "\xEF\xBB\xBF");
                // Excel-PT usa ; como separador
                fwrite($file, "sep=;\n");
                fputcsv($file, ['ID', 'Nome', 'Email', 'Telefone', 'MAC Address', 'IP Address', 'Status', 'Conectado em', 'Expira em', 'Data Cadastro'], ';');
                
                foreach ($users as $user) {
                    fputcsv($file, [
                        $user->id,
                        $user->name ?? 'N/A',
                        $user->email ?? 'N/A',
                        $user->phone ?? 'N/A',
                        $user->mac_address ?? 'N/A',
                        $user->ip_address ?? 'N/A',
                        ucfirst($user->status),
                        $user->connected_at ? $user->connected_at->format('d/m/Y H:i:s') : 'N/A',
                        $user->expires_at ? $user->expires_at->format('d/m/Y H:i:s') : 'N/A',
                        $user->created_at->format('d/m/Y H:i:s'),
                    ], ';');
                }
                fclose($file);
            };
            
            return response()->stream($callback, 200, $headers);
        }
        
        return back()->with('error', 'Formato de exportação não suportado');
    }
}
