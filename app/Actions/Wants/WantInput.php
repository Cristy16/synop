<?php

namespace App\Actions\Wants;

use App\Actions\Finance\ParsesMoney;
use App\Enums\CategoryKind;
use App\Enums\FetchStatus;
use App\Enums\TaskPriority;
use App\Enums\WantProductType;
use App\Models\User;
use App\Services\LinkPreview\ShopName;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validation shared by CreateWant and UpdateWant (D-049).
 */
class WantInput
{
    use ParsesMoney;

    /**
     * @param  array<string, mixed>  $input  url, title, description, price (text), image_url, shop, product_type,
     *                                       expense_category_id, ingredients_text, priority, fetch_status
     * @return array<string, mixed> column => value
     *
     * @throws ValidationException
     */
    public function validate(User $user, array $input): array
    {
        $validated = Validator::make($input, [
            'url' => ['nullable', 'string', 'max:2048', 'url:http,https'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'price' => ['nullable', 'string', 'max:30'],
            // Remote images only over https, so the page never loads insecure content.
            'image_url' => ['nullable', 'string', 'max:2048', 'url:https'],
            'shop' => ['nullable', 'string', 'max:50'],
            'product_type' => ['nullable', Rule::enum(WantProductType::class)],
            'expense_category_id' => ['nullable', 'integer', Rule::exists('transaction_categories', 'id')
                ->where('user_id', $user->id)->where('kind', CategoryKind::Expense->value)],
            'ingredients_text' => ['nullable', 'string', 'max:10000'],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'fetch_status' => ['nullable', Rule::in([FetchStatus::Fetched->value, FetchStatus::Failed->value, FetchStatus::Manual->value])],
        ], [
            'image_url.url' => __('Use an image link that starts with https://.'),
        ])->validate();

        $blank = fn (mixed $v) => ! is_string($v) || trim($v) === '' ? null : trim($v);
        $url = $blank($validated['url'] ?? null);
        $price = $blank($validated['price'] ?? null);

        return [
            'url' => $url,
            'title' => trim($validated['title']),
            'description' => $blank($validated['description'] ?? null),
            'price_cents' => $price === null ? null : $this->parseMoney($price, 'price', 'not_negative'),
            'image_url' => $blank($validated['image_url'] ?? null),
            'shop' => $blank($validated['shop'] ?? null) ?? ShopName::from($url),
            'product_type' => isset($validated['product_type']) ? WantProductType::from($validated['product_type']) : null,
            'expense_category_id' => isset($validated['expense_category_id']) ? (int) $validated['expense_category_id'] : null,
            'ingredients_text' => $blank($validated['ingredients_text'] ?? null),
            'priority' => TaskPriority::from($validated['priority'] ?? TaskPriority::Normal->value),
            'fetch_status' => $url === null
                ? FetchStatus::Manual
                : FetchStatus::from($validated['fetch_status'] ?? FetchStatus::Manual->value),
        ];
    }
}
