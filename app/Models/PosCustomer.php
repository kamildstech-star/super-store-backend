<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PosCustomer extends Model
{
    use SoftDeletes;

    protected $fillable = ['name', 'email', 'phone'];
    protected $hidden = ['deleted_at'];
}
