<?php

namespace App\Http\Requests\Order;

use App\DTO\Order\CreateOrderDto;
use App\DTO\Order\CreateOrderItemDto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required_without:items', 'nullable', 'string', 'max:64'],
            'items' => ['required_without:sku', 'nullable', 'array', 'min:1'],
            'items.*.sku' => ['required', 'string', 'max:64'],
            'items.*.qty' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'items.*.quantity' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'public_id' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^ord_[a-z0-9]+$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($this->filled('sku') || $this->filled('items')) {
                return;
            }

            $validator->errors()->add('sku', 'Provide sku or items.');
        });
    }

    public function toDto(): CreateOrderDto
    {
        $items = [];

        if ($this->filled('items')) {
            foreach ($this->validated('items') as $row) {
                $qty = (int) ($row['qty'] ?? $row['quantity'] ?? 1);
                for ($i = 0; $i < $qty; $i++) {
                    $items[] = new CreateOrderItemDto(sku: (string) $row['sku'], quantity: 1);
                }
            }
        } else {
            $items[] = new CreateOrderItemDto(
                sku: (string) $this->validated('sku'),
                quantity: 1,
            );
        }

        return new CreateOrderDto(
            items: $items,
            publicId: $this->validated('public_id') ?? null,
        );
    }
}
