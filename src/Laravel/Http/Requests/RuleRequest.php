<?php

declare(strict_types=1);

namespace SolutionsTI\DiscountEngine\Laravel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use SolutionsTI\DiscountEngine\Core\Enums\CalculationBase;
use SolutionsTI\DiscountEngine\Core\Enums\CombinationMode;
use SolutionsTI\DiscountEngine\Core\Enums\ResolutionStrategy;
use SolutionsTI\DiscountEngine\Core\Enums\TriggerType;
use SolutionsTI\DiscountEngine\Laravel\Support\RuleDefinitionValidator;

final class RuleRequest extends FormRequest
{
    /** A autorizacao real vem do middleware configurado em discount-engine.panel. */
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'trigger' => ['required', $this->enumRule(TriggerType::cases())],
            'priority' => ['required', 'integer', 'min:0', 'max:65535'],
            'combination_mode' => ['required', $this->enumRule(CombinationMode::cases())],
            'resolution_group' => ['nullable', 'string', 'max:60'],
            'resolution_strategy' => ['required', $this->enumRule(ResolutionStrategy::cases())],
            'calculation_base' => ['required', $this->enumRule(CalculationBase::cases())],
            'stop_further_processing' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'conditions_json' => ['nullable', 'string'],
            'actions_json' => ['required', 'string'],
            'coupon_codes' => ['nullable', 'string'],
            'usage_limit' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_customer' => ['nullable', 'integer', 'min:1'],
            'expires_at' => ['nullable', 'date'],
        ];
    }

    /**
     * O JSON e validado aqui, nao no hidratador.
     *
     * Erro de digitacao tem que virar mensagem no formulario, nao excecao
     * no checkout de um cliente real tres dias depois.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $structural = app(RuleDefinitionValidator::class);

            $conditions = $this->decode('conditions_json', $validator, 'conditions_json');
            $actions = $this->decode('actions_json', $validator, 'actions_json');

            if ($conditions !== false) {
                foreach ($structural->validateConditions($conditions) as $error) {
                    $validator->errors()->add('conditions_json', $error);
                }
            }

            if ($actions !== false) {
                foreach ($structural->validateActions($actions) as $error) {
                    $validator->errors()->add('actions_json', $error);
                }
            }

            if ($this->input('trigger') === TriggerType::Coupon->value && trim((string) $this->input('coupon_codes')) === '') {
                $validator->errors()->add('coupon_codes', 'Regra por cupom precisa de pelo menos um codigo.');
            }
        });
    }

    /** @return array<string,mixed>|null */
    public function conditionsPayload(): ?array
    {
        $decoded = json_decode((string) $this->input('conditions_json', ''), true);

        return is_array($decoded) && $decoded !== [] ? $decoded : null;
    }

    /** @return array<int,mixed> */
    public function actionsPayload(): array
    {
        $decoded = json_decode((string) $this->input('actions_json', '[]'), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @return array<int,string> */
    public function couponCodes(): array
    {
        $raw = (string) $this->input('coupon_codes', '');

        return array_values(array_unique(array_filter(array_map(
            static fn (string $line): string => strtoupper(trim($line)),
            preg_split('/[\r\n,;]+/', $raw) ?: [],
        ))));
    }

    private function decode(string $field, Validator $validator, string $errorKey): mixed
    {
        $raw = trim((string) $this->input($field, ''));

        if ($raw === '') {
            return $field === 'actions_json' ? [] : null;
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $validator->errors()->add($errorKey, 'JSON invalido: ' . json_last_error_msg());

            return false;
        }

        return $decoded;
    }

    /** @param  array<int,\BackedEnum>  $cases */
    private function enumRule(array $cases): string
    {
        return 'in:' . implode(',', array_column($cases, 'value'));
    }
}
