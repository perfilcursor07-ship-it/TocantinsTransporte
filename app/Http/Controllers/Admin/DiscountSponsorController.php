<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DiscountSponsor;
use App\Services\DiscountSponsors\SponsorManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Patrocinadores do espaço de desconto do portal.
 *
 * Trocar quem ocupa o espaço é só ativar outro cadastro — nenhum deploy.
 */
class DiscountSponsorController extends Controller
{
    public function index()
    {
        $sponsors = DiscountSponsor::withCount([
            'leads',
            'leads as leads_com_desconto_count' => fn ($q) => $q->whereNotNull('discount_granted_at'),
        ])->orderByDesc('is_active')->orderBy('name')->get();

        return view('admin.sponsors.index', compact('sponsors'));
    }

    public function create()
    {
        return view('admin.sponsors.form', ['sponsor' => new DiscountSponsor(['type' => DiscountSponsor::TYPE_VIDEO])]);
    }

    public function edit(DiscountSponsor $sponsor)
    {
        return view('admin.sponsors.form', compact('sponsor'));
    }

    public function store(Request $request)
    {
        $sponsor = DiscountSponsor::create($this->dados($request));

        return redirect()->route('admin.sponsors.index')
            ->with('success', "Parceiro \"{$sponsor->name}\" cadastrado.");
    }

    public function update(Request $request, DiscountSponsor $sponsor)
    {
        $sponsor->update($this->dados($request, $sponsor));

        return redirect()->route('admin.sponsors.index')
            ->with('success', "Parceiro \"{$sponsor->name}\" atualizado.");
    }

    /** Ativa este e desativa os outros — só um ocupa o espaço por vez. */
    public function activate(DiscountSponsor $sponsor)
    {
        DiscountSponsor::where('id', '!=', $sponsor->id)->update(['is_active' => false]);
        $sponsor->update(['is_active' => true]);
        DiscountSponsor::clearCache();

        $driver = app(SponsorManager::class)->driver();
        $aviso = $driver ? null : 'Atenção: o parceiro está ativo mas ainda não aparece no portal — confira a configuração e se o desconto está habilitado.';

        return redirect()->route('admin.sponsors.index')
            ->with('success', "\"{$sponsor->name}\" agora ocupa o espaço de desconto.")
            ->with('warning', $aviso);
    }

    public function deactivate(DiscountSponsor $sponsor)
    {
        $sponsor->update(['is_active' => false]);

        return redirect()->route('admin.sponsors.index')
            ->with('success', 'Espaço de desconto desocupado. Nenhum parceiro aparece no portal.');
    }

    public function destroy(DiscountSponsor $sponsor)
    {
        $nome = $sponsor->name;
        $sponsor->delete();

        return redirect()->route('admin.sponsors.index')
            ->with('success', "Parceiro \"{$nome}\" removido.");
    }

    // ---------- Apoio ----------

    private function dados(Request $request, ?DiscountSponsor $atual = null): array
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'type' => 'required|in:' . implode(',', DiscountSponsor::TYPES),
            'headline' => 'nullable|string|max:120',
            'subheadline' => 'nullable|string|max:160',
            'discount_amount' => 'nullable|numeric|min:0.01|max:99.99',
            'min_seconds' => 'nullable|integer|min:1|max:300',
            'collects_cpf' => 'nullable|in:0,1',
            'notes' => 'nullable|string|max:1000',

            // vídeo
            'video_url' => 'nullable|string|max:500',
            'duration' => 'nullable|integer|min:1|max:600',

            // iframe
            'page_url' => 'nullable|url|max:500',
            'height' => 'nullable|integer|min:200|max:900',
            'cpf_query_param' => 'nullable|string|max:40',

            // api
            'base_url' => 'nullable|url|max:500',
            'token_path' => 'nullable|string|max:120',
            'session_path' => 'nullable|string|max:120',
            'style' => 'nullable|in:standard,cashcash',
            'open_in' => 'nullable|in:redirect,iframe',
            'requires_location' => 'nullable|in:0,1',
            'client_id_env' => 'nullable|string|max:80',
            'client_secret_env' => 'nullable|string|max:80',
        ]);

        $config = match ($validated['type']) {
            DiscountSponsor::TYPE_VIDEO => [
                'video_url' => $validated['video_url'] ?? null,
                'duration' => $validated['duration'] ?? null,
            ],
            DiscountSponsor::TYPE_IFRAME => [
                'page_url' => $validated['page_url'] ?? null,
                'height' => $validated['height'] ?? 520,
                'cpf_query_param' => $validated['cpf_query_param'] ?? null,
            ],
            DiscountSponsor::TYPE_API => [
                'base_url' => $validated['base_url'] ?? null,
                'token_path' => $validated['token_path'] ?: '/token',
                'session_path' => $validated['session_path'] ?: '/sessions',
                'style' => $validated['style'] ?: 'standard',
                'open_in' => $validated['open_in'] ?: 'redirect',
                'requires_location' => (bool) ($validated['requires_location'] ?? false),
                'client_id_env' => $validated['client_id_env'] ?? null,
                'client_secret_env' => $validated['client_secret_env'] ?? null,
            ],
            default => [],
        };

        return [
            'name' => $validated['name'],
            'slug' => $atual->slug ?? Str::slug($validated['name']) . '-' . Str::lower(Str::random(4)),
            'type' => $validated['type'],
            'headline' => $validated['headline'] ?? null,
            'subheadline' => $validated['subheadline'] ?? null,
            'discount_amount' => $validated['discount_amount'] ?? null,
            'min_seconds' => $validated['min_seconds'] ?? null,
            'collects_cpf' => (bool) ($validated['collects_cpf'] ?? false),
            'config' => $config,
            'notes' => $validated['notes'] ?? null,
            'is_active' => $atual->is_active ?? false,
        ];
    }
}
