<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosPurchaseReceipt extends Model
{
    protected $hidden = ['request_id', 'payload_hash', 'user_id', 'updated_at'];

    protected $casts = ['total_minor' => 'integer', 'line_count' => 'integer', 'paid_minor' => 'integer', 'payment_tracking' => 'boolean'];

    protected $appends = ['balance_minor', 'payment_status'];

    public function getBalanceMinorAttribute(): ?int
    {
        return $this->payment_tracking ? $this->total_minor - ($this->paid_minor ?? 0) : null;
    }

    public function getPaymentStatusAttribute(): string
    {
        return match (true) {
            !$this->payment_tracking => 'untracked',
            $this->balance_minor === 0 => 'paid',
            ($this->paid_minor ?? 0) === 0 => 'unpaid',
            default => 'partial',
        };
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PosPurchaseReceiptLine::class, 'receipt_id')->orderBy('id');
    }
}
