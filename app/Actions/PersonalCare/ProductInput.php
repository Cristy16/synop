<?php

namespace App\Actions\PersonalCare;

use App\Actions\Finance\ParsesMoney;
use App\Enums\CategoryKind;
use App\Enums\ProductStatus;
use App\Enums\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation shared by CreateProduct and UpdateProduct (D-045).
 */
class ProductInput
{
    use ParsesMoney;

    public const MAX_PAO_MONTHS = 120;

    /**
     * @param  array<string, mixed>  $input  type, brand, name, category, price (text), purchased_on, opened_on,
     *                                       pao_months, printed_expiry_on, quantity, status, ingredients_text,
     *                                       units_left, low_stock_threshold (vitamins), notes,
     *                                       record_expense (bool), account_id, category_id
     * @return array{fields: array<string, mixed>, expense: array{account_id: int, category_id: int}|null}
     *
     * @throws ValidationException
     */
    public function validate(User $user, array $input): array
    {
        $vitamin = ($input['type'] ?? null) === ProductType::Vitamin->value;
        $expense = filter_var($input['record_expense'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $validated = Validator::make($input, [
            'type' => ['required', Rule::enum(ProductType::class)],
            'brand' => ['nullable', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:50'],
            'price' => [$expense ? 'required' : 'nullable', 'string', 'max:30'],
            'purchased_on' => [$expense ? 'required' : 'nullable', 'date_format:Y-m-d'],
            'opened_on' => ['nullable', 'date_format:Y-m-d', ...(empty($input['purchased_on']) ? [] : ['after_or_equal:purchased_on'])],
            'pao_months' => ['nullable', 'integer', 'between:1,'.self::MAX_PAO_MONTHS],
            'printed_expiry_on' => ['nullable', 'date_format:Y-m-d'],
            'quantity' => ['nullable', 'integer', 'between:0,999'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'ingredients_text' => ['nullable', 'string', 'max:10000'],
            'units_left' => $vitamin ? ['nullable', 'integer', 'between:0,100000'] : ['prohibited'],
            'low_stock_threshold' => $vitamin ? ['nullable', 'integer', 'between:0,100000'] : ['prohibited'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // The expense goes into the user's own active account, under one of their expense categories (rule 10).
            'account_id' => $expense
                ? ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $user->id)->whereNull('deleted_at')]
                : ['nullable'],
            'category_id' => $expense
                ? ['required', 'integer', Rule::exists('transaction_categories', 'id')->where('user_id', $user->id)->where('kind', CategoryKind::Expense->value)]
                : ['nullable'],
        ], [
            'opened_on.after_or_equal' => __('It can\'t be opened before it was bought.'),
            'price.required' => __('Enter the price to record it as an expense.'),
            'purchased_on.required' => __('Enter the purchase date to record it as an expense.'),
        ])->validate();

        $price = ($validated['price'] ?? '') === '' ? null : $this->parseMoney((string) $validated['price'], 'price', 'not_negative');

        if ($expense && ($price === null || $price->isZero())) {
            throw ValidationException::withMessages(['price' => __('An expense needs a price above zero.')]);
        }

        return [
            'fields' => [
                'type' => ProductType::from($validated['type']),
                'brand' => $this->blankToNull($validated['brand'] ?? null),
                'name' => trim($validated['name']),
                'category' => $this->blankToNull($validated['category'] ?? null),
                'price_cents' => $price,
                'purchased_on' => $validated['purchased_on'] ?? null,
                'opened_on' => $validated['opened_on'] ?? null,
                'pao_months' => isset($validated['pao_months']) ? (int) $validated['pao_months'] : null,
                'printed_expiry_on' => $validated['printed_expiry_on'] ?? null,
                'quantity' => isset($validated['quantity']) ? (int) $validated['quantity'] : 1,
                'status' => ProductStatus::from($validated['status']),
                'ingredients_text' => $this->blankToNull($validated['ingredients_text'] ?? null),
                'units_left' => $vitamin && isset($validated['units_left']) ? (int) $validated['units_left'] : null,
                'low_stock_threshold' => $vitamin && isset($validated['low_stock_threshold']) ? (int) $validated['low_stock_threshold'] : null,
                'notes' => $this->blankToNull($validated['notes'] ?? null),
            ],
            'expense' => $expense ? ['account_id' => (int) $validated['account_id'], 'category_id' => (int) $validated['category_id']] : null,
        ];
    }

    private function blankToNull(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
