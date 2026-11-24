<?php

namespace App\Models\Accounting;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'rate_date' => 'date',
        ];
    }
}
