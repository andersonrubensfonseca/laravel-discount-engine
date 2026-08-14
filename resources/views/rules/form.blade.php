@extends('discount-engine::layout')

@section('title', $rule->exists ? 'Editar regra' : 'Nova regra')

@section('actions')
    <div class="flex items-center gap-4">
        <a href="{{ route('discount-engine.simulator.index') }}" class="text-sm text-slate-600 hover:underline">simulador</a>
        <a href="{{ route('discount-engine.rules.index') }}" class="text-sm text-slate-600 hover:underline">voltar</a>
    </div>
@endsection

@section('content')
<form method="POST"
      action="{{ $rule->exists ? route('discount-engine.rules.update', $rule) : route('discount-engine.rules.store') }}"
      x-data="ruleBuilder(@js($conditionsData), @js($actionsData), @js($fieldMap))"
      @submit="sync()">
    @csrf
    @if ($rule->exists) @method('PUT') @endif

    <input type="hidden" name="conditions_json" x-ref="conditionsJson">
    <input type="hidden" name="actions_json" x-ref="actionsJson">

    <div class="grid gap-6 lg:grid-cols-3">

        <section class="lg:col-span-3 rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Identificacao</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block">
                    <span class="text-sm font-medium">Nome</span>
                    <input type="text" name="name" value="{{ old('name', $rule->name) }}" required
                           class="mt-1 w-full rounded border-slate-300 shadow-sm">
                </label>
                <label class="block">
                    <span class="text-sm font-medium">Descricao</span>
                    <input type="text" name="description" value="{{ old('description', $rule->description) }}"
                           class="mt-1 w-full rounded border-slate-300 shadow-sm">
                </label>
            </div>
        </section>

        {{-- Disparo --}}
        <section class="rounded bg-white p-5 shadow-sm" x-data="{ trigger: '{{ old('trigger', $rule->trigger ?: 'automatic') }}' }">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Disparo</h2>

            <label class="block mb-4">
                <span class="text-sm font-medium">Como entra</span>
                <select name="trigger" x-model="trigger" class="mt-1 w-full rounded border-slate-300 shadow-sm">
                    <option value="automatic" {{ old('trigger', $rule->trigger) === 'automatic' ? 'selected' : '' }}>Automatico</option>
                    <option value="coupon" {{ old('trigger', $rule->trigger) === 'coupon' ? 'selected' : '' }}>Por cupom</option>
                </select>
            </label>

            <div x-show="trigger === 'coupon'" x-cloak class="space-y-4">
                <label class="block">
                    <span class="text-sm font-medium">Codigos</span>
                    <textarea name="coupon_codes" rows="4"
                              class="mt-1 w-full rounded border-slate-300 font-mono text-sm shadow-sm"
                              placeholder="BEMVINDO">{{ old('coupon_codes', $couponCodes) }}</textarea>
                    <span class="text-xs text-slate-500">Um por linha.</span>
                </label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="text-sm font-medium">Limite total</span>
                        <input type="number" name="usage_limit" min="1"
                               value="{{ old('usage_limit', optional($firstCoupon)->usage_limit) }}"
                               class="mt-1 w-full rounded border-slate-300 shadow-sm">
                    </label>
                    <label class="block">
                        <span class="text-sm font-medium">Por cliente</span>
                        <input type="number" name="usage_limit_per_customer" min="1"
                               value="{{ old('usage_limit_per_customer', optional($firstCoupon)->usage_limit_per_customer) }}"
                               class="mt-1 w-full rounded border-slate-300 shadow-sm">
                    </label>
                </div>
                <label class="block">
                    <span class="text-sm font-medium">Expira em</span>
                    <input type="date" name="expires_at"
                           value="{{ old('expires_at', optional(optional($firstCoupon)->expires_at)->format('Y-m-d')) }}"
                           class="mt-1 w-full rounded border-slate-300 shadow-sm">
                </label>
            </div>
        </section>

        {{-- Acumulo --}}
        <section class="rounded bg-white p-5 shadow-sm"
                 x-data="{ grupo: '{{ old('resolution_group', $rule->resolution_group) }}',
                           estrategia: '{{ old('resolution_strategy', $rule->resolution_strategy ?: 'first_by_priority') }}' }">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Acumulo</h2>

            <label class="block mb-3">
                <span class="text-sm font-medium">Prioridade</span>
                <input type="number" name="priority" min="0" value="{{ old('priority', $rule->priority ?? 100) }}"
                       class="mt-1 w-full rounded border-slate-300 shadow-sm">
                <span class="text-xs text-slate-500">Menor numero aplica primeiro.</span>
            </label>

            <label class="block mb-3">
                <span class="text-sm font-medium">Modo</span>
                <select name="combination_mode" class="mt-1 w-full rounded border-slate-300 shadow-sm">
                    <option value="stackable" {{ old('combination_mode', $rule->combination_mode) === 'stackable' ? 'selected' : '' }}>Acumulavel</option>
                    <option value="exclusive" {{ old('combination_mode', $rule->combination_mode) === 'exclusive' ? 'selected' : '' }}>Exclusivo</option>
                </select>
                <span class="text-xs text-slate-500">Exclusivo descarta todos os outros descontos do pedido.</span>
            </label>

            <label class="block mb-3">
                <span class="text-sm font-medium">Grupo</span>
                <input type="text" name="resolution_group" x-model="grupo" placeholder="promocoes"
                       class="mt-1 w-full rounded border-slate-300 shadow-sm">
                <span class="text-xs text-slate-500">Vazio = acumula com todas.</span>
            </label>

            <label class="block mb-3">
                <span class="text-sm font-medium">Quem vence no grupo</span>
                <select name="resolution_strategy" x-model="estrategia" class="mt-1 w-full rounded border-slate-300 shadow-sm">
                    <option value="first_by_priority">A primeira por prioridade</option>
                    <option value="highest_discount">A que der maior desconto</option>
                </select>
            </label>

            <p x-show="grupo && estrategia === 'first_by_priority'" x-cloak
               class="mb-3 rounded bg-amber-50 p-2 text-xs text-amber-800">
                Esta estrategia ignora o valor do desconto. Uma regra de 5% com prioridade
                menor vence uma de 20% do mesmo grupo. Se a intencao e dar o melhor desconto
                ao cliente, escolha a outra opcao.
            </p>

            <label class="mb-3 flex items-center gap-2">
                <input type="hidden" name="stop_further_processing" value="0">
                <input type="checkbox" name="stop_further_processing" value="1"
                       {{ old('stop_further_processing', $rule->stop_further_processing) ? 'checked' : '' }}
                       class="rounded border-slate-300">
                <span class="text-sm">Encerrar o pipeline apos aplicar</span>
            </label>

            <label class="block">
                <span class="text-sm font-medium">Base de calculo</span>
                <select name="calculation_base" class="mt-1 w-full rounded border-slate-300 shadow-sm">
                    <option value="current" {{ old('calculation_base', $rule->calculation_base) === 'current' ? 'selected' : '' }}>
                        Subtotal ja descontado (10%+10% = 19%)
                    </option>
                    <option value="original" {{ old('calculation_base', $rule->calculation_base) === 'original' ? 'selected' : '' }}>
                        Subtotal original (10%+10% = 20%)
                    </option>
                </select>
            </label>
        </section>

        {{-- Vigencia --}}
        <section class="rounded bg-white p-5 shadow-sm">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-slate-500">Vigencia</h2>
            <label class="block mb-3">
                <span class="text-sm font-medium">Inicio</span>
                <input type="datetime-local" name="valid_from"
                       value="{{ old('valid_from', optional($rule->valid_from)->format('Y-m-d\TH:i')) }}"
                       class="mt-1 w-full rounded border-slate-300 shadow-sm">
            </label>
            <label class="block mb-4">
                <span class="text-sm font-medium">Fim</span>
                <input type="datetime-local" name="valid_until"
                       value="{{ old('valid_until', optional($rule->valid_until)->format('Y-m-d\TH:i')) }}"
                       class="mt-1 w-full rounded border-slate-300 shadow-sm">
            </label>
            <label class="flex items-center gap-2">
                <input type="hidden" name="active" value="0">
                <input type="checkbox" name="active" value="1"
                       {{ old('active', $rule->exists ? $rule->active : true) ? 'checked' : '' }}
                       class="rounded border-slate-300">
                <span class="text-sm">Regra ativa</span>
            </label>
        </section>

        {{-- CONDICOES --}}
        <section class="lg:col-span-3 rounded bg-white p-5 shadow-sm">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-slate-500">Condicoes</h2>
                <button type="button" @click="advanced = !advanced" class="text-xs text-slate-500 hover:underline">
                    <span x-show="!advanced">editar JSON</span>
                    <span x-show="advanced" x-cloak>voltar ao formulario</span>
                </button>
            </div>

            <div x-show="!advanced">
                <p class="mb-3 text-xs text-slate-500">Sem condicoes, a regra vale sempre.</p>

                <div class="mb-3 flex items-center gap-2">
                    <span class="text-sm">Atender</span>
                    <select x-model="conditions.logic" class="rounded border-slate-300 text-sm">
                        <option value="and">todas as condicoes</option>
                        <option value="or">qualquer condicao</option>
                    </select>
                </div>

                <template x-for="(child, i) in conditions.children" :key="i">
                    <div class="mb-2">
                        {{-- subgrupo --}}
                        <template x-if="child.logic">
                            <div class="rounded border-l-4 border-sky-300 bg-sky-50 p-3">
                                <div class="mb-2 flex items-center justify-between">
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-600">Subgrupo: atender</span>
                                        <select x-model="child.logic" class="rounded border-slate-300 text-xs">
                                            <option value="and">todas</option>
                                            <option value="or">qualquer</option>
                                        </select>
                                    </div>
                                    <button type="button" @click="conditions.children.splice(i,1)"
                                            class="text-xs text-red-600">remover subgrupo</button>
                                </div>
                                <template x-for="(sub, si) in child.children" :key="si">
                                    <div class="mb-1 grid grid-cols-12 gap-2">
                                        <select x-model="sub.type" @change="onTypeChange(sub)" class="col-span-4 rounded border-slate-300 text-sm">
                                            <template x-for="(meta, key) in fields.conditions" :key="key">
                                                <option :value="key" x-text="meta.label"></option>
                                            </template>
                                        </select>
                                        <select x-model="sub.operator" class="col-span-3 rounded border-slate-300 text-sm">
                                            <template x-for="op in operatorsFor(sub.type)" :key="op">
                                                <option :value="op" x-text="operators[op]"></option>
                                            </template>
                                        </select>
                                        <template x-if="valueKind(sub.type) === 'bool'">
                                            <select x-model="sub.value" class="col-span-4 rounded border-slate-300 text-sm">
                                                <option value="true">Sim</option>
                                                <option value="false">Nao</option>
                                            </select>
                                        </template>
                                        <template x-if="valueKind(sub.type) !== 'bool'">
                                            <input type="text" x-model="sub.value"
                                                   :placeholder="placeholderFor(sub.type)"
                                                   class="col-span-4 rounded border-slate-300 text-sm">
                                        </template>
                                        <button type="button" @click="child.children.splice(si,1)"
                                                class="col-span-1 text-xs text-red-600">x</button>
                                    </div>
                                </template>
                                <button type="button" @click="child.children.push(newCondition())"
                                        class="mt-1 text-xs text-sky-700 hover:underline">+ condicao no subgrupo</button>
                            </div>
                        </template>

                        {{-- condicao simples --}}
                        <template x-if="!child.logic">
                            <div>
                                <div class="grid grid-cols-12 gap-2">
                                    <select x-model="child.type" @change="onTypeChange(child)" class="col-span-4 rounded border-slate-300 text-sm">
                                        <template x-for="(meta, key) in fields.conditions" :key="key">
                                            <option :value="key" x-text="meta.label"></option>
                                        </template>
                                    </select>
                                    <select x-model="child.operator" class="col-span-3 rounded border-slate-300 text-sm">
                                        <template x-for="op in operatorsFor(child.type)" :key="op">
                                            <option :value="op" x-text="operators[op]"></option>
                                        </template>
                                    </select>
                                    <template x-if="valueKind(child.type) === 'bool'">
                                        <select x-model="child.value" class="col-span-4 rounded border-slate-300 text-sm">
                                            <option value="true">Sim</option>
                                            <option value="false">Nao</option>
                                        </select>
                                    </template>
                                    <template x-if="valueKind(child.type) !== 'bool'">
                                        <input type="text" x-model="child.value"
                                               :placeholder="placeholderFor(child.type)"
                                               class="col-span-4 rounded border-slate-300 text-sm">
                                    </template>
                                    <button type="button" @click="conditions.children.splice(i,1)"
                                            class="col-span-1 text-xs text-red-600">x</button>
                                </div>
                                <template x-for="(m, mk) in (fields.conditions[child.type] || {}).meta || {}" :key="mk">
                                    <div class="mt-1 grid grid-cols-12 gap-2">
                                        <span class="col-span-4 text-right text-xs text-slate-500 pt-2" x-text="m.label"></span>
                                        <input type="text" class="col-span-7 rounded border-slate-300 text-sm"
                                               :value="child.meta[mk] || ''"
                                               @input="child.meta[mk] = $event.target.value">
                                    </div>
                                </template>
                                <p class="mt-1 text-xs text-slate-400"
                                   x-text="(fields.conditions[child.type] || {}).hint || ''"></p>
                            </div>
                        </template>
                    </div>
                </template>

                <div class="mt-3 flex gap-3">
                    <button type="button" @click="conditions.children.push(newCondition())"
                            class="rounded border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                        + condicao
                    </button>
                    <button type="button" @click="conditions.children.push({logic:'or', children:[newCondition()]})"
                            class="rounded border border-dashed border-sky-300 px-3 py-1.5 text-sm text-sky-700 hover:bg-sky-50">
                        + subgrupo
                    </button>
                </div>
            </div>

            <div x-show="advanced" x-cloak>
                <textarea rows="10" class="w-full rounded border-slate-300 font-mono text-xs"
                          x-model="rawConditions"
                          @change="parseRawConditions()"></textarea>
                <p class="mt-1 text-xs text-slate-500">Ao voltar ao formulario, o JSON acima e reinterpretado.</p>
            </div>
        </section>

        {{-- ACOES --}}
        <section class="lg:col-span-3 rounded bg-white p-5 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Acoes</h2>
            <p class="mb-3 text-xs text-slate-500">Valores monetarios sempre em centavos.</p>

            <template x-for="(action, ai) in actions" :key="ai">
                <div class="mb-3 rounded border border-slate-200 p-3">
                    <div class="mb-2 grid grid-cols-12 gap-2">
                        <select x-model="action.type" class="col-span-4 rounded border-slate-300 text-sm">
                            <template x-for="(meta, key) in fields.actions" :key="key">
                                <option :value="key" x-text="meta.label"></option>
                            </template>
                        </select>
                        <select x-model="action.target" class="col-span-3 rounded border-slate-300 text-sm">
                            <option value="cart">Total do carrinho</option>
                            <option value="items">Itens</option>
                            <option value="components">Componentes</option>
                            <option value="shipping">Frete</option>
                        </select>
                        <input type="number" step="0.01" x-model.number="action.value"
                               x-show="(fields.actions[action.type] || {}).value !== 'none'"
                               placeholder="valor" class="col-span-2 rounded border-slate-300 text-sm">
                        <input type="number" x-model.number="action.max_discount_cents"
                               x-show="(fields.actions[action.type] || {}).max"
                               placeholder="teto" class="col-span-2 rounded border-slate-300 text-sm">
                        <button type="button" @click="actions.splice(ai,1)"
                                class="col-span-1 text-xs text-red-600">x</button>
                    </div>

                    <div x-show="action.target === 'components'" x-cloak class="mb-2">
                        <label class="block">
                            <span class="text-xs text-slate-500">Tipos de componente (virgula) — ex.: print</span>
                            <input type="text" x-model="action._raw.component_types"
                                   class="mt-1 w-full rounded border-slate-300 text-sm">
                        </label>
                    </div>

                    {{-- Recorte por item: quais produtos participam --}}
                    <div x-show="action.target !== 'shipping'" x-cloak class="mb-2 grid grid-cols-2 gap-2">
                        <label class="block">
                            <span class="text-xs text-slate-500">So nas categorias (IDs, virgula)</span>
                            <input type="text" placeholder="vazio = todas" x-model="action._raw.category_ids"
                                   class="mt-1 w-full rounded border-slate-300 text-sm">
                        </label>
                        <label class="block">
                            <span class="text-xs text-slate-500">So nestes SKUs (virgula)</span>
                            <input type="text" placeholder="vazio = todos" x-model="action._raw.skus"
                                   class="mt-1 w-full rounded border-slate-300 text-sm">
                        </label>
                    </div>

                    <template x-for="(m, mk) in (fields.actions[action.type] || {}).meta || {}" :key="mk">
                        <div class="mb-2 grid grid-cols-12 items-center gap-2">
                            <span class="col-span-4 text-right text-xs text-slate-500" x-text="m.label"></span>
                            <template x-if="m.type === 'select'">
                                <select class="col-span-8 rounded border-slate-300 text-sm"
                                        :value="action.meta[mk] || m.default"
                                        @change="action.meta[mk] = $event.target.value">
                                    <template x-for="(lbl, val) in m.options" :key="val">
                                        <option :value="val" x-text="lbl"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="m.type !== 'select'">
                                <input :type="m.type" class="col-span-8 rounded border-slate-300 text-sm"
                                       :value="action.meta[mk] !== undefined ? action.meta[mk] : m.default"
                                       @input="action.meta[mk] = m.type === 'number' ? Number($event.target.value) : $event.target.value">
                            </template>
                        </div>
                    </template>

                    {{-- faixas do escalonado --}}
                    <div x-show="(fields.actions[action.type] || {}).tiers" x-cloak class="mt-2 rounded bg-slate-50 p-2">
                        <p class="mb-1 text-xs font-medium text-slate-600">Faixas</p>
                        <template x-for="(tier, ti) in (action.meta.tiers || [])" :key="ti">
                            <div class="mb-1 grid grid-cols-12 gap-2">
                                <input type="number" x-model.number="tier.min" placeholder="a partir de"
                                       class="col-span-5 rounded border-slate-300 text-sm">
                                <input type="number" step="0.01" x-model.number="tier.percent" placeholder="%"
                                       class="col-span-5 rounded border-slate-300 text-sm">
                                <button type="button" @click="action.meta.tiers.splice(ti,1)"
                                        class="col-span-2 text-xs text-red-600">remover</button>
                            </div>
                        </template>
                        <button type="button" @click="addTier(action)" class="text-xs text-slate-600 hover:underline">
                            + faixa
                        </button>
                    </div>

                    <p class="mt-1 text-xs text-slate-400" x-text="(fields.actions[action.type] || {}).hint || ''"></p>
                </div>
            </template>

            <button type="button" @click="actions.push(newAction())"
                    class="rounded border border-dashed border-slate-300 px-3 py-1.5 text-sm text-slate-600 hover:bg-slate-50">
                + acao
            </button>
        </section>
    </div>

    <div class="mt-6 flex justify-end gap-3">
        <a href="{{ route('discount-engine.rules.index') }}" class="px-4 py-2 text-sm text-slate-600">Cancelar</a>
        <button type="submit" class="rounded bg-slate-900 px-5 py-2 text-sm font-medium text-white hover:bg-slate-700">
            {{ $rule->exists ? 'Salvar' : 'Criar regra' }}
        </button>
    </div>
</form>

<style>[x-cloak]{display:none!important}</style>

<script>
function ruleBuilder(initialConditions, initialActions, fieldMap) {
    return {
        fields: fieldMap,
        advanced: false,
        conditions: initialConditions,
        actions: initialActions.map(a => {
            a.meta = a.meta || {};
            a._raw = {
                component_types: (a.meta.component_types || []).join(', '),
                category_ids: (a.meta.category_ids || []).join(', '),
                skus: (a.meta.skus || []).join(', '),
            };
            return a;
        }),
        rawConditions: JSON.stringify(initialConditions, null, 2),
        operators: {
            gte: 'maior ou igual a', gt: 'maior que',
            lte: 'menor ou igual a', lt: 'menor que',
            eq: 'igual a', neq: 'diferente de',
            in: 'esta entre', not_in: 'nao esta entre',
            contains_any: 'contem algum de', contains_none: 'nao contem nenhum de',
        },

        valueKind(type) {
            return (this.fields.conditions[type] || {}).value || 'raw';
        },

        /**
         * Operador padrao e lista de opcoes dependem do tipo.
         *
         * Booleano com "maior ou igual a" nao significa nada, e foi
         * exatamente isso que o construtor oferecia antes — gerando regra
         * que parecia certa no formulario e fazia o oposto no carrinho.
         */
        operatorsFor(type) {
            const kind = this.valueKind(type);

            if (kind === 'bool') return ['eq', 'neq'];
            if (kind === 'list') return ['contains_any', 'contains_none', 'in', 'not_in'];
            if (kind === 'cents' || kind === 'int') return ['gte', 'gt', 'lte', 'lt', 'eq', 'neq'];

            return Object.keys(this.operators);
        },

        defaultsFor(type) {
            const kind = this.valueKind(type);

            if (kind === 'bool') return { operator: 'eq', value: 'true' };
            if (kind === 'list') return { operator: 'contains_any', value: '' };

            return { operator: 'gte', value: '' };
        },

        onTypeChange(condition) {
            const defaults = this.defaultsFor(condition.type);

            // Operador que nao existe para o novo tipo viraria valor invisivel
            // no select — e o navegador enviaria o primeiro da lista sem avisar.
            if (!this.operatorsFor(condition.type).includes(condition.operator)) {
                condition.operator = defaults.operator;
            }

            if (this.valueKind(condition.type) === 'bool'
                && condition.value !== 'true' && condition.value !== 'false') {
                condition.value = defaults.value;
            }

            condition.meta = {};
        },

        placeholderFor(type) {
            const kind = this.valueKind(type);

            if (kind === 'cents') return 'centavos — R$ 200,00 = 20000';
            if (kind === 'int') return 'numero inteiro';
            if (kind === 'list') return 'separe por virgula';

            return 'valor';
        },

        newCondition() {
            const first = Object.keys(this.fields.conditions)[0];
            const defaults = this.defaultsFor(first);

            return { type: first, operator: defaults.operator, value: defaults.value, meta: {} };
        },

        newAction() {
            return this.hydrateAction({
                type: 'percentage', value: 10, target: 'cart', max_discount_cents: null, meta: {},
            });
        },

        /**
         * Campos de lista guardam o TEXTO digitado, nao o array.
         *
         * A versao anterior reconstruia o texto a cada tecla a partir do
         * array — ao digitar a virgula, o pedaco vazio depois dela era
         * descartado e a virgula sumia da tela. O ponto e virgula parecia
         * funcionar porque nao era separador nenhum: "7;9" virava um unico
         * valor invalido e a regra nunca aplicava.
         */
        hydrateAction(action) {
            action.meta = action.meta || {};
            action._raw = {
                component_types: (action.meta.component_types || []).join(', '),
                category_ids: (action.meta.category_ids || []).join(', '),
                skus: (action.meta.skus || []).join(', '),
            };

            return action;
        },

        // Aceita virgula e ponto e virgula: quem cadastra usa os dois.
        parseList(raw) {
            return String(raw || '').split(/[,;]/).map(s => s.trim()).filter(Boolean);
        },

        addTier(action) {
            if (!action.meta.tiers) action.meta.tiers = [];
            action.meta.tiers.push({ min: 0, percent: 0 });
        },

        parseRawConditions() {
            try {
                const parsed = JSON.parse(this.rawConditions || '{}');
                this.conditions = parsed.logic ? parsed : { logic: 'and', children: [] };
            } catch (e) {
                alert('JSON invalido: ' + e.message);
            }
        },

        /**
         * Converte o valor digitado para o tipo que a condicao espera.
         * O motor compara com == , entao string "20000" funcionaria — mas
         * gravar tipado deixa o JSON legivel e evita surpresa em 'in'.
         */
        castValue(type, raw) {
            const kind = (this.fields.conditions[type] || {}).value || 'raw';

            if (kind === 'cents' || kind === 'int') {
                return Number(raw) || 0;
            }
            if (kind === 'bool') {
                return raw === true || raw === 'true' || raw === '1' || raw === 1;
            }
            if (kind === 'list') {
                return Array.isArray(raw) ? raw : String(raw).split(',').map(s => s.trim()).filter(Boolean);
            }
            try { return JSON.parse(raw); } catch (e) { return raw; }
        },

        serializeConditions(group) {
            return {
                logic: group.logic,
                children: (group.children || []).map(child => child.logic
                    ? this.serializeConditions(child)
                    : {
                        type: child.type,
                        operator: child.operator,
                        value: this.castValue(child.type, child.value),
                        meta: child.meta || {},
                    }),
            };
        },

        sync() {
            if (this.advanced) this.parseRawConditions();

            const conditions = this.conditions.children.length
                ? this.serializeConditions(this.conditions)
                : null;

            this.$refs.conditionsJson.value = conditions ? JSON.stringify(conditions) : '';

            this.$refs.actionsJson.value = JSON.stringify(this.actions.map(a => {
                const spec = this.fields.actions[a.type] || {};
                const meta = Object.assign({}, a.meta);

                // Preenche os defaults dos campos que o usuario nao tocou.
                Object.keys(spec.meta || {}).forEach(k => {
                    if (meta[k] === undefined && spec.meta[k].default !== undefined) {
                        meta[k] = spec.meta[k].default;
                    }
                });

                // Texto -> array so na hora de gravar.
                ['component_types', 'category_ids', 'skus'].forEach(k => {
                    const parsed = this.parseList((a._raw || {})[k]);

                    if (parsed.length) {
                        meta[k] = parsed;
                    } else {
                        delete meta[k];
                    }
                });

                const out = { type: a.type, value: Number(a.value) || 0, target: a.target, meta: meta };

                if (a.max_discount_cents) out.max_discount_cents = Number(a.max_discount_cents);

                return out;
            }));
        },
    };
}
</script>
@endsection
