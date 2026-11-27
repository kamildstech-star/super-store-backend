<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosRefund extends Model
{
    public $timestamps = false;

    protected $fillable = ['order_id', 'cash_session_id', 'user_id', 'request_id', 'fingerprint', 'actor', 'reason', 'receipt_store', 'total_minor', 'lines', 'posted_at'];

    protected $hidden = ['request_id', 'fingerprint'];

    protected function casts(): array
    {
        return ['lines' => 'array', 'receipt_store' => 'array', 'posted_at' => 'immutable_datetime'];
    }

    public function receipt(?PosOrder $order = null): array
    {
        $order ??= PosOrder::query()->select(['id', 'business_date', 'daily_number'])->findOrFail($this->order_id);

        return array_replace($this->toArray(), [
            'total_minor' => (string) $this->total_minor,
            'order_reference' => $order->reference,
            'order_business_date' => $order->business_date,
        ]);
    }
}
