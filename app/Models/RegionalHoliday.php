<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RegionalHoliday extends Model
{
    protected $fillable = [
        'country_code',
        'holiday_name',
        'holiday_date',
        'year',
        'default_uplift_pct',
    ];

    protected function casts(): array
    {
        return [
            'holiday_date'       => 'date:Y-m-d',
            'year'               => 'integer',
            'default_uplift_pct' => 'float',
        ];
    }
}
