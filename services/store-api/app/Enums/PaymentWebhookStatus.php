<?php

namespace App\Enums;

enum PaymentWebhookStatus: string
{
    case Paid = 'paid';
    case Failed = 'failed';
}
