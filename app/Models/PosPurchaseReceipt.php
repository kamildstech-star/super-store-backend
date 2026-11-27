<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosPurchaseReceipt extends Model
{
    protected $hidden = ['request_id', 'payload_hash', 'user_id', 'updated_at'];

    protected $casts = ['total_minor' => 'integer', 'line_count' => 'integer'];

    public function lines(): HasMany
    {
        return $this->hasMany(PosPurchaseReceiptLine::class, 'receipt_id')->orderBy('id');
    }
}
