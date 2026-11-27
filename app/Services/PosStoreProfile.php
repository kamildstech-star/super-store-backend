<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

final class PosStoreProfile
{
    public static function record(bool $lock = false): object
    {
        $query = DB::table('pos_store_settings')->where('id', 1);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public static function branding(?object $record = null): array
    {
        $record ??= self::record();

        return [
            'name' => $record->name,
            'address' => $record->address,
            'phone' => $record->phone,
            'receipt_footer' => $record->receipt_footer,
        ];
    }

    public static function settings(?object $record = null): array
    {
        $record ??= self::record();

        return self::branding($record) + [
            'revision' => (int) $record->revision,
            'receipt_width_mm' => (int) $record->receipt_width_mm,
            'currency' => 'PKR',
            'timezone' => PosOrderNumber::TIMEZONE,
        ];
    }
}
