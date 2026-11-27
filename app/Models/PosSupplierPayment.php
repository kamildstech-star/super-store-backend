<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSupplierPayment extends Model
{
    public $timestamps = false;

    protected $hidden = ['request_id', 'user_id'];

    protected $casts = ['amount_minor' => 'integer', 'cash_session_id' => 'integer', 'posted_at' => 'immutable_datetime'];
}
