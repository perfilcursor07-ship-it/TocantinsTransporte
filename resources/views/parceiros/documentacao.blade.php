<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Página de link direto: não deve aparecer em buscador --}}
    <meta name="robots" content="noindex, nofollow">
    <title>Integração de Parceiros — {{ config('app.company_name', 'WiFi Tocantins') }}</title>
    <meta name="description" content="Como anunciar para os passageiros do WiFi Tocantins: vídeo, página embutida ou API.">
    <link rel="preconnect" href="https://fonts.googleapis.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <style>
        :root {
            --verde: #00A335; --verde-escuro: #007A28; --verde-claro: #E8F5E9;
            --tinta: #111827; --tinta2: #374151; --suave: #6b7280;
            --borda: #e5e7eb; --fundo: #ffffff; --painel: #f9fafb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--fundo); color: var(--tinta2);
            font-family: Inter, system-ui, -apple-system, "Segoe UI", sans-serif;
            font-size: 16px; line-height: 1.65;
            -webkit-text-size-adjust: 100%;
        }
        .topo {
            background: linear-gradient(135deg, var(--verde-escuro), var(--verde));
            color: #fff; padding: 28px 0;
        }
        .wrap { max-width: 820px; margin: 0 auto; padding: 0 20px; }
        .topo .marca { font-weight: 800; font-size: 15px; letter-spacing: .3px; opacity: .95; }
        .topo .sub { font-size: 13px; opacity: .85; margin-top: 2px; }

        main { padding: 40px 0 72px; }

        h1 {
            font-size: 30px; line-height: 1.25; color: var(--tinta);
            font-weight: 800; margin: 0 0 8px; letter-spacing: -.5px;
        }
        h2 {
            font-size: 22px; color: var(--tinta); font-weight: 700;
            margin: 44px 0 12px; padding-top: 20px; border-top: 1px solid var(--borda);
            letter-spacing: -.3px;
        }
        h3 { font-size: 17px; color: var(--tinta); font-weight: 700; margin: 28px 0 8px; }
        h4 { font-size: 15px; color: var(--tinta); font-weight: 700; margin: 22px 0 6px; }
        p { margin: 0 0 14px; }
        strong { color: var(--tinta); font-weight: 600; }

        ul, ol { margin: 0 0 14px; padding-left: 22px; }
        li { margin-bottom: 6px; }

        a { color: var(--verde-escuro); }

        code {
            background: var(--painel); border: 1px solid var(--borda);
            border-radius: 5px; padding: 1px 5px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
            font-size: .88em; color: #b02a37; white-space: nowrap;
        }
        pre {
            background: #0f172a; color: #e2e8f0; border-radius: 10px;
            padding: 16px; overflow-x: auto; margin: 0 0 16px;
            font-size: 13.5px; line-height: 1.6;
        }
        pre code {
            background: none; border: 0; padding: 0;
            color: inherit; font-size: inherit; white-space: pre;
        }

        table {
            width: 100%; border-collapse: collapse; margin: 0 0 18px;
            font-size: 14.5px; display: block; overflow-x: auto;
        }
        th, td { border: 1px solid var(--borda); padding: 9px 12px; text-align: left; vertical-align: top; }
        th { background: var(--painel); font-weight: 600; color: var(--tinta); white-space: nowrap; }

        blockquote {
            margin: 0 0 16px; padding: 12px 16px;
            background: #fffbeb; border-left: 4px solid #f59e0b;
            border-radius: 0 8px 8px 0; color: #78350f;
        }
        blockquote p:last-child { margin-bottom: 0; }

        hr { border: 0; border-top: 1px solid var(--borda); margin: 32px 0; display: none; }

        .rodape {
            border-top: 1px solid var(--borda); margin-top: 56px; padding-top: 20px;
            font-size: 13px; color: var(--suave);
        }

        @media (max-width: 640px) {
            body { font-size: 15.5px; }
            h1 { font-size: 25px; }
            h2 { font-size: 20px; }
            main { padding: 28px 0 52px; }
        }
        @media print {
            .topo { background: none; color: #000; border-bottom: 2px solid #000; }
            pre { background: #f4f4f5; color: #111; border: 1px solid #ddd; }
            h2 { page-break-after: avoid; }
            table, pre, blockquote { page-break-inside: avoid; }
        }
    </style>
</head>
<body>
    <header class="topo">
        <div class="wrap">
            <div class="marca">{{ config('app.company_name', 'WiFi Tocantins') }}</div>
            <div class="sub">Internet via Starlink em ônibus intermunicipais</div>
        </div>
    </header>

    <main>
        <div class="wrap">
            {!! $conteudo !!}

            <div class="rodape">
                Documento de integração para parceiros · atualizado em {{ $atualizadoEm }}
            </div>
        </div>
    </main>
</body>
</html>
