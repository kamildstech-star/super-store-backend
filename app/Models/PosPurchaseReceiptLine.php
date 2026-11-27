<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosPurchaseReceiptLine extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_id', 'name', 'internal_code', 'unit', 'quantity_milli', 'unit_cost_minor', 'total_minor'];

    protected $casts = ['quantity_milli' => 'integer', 'unit_cost_minor' => 'integer', 'total_minor' => 'integer'];
}
