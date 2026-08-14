<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\ResolutionStrategy;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;
use SolutionsTI\DiscountEngine\Core\Registry\ActionRegistry;
use SolutionsTI\DiscountEngine\Core\Registry\ConditionRegistry;
use SolutionsTI\DiscountEngine\Laravel\Http\Requests\RuleRequest;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountCoupon;
use SolutionsTI\DiscountEngine\Laravel\Models\DiscountRule;
use SolutionsTI\DiscountEngine\Laravel\Support\PanelFieldMap;

final class RuleController extends Controller
{
    public function index(): View
    {
        $rules = DiscountRule::query()
            ->withCount('coupons')
            ->orderBy('priority')
            ->orderBy('name')
            ->paginate(25);

        return view('discount-engine::rules.index', [
            'rules' => $rules,
            'insecure' => $this->panelLooksUnprotected(),
        ]);
    }

    public function create(): View
    {
        return view('discount-engine::rules.form', $this->formData(new DiscountRule([
            'trigger' => TriggerType::Automatic->value,
            'priority' => 100,
            'combination_mode' => CombinationMode::Stackable->value,
            'resolution_strategy' => ResolutionStrategy::FirstByPriority->value,
            'calculation_base' => CalculationBase::Current->value,
            'active' => true,
        ])));
    }

    public function store(RuleRequest $request): RedirectResponse
    {
        $rule = DB::transaction(function () use ($request): DiscountRule {
            $rule = DiscountRule::create($this->attributes($request));
            $this->syncCoupons($rule, $request);

            return $rule;
        });

        return redirect()
            ->route('discount-engine.rules.edit', $rule)
            ->with('status', "Regra [{$rule->name}] criada.");
    }

    public function edit(DiscountRule $rule): View
    {
        return view('discount-engine::rules.form', $this->formData($rule));
    }

    public function update(RuleRequest $request, DiscountRule $rule): RedirectResponse
    {
        $warnings = DB::transaction(function () use ($request, $rule): array {
            $rule->update($this->attributes($request));

            return $this->syncCoupons($rule, $request);
        });

        return redirect()
            ->route('discount-engine.rules.edit', $rule)
            ->with('status', 'Regra atualizada.')
            ->with('warnings', $warnings);
    }

    public function toggle(DiscountRule $rule): RedirectResponse
    {
        $rule->update(['active' => ! $rule->active]);

        return back()->with('status', $rule->active ? 'Regra ativada.' : 'Regra desativada.');
    }

    public function destroy(DiscountRule $rule): RedirectResponse
    {
        // Apagar a regra apaga os usos em cascata — e o historico financeiro
        // do pedido some junto. Desativar preserva a auditoria.
        if ($rule->usages()->exists()) {
            return back()->withErrors([
                'rule' => 'Esta regra ja foi usada em pedidos. Desative em vez de apagar, para nao perder o historico.',
            ]);
        }

        $rule->delete();

        return redirect()
            ->route('discount-engine.rules.index')
            ->with('status', 'Regra removida.');
    }

    /** @return array<string,mixed> */
    private function attributes(RuleRequest $request): array
    {
        return [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'trigger' => $request->input('trigger'),
            'priority' => (int) $request->input('priority'),
            'combination_mode' => $request->input('combination_mode'),
            'resolution_group' => $request->input('resolution_group') ?: null,
            'resolution_strategy' => $request->input('resolution_strategy'),
            'calculation_base' => $request->input('calculation_base'),
            'stop_further_processing' => $request->boolean('stop_further_processing'),
            'active' => $request->boolean('active'),
            'valid_from' => $request->input('valid_from') ?: null,
            'valid_until' => $request->input('valid_until') ?: null,
            'conditions' => $request->conditionsPayload(),
            'actions' => $request->actionsPayload(),
        ];
    }

    /**
     * Cria os codigos novos e remove os que sairam da lista.
     *
     * Cupom que ja foi usado NUNCA e removido: apagar a linha quebraria a
     * referencia dos usos gravados. Nesses casos o codigo e apenas
     * desativado e o painel avisa.
     *
     * @return array<int,string>  avisos para exibir ao usuario
     */
    private function syncCoupons(DiscountRule $rule, RuleRequest $request): array
    {
        if ($request->input('trigger') !== TriggerType::Coupon->value) {
            return [];
        }

        $codes = $request->couponCodes();
        $warnings = [];

        $shared = [
            'usage_limit' => $request->input('usage_limit') ?: null,
            'usage_limit_per_customer' => $request->input('usage_limit_per_customer') ?: null,
            'expires_at' => $request->input('expires_at') ?: null,
        ];

        foreach ($rule->coupons()->get() as $coupon) {
            if (in_array($coupon->code, $codes, true)) {
                $coupon->update($shared + ['active' => true]);

                continue;
            }

            if ($coupon->used_count > 0) {
                $coupon->update(['active' => false]);
                $warnings[] = "O cupom [{$coupon->code}] ja foi usado {$coupon->used_count}x e foi apenas desativado, nao removido.";

                continue;
            }

            $coupon->delete();
        }

        $existing = $rule->coupons()->pluck('code')->all();

        foreach (array_diff($codes, $existing) as $code) {
            DiscountCoupon::create($shared + [
                'rule_id' => $rule->id,
                'code' => $code,
                'active' => true,
            ]);
        }

        return $warnings;
    }

    /** @return array<string,mixed> */
    private function formData(DiscountRule $rule): array
    {
        return [
            'rule' => $rule,
            'conditionsJson' => $this->pretty($rule->conditions),
            'actionsJson' => $this->pretty($rule->actions ?: []),
            'couponCodes' => $rule->exists
                ? implode("\n", $rule->coupons()->orderBy('code')->pluck('code')->all())
                : '',
            'firstCoupon' => $rule->exists ? $rule->coupons()->first() : null,
            'availableConditions' => app(ConditionRegistry::class)->options(),
            'availableActions' => app(ActionRegistry::class)->options(),
            'fieldMap' => [
                'conditions' => app(PanelFieldMap::class)->conditions(),
                'actions' => app(PanelFieldMap::class)->actions(),
            ],
            // O construtor visual trabalha com estruturas, nao com texto.
            // O JSON cru continua disponivel no modo avancado.
            'conditionsData' => $this->conditionsData($rule),
            'actionsData' => $this->actionsData($rule),
            'insecure' => $this->panelLooksUnprotected(),
        ];
    }

    /**
     * O construtor espera sempre um grupo raiz, mesmo vazio.
     *
     * @return array<string,mixed>
     */
    private function conditionsData(DiscountRule $rule): array
    {
        $conditions = old('conditions_json')
            ? json_decode((string) old('conditions_json'), true)
            : $rule->conditions;

        if (! is_array($conditions) || ! isset($conditions['logic'])) {
            return ['logic' => 'and', 'children' => []];
        }

        $conditions['children'] = array_map(
            static function (array $child): array {
                if (isset($child['logic'])) {
                    return $child;
                }

                // O input de texto nao lida com array; vira lista separada por virgula.
                if (is_array($child['value'] ?? null)) {
                    $child['value'] = implode(', ', $child['value']);
                }

                // O select booleano trabalha com as strings 'true'/'false';
                // sem isso, ao reabrir a regra o campo vinha vazio e salvar
                // de novo silenciosamente invertia a condicao.
                if (is_bool($child['value'] ?? null)) {
                    $child['value'] = $child['value'] ? 'true' : 'false';
                }

                $child['meta'] = $child['meta'] ?? [];

                return $child;
            },
            array_values($conditions['children'] ?? []),
        );

        return $conditions;
    }

    /** @return array<int,array<string,mixed>> */
    private function actionsData(DiscountRule $rule): array
    {
        $actions = old('actions_json')
            ? json_decode((string) old('actions_json'), true)
            : $rule->actions;

        if (! is_array($actions)) {
            return [];
        }

        return array_map(
            static fn (array $action): array => array_merge([
                'type' => 'percentage',
                'value' => 0,
                'target' => 'cart',
                'max_discount_cents' => null,
                'meta' => [],
            ], $action),
            array_values($actions),
        );
    }

    private function pretty(mixed $value): string
    {
        if ($value === null || $value === []) {
            return '';
        }

        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Heuristica simples: se nenhum middleware alem de 'web' foi configurado,
     * qualquer visitante da internet pode reescrever as regras de preco.
     */
    private function panelLooksUnprotected(): bool
    {
        $middleware = (array) config('discount-engine.panel.middleware', ['web']);

        return array_values(array_diff($middleware, ['web'])) === [];
    }
}
