<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    public static function profileTypeForCode(?string $code): ?string
    {
        return $code === null ? null : config('customer_trade_form.company_profile_map.'.$code);
    }
}
