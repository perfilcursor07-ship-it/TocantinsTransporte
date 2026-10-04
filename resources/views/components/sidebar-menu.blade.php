@php
    $authUser = Auth::user();
    $isAdmin = $authUser->role === 'admin';

    // Mesmos links, permissões e rotas de antes — só organizados em seções.
    $sections = [
        'Principal' => [
            ['show' => $authUser->hasModule('dashboard'), 'route' => 'admin.dashboard', 'match' => 'admin.dashboard', 'label' => 'Dashboard',
             'icon' => 'M4 5a1 1 0 011-1h14a1 1 0 011 1v2a1 1 0 01-1 1H5a1 1 0 01-1-1V5zM4 13a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H5a1 1 0 01-1-1v-6zM16 13a1 1 0 011-1h2a1 1 0 011 1v6a1 1 0 01-1 1h-2a1 1 0 01-1-1v-6z'],
            ['show' => $authUser->hasModule('reports'), 'route' => 'admin.reports', 'match' => 'admin.reports*', 'label' => 'Relatórios',
             'icon' => 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
        ],
        'Gestão' => [
            ['show' => $isAdmin, 'route' => 'admin.users', 'match' => 'admin.users*', 'label' => 'Usuários',
             'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
            ['show' => $authUser->hasModule('vouchers'), 'route' => 'admin.vouchers.index', 'match' => 'admin.vouchers*', 'label' => 'Vouchers',
             'icon' => 'M15 5v2m0 4v2m0 4v2M5 5a2 2 0 00-2 2v3a2 2 0 110 4v3a2 2 0 002 2h14a2 2 0 002-2v-3a2 2 0 110-4V7a2 2 0 00-2-2H5z'],
            ['show' => $authUser->hasModule('tourism'), 'route' => 'admin.tourism.index', 'match' => 'admin.tourism*', 'label' => 'Turismo',
             'icon' => 'M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
            ['show' => $isAdmin, 'route' => 'admin.devices', 'match' => 'admin.devices*', 'label' => 'Dispositivos',
             'icon' => 'M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z'],
            ['show' => $isAdmin, 'route' => 'admin.driver-pix.index', 'match' => 'admin.driver-pix*', 'label' => 'Pagamentos', 'title' => 'Pagamentos Motoristas',
             'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
        ],
        'Comunicação' => [
            ['show' => $authUser->hasModule('chat') && $isAdmin, 'route' => 'admin.chat.index', 'match' => 'admin.chat*', 'label' => 'Chat', 'chat_badge' => true,
             'icon' => 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
            ['show' => $isAdmin, 'route' => 'admin.whatsapp.index', 'match' => 'admin.whatsapp*', 'label' => 'WhatsApp', 'whatsapp' => true],
            ['show' => $authUser->hasModule('reviews'), 'route' => 'admin.reviews.index', 'match' => 'admin.reviews*', 'label' => 'Avaliações',
             'icon' => 'M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.124 3.457a1 1 0 00.95.69h3.636c.969 0 1.371 1.24.588 1.81l-2.942 2.137a1 1 0 00-.364 1.118l1.124 3.457c.3.921-.755 1.688-1.539 1.118l-2.942-2.137a1 1 0 00-1.176 0l-2.942 2.137c-.783.57-1.838-.197-1.539-1.118l1.124-3.457a1 1 0 00-.364-1.118L2.75 8.884c-.783-.57-.38-1.81.588-1.81h3.636a1 1 0 00.95-.69l1.124-3.457z'],
        ],
        'Sistema' => [
            ['show' => $isAdmin, 'route' => 'admin.mikrotik.health', 'match' => 'admin.mikrotik.health', 'label' => 'Saúde', 'title' => 'Saúde dos MikroTiks',
             'icon' => 'M4.318 6.318a4.5 4.5 0 000 6.364L12 20.364l7.682-7.682a4.5 4.5 0 00-6.364-6.364L12 7.636l-1.318-1.318a4.5 4.5 0 00-6.364 0z'],
            ['show' => $isAdmin, 'route' => 'admin.mikrotik.remote.index', 'match' => 'admin.mikrotik.remote*', 'label' => 'MikroTik',
             'icon' => 'M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z'],
            ['show' => $isAdmin, 'route' => 'admin.settings.index', 'match' => 'admin.settings*', 'label' => 'Configurações', 'settings' => true,
             'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z'],
        ],
    ];
@endphp

<!-- Sidebar -->
<aside id="sidebar"
       class="ui-modern admin-sidebar fixed inset-y-0 left-0 z-[60] transform transition-all duration-300 ease-in-out
              -translate-x-full lg:translate-x-0 flex flex-col w-64 sidebar-expanded">

    <!-- Brand -->
    <div class="flex items-center justify-between h-16 px-4 flex-shrink-0">
        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 min-w-0">
            <div class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0 bg-gradient-to-br from-emerald-400 to-green-600 shadow-lg shadow-green-900/40">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M8.111 16.404a5.5 5.5 0 017.778 0M12 20h.01m-7.08-7.071c3.904-3.905 10.236-3.905 14.141 0M1.394 9.393c5.857-5.857 15.355-5.857 21.213 0"/>
                </svg>
            </div>
            <div class="min-w-0 sidebar-label">
                <p class="text-[15px] font-extrabold text-white leading-tight tracking-tight truncate">WiFi Tocantins</p>
                <p class="text-[10px] font-semibold uppercase tracking-[0.14em] text-emerald-300/80 leading-none mt-0.5">Starlink · Admin</p>
            </div>
        </a>
        <div class="flex items-center gap-1">
            <!-- Collapse toggle (desktop) -->
            <button onclick="collapseSidebar()" id="collapseBtn"
                    class="hidden lg:flex w-8 h-8 items-center justify-center text-white/40 hover:text-white hover:bg-white/10 rounded-lg transition-colors"
                    title="Recolher menu">
                <svg id="collapseIcon" class="w-4 h-4 transition-transform duration-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"/>
                </svg>
            </button>
            <!-- Close (mobile) -->
            <button onclick="toggleSidebar()" class="lg:hidden w-8 h-8 flex items-center justify-center text-white/50 hover:text-white hover:bg-white/10 rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>
    </div>

    <!-- Expand button (visible only when collapsed on desktop) -->
    <button onclick="collapseSidebar()" id="expandBtn"
            class="hidden mx-auto mb-2 w-10 h-10 items-center justify-center rounded-xl text-white/60 bg-white/5 hover:bg-white/10 hover:text-white transition-colors"
            title="Expandir menu">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 5l7 7-7 7M5 5l7 7-7 7"/>
        </svg>
    </button>

    <div class="mx-4 h-px bg-white/10 flex-shrink-0 sidebar-label"></div>

    <!-- Nav -->
    <nav class="flex-1 px-3 py-4 overflow-y-auto space-y-5 sidebar-scroll">
        @foreach($sections as $sectionLabel => $items)
            @php $visibleItems = array_filter($items, fn ($i) => $i['show']); @endphp
            @if(count($visibleItems) > 0)
            <div>
                <p class="px-3 mb-1.5 text-[10px] font-bold text-white/35 uppercase tracking-[0.16em] sidebar-label">{{ $sectionLabel }}</p>
                <div class="space-y-0.5">
                    @foreach($visibleItems as $item)
                        @php $active = request()->routeIs($item['match']); @endphp
                        <a href="{{ route($item['route']) }}" onclick="closeSidebarOnMobile()" title="{{ $item['title'] ?? $item['label'] }}"
                           class="sidebar-link group relative flex items-center gap-3 px-2 py-1.5 rounded-xl text-[13px] font-semibold transition-all
                                  {{ $active ? 'sidebar-link-active text-white' : 'text-white/60 hover:text-white hover:bg-white/[0.06]' }}">
                            <span class="w-8 h-8 flex items-center justify-center rounded-lg flex-shrink-0 transition-colors
                                         {{ $active ? 'bg-white/20' : 'bg-white/[0.04] group-hover:bg-white/10' }}">
                                @if(!empty($item['whatsapp']))
                                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24">
                                        <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
                                    </svg>
                                @else
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $item['icon'] }}"/>
                                        @if(!empty($item['settings']))
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        @endif
                                    </svg>
                                @endif
                            </span>
                            <span class="sidebar-label truncate">{{ $item['label'] }}</span>
                            @if(!empty($item['chat_badge']))
                                <span id="chat-unread-badge" class="hidden ml-auto min-w-[20px] h-5 px-1.5 bg-red-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center shadow-sm"></span>
                            @endif
                            @if(($item['count'] ?? 0) > 0)
                                <span class="ml-auto min-w-[20px] h-5 px-1.5 flex items-center justify-center rounded-full bg-red-500 text-white text-[10px] font-bold animate-pulse sidebar-label">{{ $item['count'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
            @endif
        @endforeach
    </nav>

    <!-- User -->
    <div class="p-3 flex-shrink-0">
        <div id="userDropdown" class="hidden mb-2 p-1.5 rounded-xl bg-white/[0.06] ring-1 ring-white/10">
            <a href="{{ route('admin.settings.index') }}" class="flex items-center gap-2 px-3 py-2 text-xs font-semibold text-white/70 hover:text-white hover:bg-white/10 rounded-lg transition-colors">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <span class="sidebar-label">Meu Perfil</span>
            </a>
            <form method="POST" action="{{ route('logout') }}" class="mt-0.5">
                @csrf
                <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 text-xs font-semibold text-red-300 hover:text-white hover:bg-red-500/80 rounded-lg transition-colors">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    <span class="sidebar-label">Sair</span>
                </button>
            </form>
        </div>
        <div class="flex items-center gap-3 p-2 rounded-xl bg-white/[0.05] ring-1 ring-white/10 hover:bg-white/10 transition-colors cursor-pointer sidebar-user" onclick="toggleDropdown()">
            <div class="w-9 h-9 bg-gradient-to-br from-emerald-400 to-green-600 rounded-lg flex items-center justify-center flex-shrink-0">
                <span class="text-white text-sm font-bold">{{ strtoupper(substr($authUser->name, 0, 1)) }}</span>
            </div>
            <div class="flex-1 min-w-0 sidebar-label">
                <p class="text-[13px] font-bold text-white truncate leading-tight">{{ $authUser->name }}</p>
                <p class="text-[11px] text-white/45 leading-tight">{{ $isAdmin ? 'Administrador' : 'Gestor' }}</p>
            </div>
            <svg class="w-4 h-4 text-white/40 flex-shrink-0 sidebar-label" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l4-4 4 4m0 6l-4 4-4-4"/>
            </svg>
        </div>
    </div>
</aside>

<!-- Mobile toggle -->
<button onclick="toggleSidebar()" id="menuToggleBtn"
        class="lg:hidden fixed top-3 left-4 z-50 w-10 h-10 bg-[#0C1A13] text-white rounded-xl shadow-lg flex items-center justify-center hover:bg-[#12261b] transition-colors">
    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
    </svg>
</button>

<!-- Overlay mobile -->
<div id="sidebarOverlay" class="lg:hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-30 hidden" onclick="toggleSidebar()"></div>

<style>
    .admin-sidebar {
        background:
            radial-gradient(120% 50% at 0% 0%, rgba(16, 185, 129, 0.16) 0%, transparent 60%),
            linear-gradient(180deg, #0C1A13 0%, #0A140F 100%);
        box-shadow: 1px 0 0 rgba(255,255,255,0.04), 8px 0 30px -12px rgba(0,0,0,0.35);
    }
    .sidebar-link-active {
        background: linear-gradient(135deg, #10B981 0%, #00A335 100%);
        box-shadow: 0 8px 20px -8px rgba(16, 185, 129, 0.7);
    }
    .sidebar-scroll::-webkit-scrollbar { width: 4px; }
    .sidebar-scroll::-webkit-scrollbar-track { background: transparent; }
    .sidebar-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); }
    .sidebar-collapsed { width: 4rem !important; }
    .sidebar-collapsed .sidebar-label { display: none !important; }
    .sidebar-collapsed .sidebar-link { justify-content: center; padding-left: 0; padding-right: 0; }
    .sidebar-collapsed .sidebar-link span:first-child { margin: 0; }
    .sidebar-collapsed .sidebar-user { justify-content: center; }
    .sidebar-collapsed nav > div > p.sidebar-label { display: none !important; }
    .sidebar-collapsed #userDropdown { display: none !important; }
    .sidebar-collapsed #collapseBtn { display: none !important; }
    .sidebar-collapsed > div:first-child { justify-content: center; padding-left: 0; padding-right: 0; }
    .sidebar-collapsed #chat-unread-badge { position: absolute; top: 2px; right: 8px; min-width: 16px; height: 16px; font-size: 9px; }
</style>

<script>
    const SIDEBAR_KEY = 'sidebarCollapsed';

    function collapseSidebar() {
        const sidebar = document.getElementById('sidebar');
        const main = document.querySelector('.lg\\:ml-64, .lg\\:ml-16');
        const expandBtn = document.getElementById('expandBtn');
        const isCollapsed = sidebar.classList.toggle('sidebar-collapsed');
        sidebar.classList.toggle('sidebar-expanded', !isCollapsed);

        if (main) {
            main.classList.toggle('lg:ml-64', !isCollapsed);
            main.classList.toggle('lg:ml-16', isCollapsed);
        }
        if (expandBtn) {
            expandBtn.classList.toggle('hidden', !isCollapsed);
            expandBtn.classList.toggle('lg:flex', isCollapsed);
        }
        localStorage.setItem(SIDEBAR_KEY, isCollapsed ? '1' : '0');
    }

    // Restore state on load
    (function() {
        if (localStorage.getItem(SIDEBAR_KEY) === '1') {
            const sidebar = document.getElementById('sidebar');
            const main = document.querySelector('.lg\\:ml-64');
            const expandBtn = document.getElementById('expandBtn');
            if (sidebar) {
                sidebar.classList.add('sidebar-collapsed');
                sidebar.classList.remove('sidebar-expanded');
            }
            if (main) {
                main.classList.remove('lg:ml-64');
                main.classList.add('lg:ml-16');
            }
            if (expandBtn) {
                expandBtn.classList.remove('hidden');
                expandBtn.classList.add('lg:flex');
            }
        }
    })();

    function toggleDropdown() {
        document.getElementById('userDropdown')?.classList.toggle('hidden');
    }
    function toggleSidebar() {
        document.getElementById('sidebar')?.classList.toggle('-translate-x-full');
        document.getElementById('sidebarOverlay')?.classList.toggle('hidden');
    }
    function closeSidebarOnMobile() {
        if (window.innerWidth < 1024) {
            document.getElementById('sidebar')?.classList.add('-translate-x-full');
            document.getElementById('sidebarOverlay')?.classList.add('hidden');
        }
    }
    document.addEventListener('click', function(e) {
        const dropdown = document.getElementById('userDropdown');
        if (dropdown && !e.target.closest('[onclick="toggleDropdown()"]') && !dropdown.contains(e.target)) {
            dropdown.classList.add('hidden');
        }
    });
    @if(Auth::user()->hasModule('chat') && Auth::user()->role === 'admin')
    function checkUnreadMessages() {
        fetch('{{ route("admin.chat.unread") }}')
            .then(r => r.json())
            .then(data => {
                const badge = document.getElementById('chat-unread-badge');
                if (!badge) return;
                if (data.count > 0) {
                    badge.textContent = data.count > 99 ? '99+' : data.count;
                    badge.classList.remove('hidden');
                } else { badge.classList.add('hidden'); }
            }).catch(() => {});
    }
    setInterval(checkUnreadMessages, 30000);
    checkUnreadMessages();
    @endif
</script>
