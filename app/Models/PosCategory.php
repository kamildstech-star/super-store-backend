<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosCategory extends Model
{
    protected $fillable = ['slug', 'name', 'icon', 'color', 'sort_order'];

    public function products(): HasMany
    {
        return $this->hasMany(PosProduct::class, 'category_id');
    }
}
