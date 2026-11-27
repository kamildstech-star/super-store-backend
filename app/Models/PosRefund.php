<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosRefund extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'cash_session_id', 'user_id', 'request_id', 'fingerprint', 'actor', 'reason', 'total_minor', 'lines', 'posted_at'];

    protected $hidden = ['request_id', 'fingerprint'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'posted_at' => 'immutable_datetime'];
    }

    public function receipt(): array
    {
        return array_replace($this->toArray(), ['total_minor' => (string) $this->total_minor]);
    }
}
