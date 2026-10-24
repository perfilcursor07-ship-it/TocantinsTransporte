<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Documentação pública de integração para parceiros do espaço de desconto.
 *
 * Renderiza docs/INTEGRACAO-PARCEIROS.md — fonte única: editar o markdown
 * atualiza a página, sem mexer na view.
 *
 * Página de link direto: acessível a quem tem a URL, fora do buscador
 * (meta robots noindex na view) e sem link no site.
 */
class PartnerDocsController extends Controller
{
    private const ARQUIVO = 'docs/INTEGRACAO-PARCEIROS.md';

    public function __invoke()
    {
        $caminho = base_path(self::ARQUIVO);

        abort_unless(is_file($caminho), 404);

        // Cache invalidado pela data de modificação: salvou o .md, a página troca.
        $chave = 'partner_docs:' . filemtime($caminho);

        $conteudo = Cache::remember($chave, now()->addDay(), function () use ($caminho) {
            $markdown = file_get_contents($caminho);

            // Primeira linha é o H1; o cabeçalho "Para/Versão" não vai para a web.
            $markdown = preg_replace('/^\*\*Para:\*\*.*?\n\*\*Versão:\*\*.*?\n/ms', '', $markdown, 1);

            return Str::markdown($markdown, [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
            ]);
        });

        return response()
            ->view('parceiros.documentacao', [
                'conteudo' => $conteudo,
                'atualizadoEm' => date('d/m/Y', filemtime($caminho)),
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
