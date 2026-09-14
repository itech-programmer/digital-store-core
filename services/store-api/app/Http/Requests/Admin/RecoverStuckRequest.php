<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RecoverStuckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stale_minutes' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function staleMinutes(): int
    {
        return max(1, (int) $this->integer('stale_minutes', 10));
    }
}
