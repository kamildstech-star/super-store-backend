<?php

namespace App\Models;

use App\Services\PosOrderNumber;
use Illuminate\Database\Eloquent\Model;

class PosOrder extends Model
{
    protected $fillable = [
        'user_id', 'request_id', 'cashier', 'customer', 'note', 'line_count', 'total_minor', 'lines',
    ];

    protected $hidden = ['user_id', 'request_id', 'checkout_id', 'request_fingerprint', 'checkout_fingerprint', 'update_request_id', 'update_fingerprint', 'updated_at'];

    protected $appends = ['reference'];

    public function getReferenceAttribute(): string
    {
        return PosOrderNumber::reference($this->id, $this->daily_number);
    }

    protected function casts(): array
    {
        return ['daily_number' => 'integer', 'cash_received_minor' => 'integer', 'line_count' => 'integer', 'total_minor' => 'integer', 'lines' => 'array', 'finalized_at' => 'datetime'];
    }
}
