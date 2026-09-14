<?php

namespace App\Http\Requests\Catalog;

use Illuminate\Foundation\Http\FormRequest;

class CatalogStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function page(): int
    {
        return max(1, (int) $this->integer('page', 1));
    }

    public function perPage(): int
    {
        return min(200, max(1, (int) $this->integer('per_page', 100)));
    }
}
