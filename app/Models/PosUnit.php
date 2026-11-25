<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosUnit extends Model
{
    protected $fillable = ['code', 'name', 'quantity_precision'];

    protected function casts(): array
    {
        return ['quantity_precision' => 'integer'];
    }
}
