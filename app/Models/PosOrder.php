<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    protected $fillable = [
        'user_id', 'request_id', 'cashier', 'customer', 'note', 'line_count', 'total_minor', 'lines',
    ];

    protected $hidden = ['user_id', 'request_id', 'checkout_id', 'updated_at'];

    protected function casts(): array
    {
        return ['line_count' => 'integer', 'total_minor' => 'integer', 'lines' => 'array', 'finalized_at' => 'datetime'];
    }
}
