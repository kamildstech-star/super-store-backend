<?php

namespace App\Services;

use App\Models\PosProduct;
use Illuminate\Validation\ValidationException;

class PosProductCode
{
    public function next(): string
    {
        $used = array_fill_keys(PosProduct::query()->pluck('internal_code')->all(), true);
        $available = [];
        for ($i = 0; $i < 10000; $i++) {
            $code = sprintf('%04d', $i);
            if (!isset($used[$code])) {
                $available[] = $code;
            }
        }

        if ($available === []) {
            throw ValidationException::withMessages(['internal_code' => 'All 10,000 internal codes are in use.']);
        }

        return $available[random_int(0, count($available) - 1)];
    }
}
