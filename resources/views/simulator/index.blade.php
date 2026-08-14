@extends('discount-engine::layout')

@section('title', 'Simulador')

@section('actions')
    <a href="{{ route('discount-engine.rules.index') }}" class="text-sm text-slate-600 hover:underline">voltar</a>
@endsection

@section('content')
<div x-data="simulator()" class="grid gap-6 lg:grid-cols-2">

    {{-- Carrinho --}}
    <section class="rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Carrinho de teste</h2>

        <template x-for="(item, i) in cart.items" :key="i">
            <div class="mb-4 rounded border border-slate-200 p-3">
                <div class="mb-2 flex items-center justify-between">
                    <strong class="text-sm">Item <span x-text="i + 1"></span></strong>
                    <button type="button" @click="cart.items.splice(i, 1)"
                            class="text-xs text-red-600 hover:underline">remover</button>
                </div>

                <div class="grid grid-cols-3 gap-2">
                    <label class="col-span-2 block">
                        <span class="text-xs text-slate-500">SKU</span>
                        <input type="text" x-model="item.sku" class="mt-1 w-full rounded border-slate-300 text-sm">
                    </label>
                    <label class="block">
                        <span class="text-xs text-slate-500">Qtd</span>
                        <input type="number" min="1" x-model.number="item.quantity"
                               class="mt-1 w-full rounded border-slate-300 text-sm">
                    </label>
                </div>

                <label class="mt-2 block">
                    <span class="text-xs text-slate-500">Categorias (IDs, separados por virgula)</span>
                    <input type="text" x-model="item.categories" class="mt-1 w-full rounded border-slate-300 text-sm">
                </label>

                <div class="mt-3">
                    <div class="mb-1 flex items-center justify-between">
                        <span class="text-xs font-medium text-slate-600">Componentes de preco</span>
                        <button type="button" @click="addComponent(item)"
                                class="text-xs text-slate-600 hover:underline">+ componente</button>
                    </div>

                    <template x-for="(c, ci) in item.components" :key="ci">
                        <div class="mb-1 grid grid-cols-12 gap-1">
                            <input type="text" x-model="c.type" placeholder="base"
                                   class="col-span-4 rounded border-slate-300 text-xs">
                            <input type="number" x-model.number="c.unit_price_cents" placeholder="centavos"
                                   class="col-span-4 rounded border-slate-300 text-xs">
                            <input type="number" min="1" x-model.number="c.quantity"
                                   class="col-span-3 rounded border-slate-300 text-xs">
                            <button type="button" @click="item.components.splice(ci, 1)"
                                    class="col-span-1 text-xs text-red-600">x</button>
                        </div>
                    </template>
                    <p class="mt-1 text-xs text-slate-400">tipo · preco unitario em centavos · quantidade por unidade do item</p>
                </div>
            </div>
        </template>

        <button type="button" @click="addItem()"
                class="mb-4 w-full rounded border border-dashed border-slate-300 py-2 text-sm text-slate-600 hover:bg-slate-50">
            + adicionar item
        </button>

        <div class="grid grid-cols-2 gap-3">
            <label class="block">
                <span class="text-xs text-slate-500">Frete (centavos)</span>
                <input type="number" x-model.number="cart.shipping_cents" class="mt-1 w-full rounded border-slate-300 text-sm">
            </label>
            <label class="block">
                <span class="text-xs text-slate-500">Cupons (virgula)</span>
                <input type="text" x-model="cart.couponsRaw" class="mt-1 w-full rounded border-slate-300 text-sm">
            </label>
        </div>

        <fieldset class="mt-4 rounded border border-slate-200 p-3">
            <legend class="px-1 text-xs text-slate-500">Cliente</legend>
            <label class="mb-2 flex items-center gap-2">
                <input type="checkbox" x-model="cart.identified" class="rounded border-slate-300">
                <span class="text-sm">Cliente identificado</span>
            </label>
            <div x-show="cart.identified" x-cloak class="grid grid-cols-2 gap-2">
                <label class="block">
                    <span class="text-xs text-slate-500">Pedidos concluidos</span>
                    <input type="number" min="0" x-model.number="cart.customer.completed_orders"
                           class="mt-1 w-full rounded border-slate-300 text-sm">
                </label>
                <label class="block">
                    <span class="text-xs text-slate-500">Grupos (virgula)</span>
                    <input type="text" x-model="cart.customer.groupsRaw"
                           class="mt-1 w-full rounded border-slate-300 text-sm">
                </label>
            </div>
        </fieldset>

        <button type="button" @click="run()" :disabled="loading"
                class="mt-5 w-full rounded bg-slate-900 py-2.5 text-sm font-medium text-white hover:bg-slate-700 disabled:opacity-50">
            <span x-show="!loading">Simular</span>
            <span x-show="loading" x-cloak>Calculando...</span>
        </button>
    </section>

    {{-- Resultado --}}
    <section class="rounded bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Resultado</h2>

        <p x-show="!result && !error" class="text-sm text-slate-400">
            Monte um carrinho e clique em Simular.
        </p>

        <div x-show="error" x-cloak class="rounded bg-red-50 p-3 text-sm text-red-800" x-text="error"></div>

        <template x-if="result">
            <div>
                <dl class="mb-5 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-slate-500">Subtotal</dt>
                    <dd class="text-right" x-text="brl(result.subtotal_cents)"></dd>
                    <dt class="text-slate-500">Frete</dt>
                    <dd class="text-right" x-text="brl(result.shipping_cents)"></dd>
                    <dt class="text-slate-500">Desconto em itens</dt>
                    <dd class="text-right text-emerald-700" x-text="'- ' + brl(result.items_discount_cents)"></dd>
                    <dt class="text-slate-500">Desconto no frete</dt>
                    <dd class="text-right text-emerald-700" x-text="'- ' + brl(result.shipping_discount_cents)"></dd>
                    <dt class="border-t pt-2 font-semibold">Total</dt>
                    <dd class="border-t pt-2 text-right font-semibold" x-text="brl(result.final_total_cents)"></dd>
                </dl>

                <h3 class="mb-2 text-xs font-semibold uppercase text-slate-500">Regras aplicadas</h3>
                <p x-show="result.applied.length === 0" class="mb-4 text-sm text-slate-400">Nenhuma.</p>
                <ul class="mb-5 space-y-1">
                    <template x-for="(a, i) in result.applied" :key="i">
                        <li class="flex justify-between rounded bg-emerald-50 px-3 py-2 text-sm">
                            <span>
                                <span x-text="a.rule_name" class="font-medium"></span>
                                <span class="text-xs text-slate-500" x-text="'· ' + a.action_type + ' · ' + a.target"></span>
                                <template x-if="a.coupon_code">
                                    <span class="ml-1 rounded bg-slate-200 px-1 text-xs" x-text="a.coupon_code"></span>
                                </template>
                            </span>
                            <span class="text-emerald-700" x-text="brl(a.amount_cents)"></span>
                        </li>
                    </template>
                </ul>

                <h3 class="mb-2 text-xs font-semibold uppercase text-slate-500">
                    Regras que nao entraram
                </h3>
                <p x-show="result.rejected.length === 0" class="mb-4 text-sm text-slate-400">Nenhuma.</p>
                <ul class="mb-5 space-y-1">
                    <template x-for="(r, i) in result.rejected" :key="i">
                        <li class="rounded bg-slate-50 px-3 py-2 text-sm">
                            <span x-text="r.rule_name" class="font-medium"></span>
                            <p class="text-xs text-slate-500" x-text="r.message"></p>
                        </li>
                    </template>
                </ul>

                <template x-if="Object.keys(result.by_component_type).length">
                    <div>
                        <h3 class="mb-2 text-xs font-semibold uppercase text-slate-500">Por tipo de componente</h3>
                        <ul class="space-y-1">
                            <template x-for="(cents, tipo) in result.by_component_type" :key="tipo">
                                <li class="flex justify-between rounded bg-slate-50 px-3 py-1.5 text-sm">
                                    <span x-text="tipo"></span>
                                    <span x-text="brl(cents)"></span>
                                </li>
                            </template>
                        </ul>
                    </div>
                </template>
            </div>
        </template>
    </section>
</div>

<style>[x-cloak]{display:none!important}</style>

<script>
function simulator() {
    return {
        loading: false,
        error: null,
        result: null,
        cart: {
            items: [{
                sku: 'CAMISA-P',
                quantity: 1,
                categories: '',
                components: [
                    { type: 'base', unit_price_cents: 4000, quantity: 1 },
                    { type: 'print', unit_price_cents: 1500, quantity: 1 },
                ],
            }],
            shipping_cents: 0,
            couponsRaw: '',
            identified: false,
            customer: { completed_orders: 0, groupsRaw: '' },
        },

        addItem() {
            this.cart.items.push({
                sku: 'SKU',
                quantity: 1,
                categories: '',
                components: [{ type: 'base', unit_price_cents: 1000, quantity: 1 }],
            });
        },

        addComponent(item) {
            item.components.push({ type: 'print', unit_price_cents: 1500, quantity: 1 });
        },

        brl(cents) {
            return (cents / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
        },

        list(raw) {
            return (raw || '').split(',').map(s => s.trim()).filter(Boolean);
        },

        payload() {
            return {
                shipping_cents: this.cart.shipping_cents || 0,
                coupons: this.list(this.cart.couponsRaw),
                customer: this.cart.identified ? {
                    id: 1,
                    completed_orders: this.cart.customer.completed_orders || 0,
                    groups: this.list(this.cart.customer.groupsRaw),
                } : null,
                items: this.cart.items.map((item, index) => ({
                    id: 'item-' + (index + 1),
                    sku: item.sku,
                    quantity: item.quantity || 1,
                    category_ids: this.list(item.categories),
                    components: item.components,
                })),
            };
        },

        async run() {
            this.loading = true;
            this.error = null;

            try {
                const response = await fetch('{{ route('discount-engine.simulator.run') }}', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                    body: JSON.stringify(this.payload()),
                });

                const data = await response.json();

                if (!response.ok) {
                    this.error = data.error || 'Nao foi possivel simular.';
                    this.result = null;
                } else {
                    this.result = data;
                }
            } catch (e) {
                this.error = 'Falha na comunicacao: ' + e.message;
            } finally {
                this.loading = false;
            }
        },
    };
}
</script>
@endsection
