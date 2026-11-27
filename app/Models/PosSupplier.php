<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosSupplier extends Model
{
    protected $fillable = ['name', 'email', 'phone'];

    public function products(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PosProduct::class, 'supplier_id');
    }
}
