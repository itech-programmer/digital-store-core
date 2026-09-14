<?php

namespace App\Http\Requests\Admin;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class OrderAsOfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'at' => ['required', 'date'],
        ];
    }

    public function at(): Carbon
    {
        return Carbon::parse((string) $this->query('at'));
    }
}
