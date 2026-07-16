<?php

namespace Webkul\Account\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BankStatementLine extends Model
{
    use HasFactory;

    protected $table = 'accounts_bank_statement_lines';
}
