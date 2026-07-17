<?php

namespace Webkul\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentToken extends Model
{
    use HasFactory;

    protected $table = 'payments_payment_tokens';
}
