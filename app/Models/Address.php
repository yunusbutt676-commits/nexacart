<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Address extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'line1',
        'line2',
        'city',
        'state',
        'postal_code',
        'country_code',
    ];

    protected $hidden = ['user_id'];
}