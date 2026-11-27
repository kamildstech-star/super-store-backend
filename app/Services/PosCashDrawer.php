<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class PosCashDrawer
{
    public const MAX_CASH = 9000000000000000;

    // All new cash events and the close take this same lock, before any order or stock lock.
    public static function lock(): object
    {
        return DB::table('pos_cash_drawers')->where('id', 1)->lockForUpdate()->first();
    }

    public static function requireSession(object $drawer, ?int $expected): ?int
    {
        if (! $drawer->tracking_enabled && $expected === null) {
            return null;
        }
        if ($drawer->active_session_id === null || (int) $drawer->active_session_id !== $expected) {
            throw new ConflictHttpException('The cash drawer changed or is closed. Reopen the payment or refund and check the active shift.');
        }

        return $expected;
    }

    public static function add(?int $session, string $field, int $amount): void
    {
        if ($session !== null) {
            $changed = DB::table('pos_cash_sessions')->where('id', $session)
                ->where($field, '<=', self::MAX_CASH - $amount)->incrementEach([$field => $amount, 'revision' => 1]);
            if ($changed !== 1) {
                throw ValidationException::withMessages(['amount_minor' => 'This amount exceeds the shift limit.']);
            }
        }
    }

    public static function expected(object $session): int
    {
        return $session->opening_minor + $session->sales_minor + $session->cash_in_minor - $session->cash_out_minor - $session->refunds_minor;
    }

    public static function summary(object $session): array
    {
        $result = (array) $session;
        unset($result['open_key'], $result['close_key']);
        $result['expected_minor'] = $session->expected_minor ?? self::expected($session);
        foreach ($result as $key => $value) {
            if (str_ends_with($key, '_minor') && $value !== null) {
                $result[$key] = (string) $value;
            }
        }
        foreach (['opened_at', 'closed_at'] as $key) {
            if ($result[$key] !== null) {
                $result[$key] = \Carbon\CarbonImmutable::parse($result[$key], 'UTC')->toAtomString();
            }
        }

        return $result;
    }
}
