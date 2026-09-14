<?php

namespace App\Http\Requests\Admin;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

class FinancePeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
        ];
    }

    public function from(): Carbon
    {
        return Carbon::parse((string) $this->query('from'));
    }

    public function to(): Carbon
    {
        return Carbon::parse((string) $this->query('to'));
    }
}
