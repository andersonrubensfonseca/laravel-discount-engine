<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Descontos')</title>

    {{--
        Tailwind e Alpine via CDN de proposito: o pacote precisa funcionar
        em Laravel 8 e 13 sem exigir build de assets do app hospedeiro.
        Se o seu projeto ja compila Tailwind, publique as views e troque.
    --}}
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</head>
<body class="bg-slate-100 text-slate-800">
<div class="max-w-6xl mx-auto px-4 py-8">

    <header class="flex items-center justify-between mb-6">
        <a href="{{ route('discount-engine.rules.index') }}" class="text-xl font-semibold text-slate-900">
            Regras de desconto
        </a>
        @yield('actions')
    </header>

    @if ($insecure ?? false)
        <div class="mb-4 rounded border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800">
            <strong>Painel sem autenticacao.</strong>
            O middleware configurado e apenas <code>web</code>. Qualquer pessoa que
            alcance esta URL pode reescrever as regras de preco da loja. Ajuste
            <code>config/discount-engine.php</code> antes de subir para producao.
        </div>
    @endif

    @if (session('status'))
        <div class="mb-4 rounded border-l-4 border-emerald-500 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @foreach ((array) session('warnings', []) as $warning)
        <div class="mb-4 rounded border-l-4 border-amber-500 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            {{ $warning }}
        </div>
    @endforeach

    @if ($errors->any())
        <div class="mb-4 rounded border-l-4 border-red-500 bg-red-50 px-4 py-3 text-sm text-red-800">
            <p class="font-semibold mb-1">Corrija os pontos abaixo:</p>
            <ul class="list-disc list-inside space-y-1">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @yield('content')
</div>
</body>
</html>
