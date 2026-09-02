<?php

namespace App\Http\Requests\Payment;

use App\DTO\Payment\PaymentWebhookDto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['required', 'string', 'max:64'],
            'order_id' => ['required', 'string', 'max:32'],
            'status' => ['required', 'string', Rule::in(['paid', 'failed'])],
            'amount' => ['required', 'numeric'],
            'currency' => ['required', 'string', 'size:3'],
            'created_at' => ['required', 'date'],
        ];
    }

    public function toDto(): PaymentWebhookDto
    {
        return PaymentWebhookDto::fromValidated($this->validated());
    }
}
