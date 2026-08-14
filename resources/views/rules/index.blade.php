@extends('discount-engine::layout')

@section('title', 'Regras de desconto')

@section('actions')
    <a href="{{ route('discount-engine.rules.create') }}"
       class="rounded bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
        Nova regra
    </a>
@endsection

@section('content')
    <div class="overflow-hidden rounded bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
            <tr>
                <th class="px-4 py-3">Prio</th>
                <th class="px-4 py-3">Regra</th>
                <th class="px-4 py-3">Disparo</th>
                <th class="px-4 py-3">Acumulo</th>
                <th class="px-4 py-3">Vigencia</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3"></th>
            </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
            @forelse ($rules as $rule)
                <tr class="{{ $rule->active ? '' : 'opacity-50' }}">
                    <td class="px-4 py-3 font-mono text-slate-500">{{ $rule->priority }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('discount-engine.rules.edit', $rule) }}"
                           class="font-medium text-slate-900 hover:underline">{{ $rule->name }}</a>
                        @if ($rule->description)
                            <p class="text-xs text-slate-500">{{ $rule->description }}</p>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        {{ $rule->trigger === 'coupon' ? 'Cupom' : 'Automatico' }}
                        @if ($rule->trigger === 'coupon')
                            <span class="text-xs text-slate-500">({{ $rule->coupons_count }} codigo(s))</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs">
                        @if ($rule->combination_mode === 'exclusive')
                            <span class="rounded bg-purple-100 px-2 py-0.5 text-purple-800">Exclusivo</span>
                        @elseif ($rule->resolution_group)
                            <span class="rounded bg-sky-100 px-2 py-0.5 text-sky-800">
                                {{ $rule->resolution_group }}
                                @if ($rule->resolution_strategy === 'highest_discount') · melhor @endif
                            </span>
                        @else
                            <span class="text-slate-400">Acumula</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-slate-500">
                        {{ optional($rule->valid_from)->format('d/m/y') ?: '—' }}
                        ate
                        {{ optional($rule->valid_until)->format('d/m/y') ?: '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('discount-engine.rules.toggle', $rule) }}">
                            @csrf
                            <button type="submit"
                                    class="rounded px-2 py-0.5 text-xs {{ $rule->active ? 'bg-emerald-100 text-emerald-800' : 'bg-slate-200 text-slate-600' }}">
                                {{ $rule->active ? 'Ativa' : 'Inativa' }}
                            </button>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-right">
                        <form method="POST" action="{{ route('discount-engine.rules.destroy', $rule) }}"
                              onsubmit="return confirm('Apagar esta regra?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="text-xs text-red-600 hover:underline">apagar</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="px-4 py-10 text-center text-slate-500">
                        Nenhuma regra cadastrada ainda.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $rules->links() }}</div>
@endsection
