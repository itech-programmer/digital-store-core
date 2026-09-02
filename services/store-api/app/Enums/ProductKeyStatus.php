<?php

namespace App\Enums;

enum ProductKeyStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Delivered = 'delivered';
}
