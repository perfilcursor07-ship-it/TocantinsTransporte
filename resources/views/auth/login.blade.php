<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Entrar — WiFi Tocantins</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; }
        body { font-family: Inter, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-[#f3f6f4] text-[#17221c] antialiased">
    <main class="grid min-h-screen place-items-center px-5 py-10">
        <div class="w-full max-w-[420px]">
            <div class="mb-7 flex justify-center">
                <img src="{{ asset('images/logo.png') }}" alt="WiFi Tocantins" class="h-11 w-auto object-contain">
            </div>

            <section class="w-full rounded-xl border border-[#e1e7e2] bg-white p-6 shadow-[0_12px_40px_rgba(23,34,28,0.08)] sm:p-8" aria-label="Acesso à conta">
                <div class="mb-8">
                    <div class="mb-6 inline-flex items-center gap-2 text-xs font-semibold text-emerald-800">
                        <span class="flex h-7 w-7 items-center justify-center rounded-md bg-emerald-100 text-emerald-800" aria-hidden="true">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 3 5 6v5c0 4.4 2.9 8.3 7 9.5 4.1-1.2 7-5.1 7-9.5V6l-7-3Zm-3 9 2 2 4-4"/>
                            </svg>
                        </span>
                        Acesso restrito a administradores
                    </div>
                    <p class="mt-2 text-sm leading-6 text-[#66736a]">Informe suas credenciais para continuar.</p>
                </div>

                @if ($errors->any())
                    <div role="alert" aria-live="polite" class="mb-6 flex gap-3 rounded-lg border border-red-200 bg-red-50 px-4 py-3.5 text-sm text-red-800">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M12 9v3m0 4h.01M10.3 3.9 1.9 18a2 2 0 0 0 1.7 3h16.8a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/>
                        </svg>
                        <div>
                            @foreach ($errors->all() as $error)
                                <p>{{ $error }}</p>
                            @endforeach
                        </div>
                    </div>
                @endif

                @if (session('success'))
                    <div role="status" class="mb-6 flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3.5 text-sm text-emerald-800">
                        <svg class="mt-0.5 h-5 w-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="m5 12 4 4L19 6"/>
                        </svg>
                        <p>{{ session('success') }}</p>
                    </div>
                @endif

                <form method="POST" action="{{ route('login') }}" id="login-form" class="space-y-5">
                    @csrf

                    <div>
                        <label for="email" class="mb-2 block text-sm font-semibold text-[#29372e]">E-mail</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-[#849087]" aria-hidden="true">
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 6h16a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2Zm0 1 8 6 8-6"/>
                                </svg>
                            </span>
                            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}" placeholder="seu@email.com"
                                   aria-invalid="{{ $errors->has('email') ? 'true' : 'false' }}"
                                   class="h-[50px] w-full rounded-lg border border-[#d7dfd9] bg-white pl-11 pr-4 text-sm text-[#17221c] outline-none transition placeholder:text-[#9aa59d] hover:border-[#aab8ae] focus:border-emerald-700 focus:ring-4 focus:ring-emerald-700/10 @error('email') border-red-400 @enderror">
                        </div>
                    </div>

                    <div>
                        <label for="password" class="mb-2 block text-sm font-semibold text-[#29372e]">Senha</label>
                        <div class="relative">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-[#849087]" aria-hidden="true">
                                <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 10V7a5 5 0 0 1 10 0v3m-11 0h12a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Zm6 4v3"/>
                                </svg>
                            </span>
                            <input id="password" name="password" type="password" autocomplete="current-password" required placeholder="Sua senha"
                                   aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}"
                                   class="h-[50px] w-full rounded-lg border border-[#d7dfd9] bg-white pl-11 pr-12 text-sm text-[#17221c] outline-none transition placeholder:text-[#9aa59d] hover:border-[#aab8ae] focus:border-emerald-700 focus:ring-4 focus:ring-emerald-700/10 @error('password') border-red-400 @enderror">
                            <button type="button" id="toggle-password" aria-label="Mostrar senha" aria-pressed="false" title="Mostrar senha"
                                    class="absolute inset-y-0 right-0 flex w-11 items-center justify-center rounded-r-lg text-[#7a887e] transition hover:text-[#17633f] focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-emerald-700">
                                <svg id="eye-icon" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Zm9.5 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div class="flex items-center justify-between pt-1">
                        <label for="remember" class="inline-flex cursor-pointer select-none items-center gap-2.5 text-sm text-[#66736a]">
                            <input id="remember" type="checkbox" name="remember" @checked(old('remember')) class="h-4 w-4 rounded border-[#bcc8bf] accent-emerald-700 focus:ring-emerald-700/20">
                            Lembrar-me
                        </label>
                        <span class="inline-flex items-center gap-1.5 text-xs text-[#7b887f]">
                            <svg class="h-3.5 w-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M7 10V7a5 5 0 0 1 10 0v3m-11 0h12a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2Z"/>
                            </svg>
                            Conexão segura
                        </span>
                    </div>

                    <button type="submit" id="submit-btn"
                            class="mt-2 inline-flex h-[50px] w-full items-center justify-center gap-2 rounded-lg bg-[#087a3b] px-4 text-sm font-bold text-white shadow-sm transition hover:bg-[#066a33] focus:outline-none focus-visible:ring-4 focus-visible:ring-emerald-700/25 active:bg-[#055a2b]">
                        <svg id="btn-icon" class="h-[18px] w-[18px]" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M10 17l5-5-5-5m5 5H3m9-9h6a3 3 0 0 1 3 3v12a3 3 0 0 1-3 3h-6"/>
                        </svg>
                        <span id="btn-text">Entrar</span>
                    </button>
                </form>

                <p class="mt-8 border-t border-[#e1e7e2] pt-5 text-center text-xs leading-5 text-[#849087]">
                    © {{ date('Y') }} WiFi Tocantins Express · Acesso administrativo
                </p>
            </section>
        </div>
    </main>

    <script>
        const toggleBtn = document.getElementById('toggle-password');
        const pwdInput = document.getElementById('password');
        const eyeIcon = document.getElementById('eye-icon');

        toggleBtn.addEventListener('click', () => {
            const showPassword = pwdInput.type === 'password';
            pwdInput.type = showPassword ? 'text' : 'password';
            toggleBtn.setAttribute('aria-pressed', String(showPassword));
            toggleBtn.setAttribute('aria-label', showPassword ? 'Ocultar senha' : 'Mostrar senha');
            toggleBtn.setAttribute('title', showPassword ? 'Ocultar senha' : 'Mostrar senha');
            eyeIcon.innerHTML = showPassword
                ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="m3 3 18 18M10.6 10.6a2 2 0 0 0 2.8 2.8m-5.5-5.5A9.8 9.8 0 0 1 12 6c6.1 0 9.5 6 9.5 6a15.7 15.7 0 0 1-3.1 3.7M6.2 6.2C3.8 7.8 2.5 12 2.5 12s3.4 6 9.5 6a9.8 9.8 0 0 0 3.4-.6"/>'
                : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Zm9.5 3a3 3 0 1 0 0-6 3 3 0 0 0 0 6Z"/>';
        });

        document.getElementById('login-form').addEventListener('submit', function () {
            const btn = document.getElementById('submit-btn');
            const text = document.getElementById('btn-text');
            const icon = document.getElementById('btn-icon');
            btn.disabled = true;
            btn.classList.add('cursor-not-allowed', 'opacity-75');
            icon.innerHTML = '<circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" class="opacity-25"/><path fill="currentColor" class="opacity-75" d="M4 12a8 8 0 0 1 8-8V0C5.4 0 0 5.4 0 12h4Z"/>';
            icon.classList.add('animate-spin');
            text.textContent = 'Entrando...';
        });
    </script>
</body>
</html>
