<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BusHealthLog;
use Illuminate\Http\Request;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class MikrotikRemoteController extends Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;
    public function index()
    {
        return view('admin.mikrotik.remote');
    }

    /**
     * 🩺 Dashboard de saúde dos 8 MikroTiks (ônibus + Starlink).
     *
     * Cada MikroTik chama /api/mikrotik/check-paid-users-lite a cada 15s e isso
     * atualiza buses.last_sync_at. Se o sync parou, ou o ônibus está desligado,
     * ou a Starlink caiu, ou a rota do hotspot parou — de qualquer forma o
     * usuário não consegue pagar/conectar naquele ônibus. Este painel mostra
     * isso ANTES da reclamação chegar.
     */
    public function health()
    {
        $buses = \App\Models\Bus::orderBy('name')->get();

        $data = $buses->map(function ($bus) {
            $seconds = $bus->last_sync_at ? $bus->last_sync_at->diffInSeconds(now()) : null;

            if ($seconds === null) {
                $status = 'unknown';
            } elseif ($seconds <= 30) {
                $status = 'online';
            } elseif ($seconds <= 300) {
                $status = 'lagging';
            } else {
                $status = 'offline';
            }

            $activeUsers = User::where('last_mikrotik_id', $bus->mikrotik_serial)
                ->whereIn('status', ['connected', 'active', 'temp_bypass'])
                ->where('expires_at', '>', now())
                ->count();

            // Última latência medida (pegar o log mais recente com latência)
            $lastLatency = BusHealthLog::where('bus_id', $bus->id)
                ->whereNotNull('latency_ms')
                ->orderByDesc('recorded_at')
                ->value('latency_ms');

            return [
                'bus' => $bus,
                'status' => $status,
                'seconds_since_sync' => $seconds,
                'active_users' => $activeUsers,
                'latency_ms' => $lastLatency,
            ];
        });

        $summary = [
            'total' => $data->count(),
            'online' => $data->where('status', 'online')->count(),
            'lagging' => $data->where('status', 'lagging')->count(),
            'offline' => $data->whereIn('status', ['offline', 'unknown'])->count(),
            'total_users' => $data->sum('active_users'),
        ];

        // Histórico dos últimos 7 dias para cada ônibus
        $days = 7;
        $startDate = now()->subDays($days - 1)->startOfDay();

        $historyRaw = BusHealthLog::where('recorded_at', '>=', $startDate)
            ->orderBy('recorded_at')
            ->get();

        // Organizar: bus_id => [date => [logs]]
        $history = [];
        foreach ($buses as $bus) {
            $busLogs = $historyRaw->where('bus_id', $bus->id);
            $dailyData = [];

            for ($i = 0; $i < $days; $i++) {
                $date = now()->subDays($days - 1 - $i)->format('Y-m-d');
                $dayLogs = $busLogs->filter(fn($log) => $log->recorded_at->format('Y-m-d') === $date)->sortBy('recorded_at')->values();

                $totalChecks = $dayLogs->count();
                $onlineChecks = $dayLogs->whereIn('status', ['online', 'lagging'])->count();

                // Cada check = 5 minutos. online_minutes = checks_online * 5
                $onlineMinutes = $onlineChecks * 5;
                $totalMinutes = $totalChecks * 5;
                $uptimePercent = $totalChecks > 0 ? round(($onlineChecks / $totalChecks) * 100, 1) : null;

                // Detectar eventos de queda e retorno (transições)
                $events = [];
                $prevStatus = null;
                $offlineStart = null;

                foreach ($dayLogs as $log) {
                    $isOnline = in_array($log->status, ['online', 'lagging']);
                    $wasOnline = $prevStatus === null ? $isOnline : in_array($prevStatus, ['online', 'lagging']);

                    if ($wasOnline && !$isOnline) {
                        // Transição: ficou offline
                        $offlineStart = $log->recorded_at;
                        $events[] = [
                            'type' => 'went_offline',
                            'at' => $log->recorded_at->format('H:i'),
                            'timestamp' => $log->recorded_at->toIso8601String(),
                        ];
                    } elseif (!$wasOnline && $isOnline && $prevStatus !== null) {
                        // Transição: voltou online
                        $duration = $offlineStart ? $offlineStart->diffInMinutes($log->recorded_at) : null;
                        $events[] = [
                            'type' => 'came_online',
                            'at' => $log->recorded_at->format('H:i'),
                            'timestamp' => $log->recorded_at->toIso8601String(),
                            'offline_duration_min' => $duration,
                        ];
                        $offlineStart = null;
                    }

                    $prevStatus = $log->status;
                }

                // Se terminou o dia offline (ou está offline agora no dia de hoje)
                if ($prevStatus && !in_array($prevStatus, ['online', 'lagging']) && $offlineStart) {
                    $isToday = $date === now()->format('Y-m-d');
                    $endTime = $isToday ? now() : Carbon::parse($date)->endOfDay();
                    $duration = $offlineStart->diffInMinutes($endTime);
                    $events[] = [
                        'type' => 'still_offline',
                        'since' => $offlineStart->format('H:i'),
                        'duration_min' => $duration,
                    ];
                }

                $dailyData[] = [
                    'date' => $date,
                    'date_label' => Carbon::parse($date)->format('d/m'),
                    'day_name' => Carbon::parse($date)->locale('pt_BR')->isoFormat('ddd'),
                    'total_checks' => $totalChecks,
                    'online_checks' => $onlineChecks,
                    'offline_checks' => $totalChecks - $onlineChecks,
                    'online_minutes' => $onlineMinutes,
                    'total_minutes' => $totalMinutes,
                    'uptime_percent' => $uptimePercent,
                    'online_hours' => round($onlineMinutes / 60, 1),
                    'total_hours' => round($totalMinutes / 60, 1),
                    'events' => $events,
                ];
            }

            $history[$bus->id] = $dailyData;
        }

        return view('admin.mikrotik.saude', compact('data', 'summary', 'history', 'days'));
    }

    /**
     * Endpoint JSON para auto-refresh da saúde sem recarregar a página.
     */
    public function healthJson()
    {
        $buses = \App\Models\Bus::orderBy('name')->get();

        $data = $buses->map(function ($bus) {
            $seconds = $bus->last_sync_at ? $bus->last_sync_at->diffInSeconds(now()) : null;
            if ($seconds === null) $status = 'unknown';
            elseif ($seconds <= 30) $status = 'online';
            elseif ($seconds <= 300) $status = 'lagging';
            else $status = 'offline';

            $activeUsers = User::where('last_mikrotik_id', $bus->mikrotik_serial)
                ->whereIn('status', ['connected', 'active', 'temp_bypass'])
                ->where('expires_at', '>', now())
                ->count();

            $lastLatency = BusHealthLog::where('bus_id', $bus->id)
                ->whereNotNull('latency_ms')
                ->orderByDesc('recorded_at')
                ->value('latency_ms');

            return [
                'serial' => $bus->mikrotik_serial,
                'name' => $bus->name,
                'plate' => $bus->plate,
                'status' => $status,
                'seconds_since_sync' => $seconds,
                'last_sync_at' => $bus->last_sync_at?->toIso8601String(),
                'last_public_ip' => $bus->last_public_ip,
                'last_city' => $bus->last_city,
                'last_state' => $bus->last_state,
                'active_users' => $activeUsers,
                'latency_ms' => $lastLatency,
            ];
        });

        return response()->json([
            'data' => $data,
            'checked_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Retorna dados dos usuários com MAC - fonte de verdade para o MikroTik
     * O MikroTik consulta /api/mikrotik/check-paid-users-lite a cada 15s
     * Então o que está aqui = o que o MikroTik vai executar
     */
    public function getStatus()
    {
        try {
            // Usuários liberados (MAC vai como L: para o MikroTik)
            $liberados = User::whereIn('status', ['connected', 'active', 'temp_bypass'])
                ->where('expires_at', '>', now())
                ->whereNotNull('mac_address')
                ->where('mac_address', '!=', '')
                ->orderBy('expires_at', 'desc')
                ->get(['id', 'name', 'phone', 'mac_address', 'ip_address', 'status', 'connected_at', 'expires_at', 'device_name']);

            // Usuários expirados recentes (MAC vai como R: para o MikroTik)
            $expirados = User::where('status', 'expired')
                ->whereNotNull('mac_address')
                ->where('mac_address', '!=', '')
                ->where('expires_at', '>', now()->subHours(24))
                ->where('expires_at', '<', now())
                ->orderBy('expires_at', 'desc')
                ->get(['id', 'name', 'phone', 'mac_address', 'ip_address', 'status', 'connected_at', 'expires_at', 'device_name']);

            // Todos os usuários com MAC (histórico)
            $todos = User::whereNotNull('mac_address')
                ->where('mac_address', '!=', '')
                ->orderBy('updated_at', 'desc')
                ->limit(100)
                ->get(['id', 'name', 'phone', 'mac_address', 'ip_address', 'status', 'connected_at', 'expires_at', 'device_name', 'updated_at']);

            return response()->json([
                'success' => true,
                'liberados' => $liberados,
                'expirados' => $expirados,
                'todos' => $todos,
                'stats' => [
                    'total_liberados' => $liberados->count(),
                    'total_expirados' => $expirados->count(),
                    'total_registrados' => $todos->count(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('MikrotikRemote getStatus error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Liberar MAC - Adiciona na lista que o MikroTik consulta
     * Seta status=connected e expires_at para +24h
     */
    public function liberateMac(Request $request)
    {
        $request->validate([
            'mac' => 'required|string',
            'phone' => 'nullable|string',
            'hours' => 'nullable|integer|min:1|max:720',
        ]);

        try {
            $mac = strtoupper(trim($request->input('mac')));
            $phone = $request->input('phone', '');
            $hours = $request->input('hours', 24);

            // Verificar se já existe um usuário com esse MAC
            $user = User::where('mac_address', $mac)->first();

            if ($user) {
                // Atualizar usuário existente
                $user->update([
                    'status' => 'connected',
                    'connected_at' => now(),
                    'expires_at' => now()->addHours($hours),
                    'phone' => $phone ?: $user->phone,
                ]);
                cache()->forget('admin_blocked_user_'.$user->id);
                $message = "MAC {$mac} RE-liberado por {$hours}h";
            } else {
                // Criar novo usuário
                $user = User::create([
                    'name' => 'Manual - ' . $mac,
                    'mac_address' => $mac,
                    'phone' => $phone,
                    'status' => 'connected',
                    'connected_at' => now(),
                    'expires_at' => now()->addHours($hours),
                    'ip_address' => '',
                    'password' => bcrypt('manual-' . $mac),
                ]);
                $message = "MAC {$mac} liberado por {$hours}h (novo usuário)";
            }

            Log::info("🟢 Admin: $message", ['mac' => $mac, 'phone' => $phone, 'hours' => $hours]);

            return response()->json([
                'success' => true,
                'message' => $message,
                'user' => $user,
            ]);
        } catch (\Exception $e) {
            Log::error('MikrotikRemote liberateMac error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bloquear MAC - Remove da lista que o MikroTik consulta
     * Seta status=expired e expires_at para agora
     */
    public function blockMac(Request $request)
    {
        $request->validate([
            'mac' => 'required|string',
        ]);

        try {
            $mac = strtoupper(trim($request->input('mac')));

            $user = User::where('mac_address', $mac)->first();

            if (!$user) {
                return response()->json(['error' => 'MAC não encontrado no sistema'], 404);
            }

            $user->update([
                'status' => 'expired',
                'expires_at' => now()->subMinute(), // Expirou há 1 min
                'connected_at' => null,
            ]);

            // Sem esta marca o auto-heal e a reconciliação reativavam o usuário
            // em 1-2 min quando ele tinha pagamento recente. Vale 7 dias (maior
            // plano) e cai sozinha com um pagamento novo ou liberação manual.
            cache()->put('admin_blocked_user_'.$user->id, true, now()->addDays(7));
            cache()->forget('mikrotik_sync_lists_all');

            Log::info("🔴 Admin: MAC {$mac} bloqueado",['user_id' => $user->id, 'phone' => $user->phone]);

            return response()->json([
                'success' => true,
                'message' => "MAC {$mac} bloqueado. O MikroTik vai removê-lo na próxima sincronização (~15s).",
            ]);
        } catch (\Exception $e) {
            Log::error('MikrotikRemote blockMac error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Sincronizar agora - Mostra o que o MikroTik vai receber
     * (preview do check-paid-users-lite)
     */
    public function syncNow()
    {
        try {
            // Simular resposta do check-paid-users-lite
            $activeMacs = User::whereIn('status', ['connected', 'active', 'temp_bypass'])
                ->where('expires_at', '>', now())
                ->whereNotNull('mac_address')
                ->where('mac_address', '!=', '')
                ->pluck('mac_address')
                ->map(fn($mac) => strtoupper(trim($mac)))
                ->unique()
                ->values()
                ->toArray();

            $expiredMacs = User::where('status', 'expired')
                ->whereNotNull('mac_address')
                ->where('mac_address', '!=', '')
                ->whereNotIn('mac_address', $activeMacs)
                ->where('expires_at', '>', now()->subHours(24))
                ->where('expires_at', '<', now())
                ->pluck('mac_address')
                ->map(fn($mac) => strtoupper(trim($mac)))
                ->unique()
                ->values()
                ->toArray();

            $output = "OK\n";
            foreach ($activeMacs as $mac) {
                $output .= "L:$mac\n";
            }
            foreach ($expiredMacs as $mac) {
                $output .= "R:$mac\n";
            }
            $output .= "END";

            return response()->json([
                'success' => true,
                'message' => 'Preview da resposta da API para o MikroTik',
                'api_response' => $output,
                'stats' => [
                    'liberar' => count($activeMacs),
                    'remover' => count($expiredMacs),
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retorna logs do sistema relacionados ao MikroTik
     */
    public function getLogs()
    {
        try {
            $logFile = storage_path('logs/laravel.log');
            
            if (!file_exists($logFile)) {
                return response()->json(['success' => true, 'logs' => []]);
            }

            // Ler apenas as últimas ~100KB do arquivo (evita estouro de memória em logs grandes)
            $maxBytes = 100 * 1024;
            $fileSize = filesize($logFile);
            $offset = max(0, $fileSize - $maxBytes);

            $handle = fopen($logFile, 'r');
            if (!$handle) {
                return response()->json(['success' => true, 'logs' => ['Não foi possível abrir o arquivo de log']]);
            }

            if ($offset > 0) {
                fseek($handle, $offset);
                fgets($handle); // Descarta linha parcial
            }

            $lines = [];
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') continue;

                if (stripos($line, 'mikrotik') !== false 
                    || stripos($line, 'sync') !== false
                    || stripos($line, 'PAGO') !== false
                    || stripos($line, 'liberar') !== false
                    || stripos($line, 'remover') !== false
                    || stripos($line, 'AUTO-HEAL') !== false
                    || stripos($line, 'CROSS-REF') !== false
                    || stripos($line, 'REATIVAD') !== false
                    || stripos($line, 'MAC') !== false) {
                    $lines[] = $line;
                }
            }
            fclose($handle);

            return response()->json([
                'success' => true,
                'logs' => array_slice($lines, -50),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Retorna logs de bypass temporário (aprovados e negados)
     */
    public function getBypassLogs(Request $request)
    {
        try {
            $query = \App\Models\TempBypassLog::orderBy('created_at', 'desc');

            // Filtros opcionais
            if ($request->filled('mac')) {
                $query->where('mac_address', 'like', '%' . $request->mac . '%');
            }
            if ($request->filled('phone')) {
                $query->where('phone', 'like', '%' . $request->phone . '%');
            }
            if ($request->filled('denied_only')) {
                $query->where('was_denied', true);
            }

            $logs = $query->limit(100)->get();

            // Adicionar info de bloqueio a cada log
            $logs->transform(function ($log) {
                $log->is_blocked = false;
                if ($log->mac_address) {
                    $blockInfo = \Illuminate\Support\Facades\Cache::get('bypass_blocked_' . strtoupper($log->mac_address));
                    if ($blockInfo) {
                        $log->is_blocked = true;
                        $log->blocked_by = $blockInfo['blocked_by'] ?? 'Admin';
                        $log->blocked_at = $blockInfo['blocked_at'] ?? null;
                    }
                }
                return $log;
            });

            // Estatísticas
            $today = now()->startOfDay();
            $stats = [
                'total_hoje' => \App\Models\TempBypassLog::where('created_at', '>=', $today)->count(),
                'aprovados_hoje' => \App\Models\TempBypassLog::where('created_at', '>=', $today)->where('was_denied', false)->count(),
                'negados_hoje' => \App\Models\TempBypassLog::where('created_at', '>=', $today)->where('was_denied', true)->count(),
                'total_geral' => \App\Models\TempBypassLog::count(),
            ];

            return response()->json([
                'success' => true,
                'logs' => $logs,
                'stats' => $stats,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Resetar contador de bypass para um MAC/telefone
     * Limpa o cache de anti-abuso para que o usuário possa usar mais bypasses
     */
    public function resetBypass(Request $request)
    {
        $request->validate([
            'mac' => 'nullable|string',
            'phone' => 'nullable|string',
        ]);

        try {
            $mac = $request->input('mac');
            $phone = $request->input('phone');
            $resetted = [];

            if ($mac) {
                $macKey = 'bypass_mac_' . strtoupper(trim($mac));
                \Illuminate\Support\Facades\Cache::forget($macKey);
                $resetted[] = "MAC: {$mac}";
            }

            if ($phone) {
                $phoneKey = 'bypass_phone_' . $phone;
                \Illuminate\Support\Facades\Cache::forget($phoneKey);
                $resetted[] = "Phone: {$phone}";
            }

            if (empty($resetted)) {
                return response()->json(['error' => 'Informe MAC ou telefone para resetar'], 422);
            }

            $desc = implode(', ', $resetted);
            Log::info("🔄 Admin: Bypass resetado para {$desc}");

            return response()->json([
                'success' => true,
                'message' => "Bypass resetado para {$desc}. O usuário pode usar mais 2 liberações.",
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao resetar bypass: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Bloquear MAC de usar bypass por 12 horas
     * O usuário não poderá usar liberação temporária durante esse período
     */
    public function blockBypass(Request $request)
    {
        $request->validate([
            'mac' => 'required|string',
            'phone' => 'nullable|string',
        ]);

        try {
            $mac = strtoupper(trim($request->input('mac')));
            $phone = $request->input('phone');

            // Bloquear MAC por 12 horas
            $blockKey = 'bypass_blocked_' . $mac;
            \Illuminate\Support\Facades\Cache::put($blockKey, [
                'blocked_at' => now()->toISOString(),
                'blocked_by' => auth()->user()->name ?? 'Admin',
                'phone' => $phone,
            ], now()->addHours(12));

            // Também bloquear por telefone se disponível
            if ($phone) {
                $phoneBlockKey = 'bypass_blocked_phone_' . preg_replace('/[^\d]/', '', $phone);
                \Illuminate\Support\Facades\Cache::put($phoneBlockKey, [
                    'blocked_at' => now()->toISOString(),
                    'blocked_by' => auth()->user()->name ?? 'Admin',
                    'mac' => $mac,
                ], now()->addHours(12));
            }

            Log::info("🚫 Admin: Bypass BLOQUEADO por 12h", [
                'mac' => $mac,
                'phone' => $phone,
                'blocked_by' => auth()->user()->name ?? 'Admin',
            ]);

            return response()->json([
                'success' => true,
                'message' => "MAC {$mac} bloqueado de usar bypass por 12 horas.",
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao bloquear bypass: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Desbloquear MAC do bypass
     */
    public function unblockBypass(Request $request)
    {
        $request->validate([
            'mac' => 'required|string',
            'phone' => 'nullable|string',
        ]);

        try {
            $mac = strtoupper(trim($request->input('mac')));
            $phone = $request->input('phone');

            \Illuminate\Support\Facades\Cache::forget('bypass_blocked_' . $mac);

            if ($phone) {
                \Illuminate\Support\Facades\Cache::forget('bypass_blocked_phone_' . preg_replace('/[^\d]/', '', $phone));
            }

            Log::info("✅ Admin: Bypass DESBLOQUEADO", ['mac' => $mac, 'phone' => $phone]);

            return response()->json([
                'success' => true,
                'message' => "MAC {$mac} desbloqueado. Pode usar bypass novamente.",
            ]);
        } catch (\Exception $e) {
            Log::error('Erro ao desbloquear bypass: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Editar tempo de expiração de um usuário
     */
    public function editExpiration(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer',
            'hours' => 'required|integer|min:1|max:720',
        ]);

        try {
            $user = User::findOrFail($request->input('user_id'));
            
            $user->update([
                'expires_at' => now()->addHours($request->input('hours')),
                'status' => 'connected',
                'connected_at' => $user->connected_at ?? now(),
            ]);

            Log::info("✏️ Admin: Expiração de {$user->mac_address} alterada para +{$request->input('hours')}h");

            return response()->json([
                'success' => true,
                'message' => "Expiração atualizada para +{$request->input('hours')}h",
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Lista todos os ônibus cadastrados
     */
    public function getBuses()
    {
        $buses = \App\Models\Bus::orderBy('name')->get();
        return response()->json(['success' => true, 'buses' => $buses]);
    }

    /**
     * Atualiza nome/placa/rota de um ônibus
     */
    public function updateBus(Request $request)
    {
        $request->validate([
            'id' => 'required|integer|exists:buses,id',
            'name' => 'required|string|max:100',
            'plate' => 'nullable|string|max:15',
            'route_description' => 'nullable|string|max:255',
        ]);

        try {
            $bus = \App\Models\Bus::findOrFail($request->input('id'));
            $bus->update($request->only('name', 'plate', 'route_description'));

            return response()->json([
                'success' => true,
                'message' => "Ônibus '{$bus->name}' atualizado",
                'bus' => $bus,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Atualiza geolocalização de todos os ônibus via IP público
     */
    public function updateBusLocations()
    {
        try {
            $buses = \App\Models\Bus::whereNotNull('last_public_ip')
                ->where('last_sync_at', '>', now()->subHours(12))
                ->get();

            $updated = 0;
            foreach ($buses as $bus) {
                try {
                    $resp = \Illuminate\Support\Facades\Http::timeout(5)
                        ->get("http://ip-api.com/json/{$bus->last_public_ip}?fields=status,city,regionName,lat,lon&lang=pt-BR");

                    if ($resp->successful()) {
                        $data = $resp->json();
                        if (($data['status'] ?? '') === 'success') {
                            $bus->update([
                                'last_city' => $data['city'] ?? null,
                                'last_state' => $data['regionName'] ?? null,
                                'last_lat' => $data['lat'] ?? null,
                                'last_lng' => $data['lon'] ?? null,
                            ]);
                            $updated++;
                        }
                    }
                    // ip-api.com free: max 45 req/min
                    usleep(1500000);
                } catch (\Exception $e) {
                    continue;
                }
            }

            return response()->json(['success' => true, 'updated' => $updated, 'total' => $buses->count()]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
