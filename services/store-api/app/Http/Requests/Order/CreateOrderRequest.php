<?php

namespace App\Http\Requests\Order;

use App\DTO\Order\CreateOrderDto;
use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'public_id' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^ord_[a-z0-9]+$/'],
        ];
    }

    public function toDto(): CreateOrderDto
    {
        return new CreateOrderDto(
            sku: (string) $this->validated('sku'),
            publicId: $this->validated('public_id') ?? null,
        );
    }
}
