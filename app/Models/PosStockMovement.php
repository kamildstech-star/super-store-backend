<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosStockMovement extends Model
{
    protected $fillable = [
        'product_id', 'supplier_id', 'order_id', 'user_id', 'actor', 'supplier_name',
        'type', 'request_id', 'delta_milli', 'balance_milli', 'reason',
    ];

    protected $hidden = ['user_id', 'request_id', 'updated_at'];

    protected function casts(): array
    {
        return ['delta_milli' => 'integer', 'balance_milli' => 'integer'];
    }
}
