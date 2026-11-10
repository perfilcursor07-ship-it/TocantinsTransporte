<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Acréscimo manual na receita de um ônibus (ver a migration da tabela).
 *
 * Não é pagamento: não tem passageiro, não libera internet e não aparece na
 * lista de pagamentos. Só soma na receita do ônibus, no dia de reference_at.
 */
class BusRevenueAdjustment extends Model
{
    protected $fillable = [
        'bus_mikrotik_id',
        'quantity',
        'unit_amount',
        'total',
        'reference_at',
        'note',
        'created_by',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'unit_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'reference_at' => 'datetime',
    ];

    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeNoPeriodo($query, $inicio, $fim)
    {
        return $query->whereBetween('reference_at', [$inicio, $fim]);
    }

    /**
     * Totais por ônibus no período: [serial => (object) {total, count}].
     *
     * Se a tabela ainda não existe (deploy sem migrate), devolve vazio: o
     * relatório continua mostrando só os pagamentos, como antes.
     */
    public static function totaisPorOnibus($inicio, $fim, ?string $serial = null): array
    {
        try {
            $query = static::noPeriodo($inicio, $fim);
            if ($serial !== null) {
                $query->where('bus_mikrotik_id', $serial);
            }

            return $query->groupBy('bus_mikrotik_id')
                ->select(
                    'bus_mikrotik_id',
                    DB::raw('SUM(total) as total'),
                    DB::raw('SUM(quantity) as count')
                )
                ->get()
                ->mapWithKeys(fn ($l) => [$l->bus_mikrotik_id => (object) [
                    'total' => (float) $l->total,
                    'count' => (int) $l->count,
                ]])
                ->all();
        } catch (\Throwable $e) {
            report($e);
            return [];
        }
    }
}
