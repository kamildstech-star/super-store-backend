<?php

namespace App\Services;

use App\Models\PosOrder;
use Illuminate\Support\Facades\DB;

final class PosOrderNumber
{
    public const TIMEZONE = 'Asia/Karachi';

    // Called inside the order transaction, after checking for an exact replay.
    public static function assign(PosOrder $order): void
    {
        $created = now();
        $date = $created->copy()->setTimezone(self::TIMEZONE)->toDateString();
        // One atomic upsert takes an exclusive lock until both the counter and order commit.
        DB::table('pos_order_counters')->upsert(
            ['business_date' => $date, 'last_number' => 1],
            ['business_date'],
            ['last_number' => DB::raw('last_number + 1')],
        );
        $number = DB::table('pos_order_counters')->where('business_date', $date)->lockForUpdate()->value('last_number');

        $order->forceFill(['business_date' => $date, 'daily_number' => $number, 'created_at' => $created]);
    }

    public static function reference(int $id, ?int $number): string
    {
        return '#'.str_pad((string) ($number ?? $id), $number === null ? 3 : 4, '0', STR_PAD_LEFT);
    }
}
