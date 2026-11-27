<?php

namespace App\Services;

use App\Models\PosPurchaseReceipt;
use App\Models\PosSupplierPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PosSupplierPayments
{
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'amount_minor' => ['required_with:'.($prefix ? 'payment' : 'source'), 'integer', 'between:1,'.PosCashDrawer::MAX_CASH],
            $prefix.'source' => ['required_with:'.($prefix ? 'payment' : 'amount_minor'), 'in:external,drawer'],
            $prefix.'cash_session_id' => ['nullable', 'required_if:'.$prefix.'source,drawer', 'prohibited_unless:'.$prefix.'source,drawer', 'integer', 'min:1'],
            $prefix.'note' => ['nullable', 'string', 'max:160'],
        ];
    }

    public static function normalize(array $payment): array
    {
        return [
            'amount_minor' => (int) $payment['amount_minor'],
            'source' => $payment['source'],
            'cash_session_id' => isset($payment['cash_session_id']) ? (int) $payment['cash_session_id'] : null,
            'note' => $payment['note'] ?? null,
        ];
    }

    // The drawer lock (if needed) must be obtained before the receipt or stock locks.
    public static function record(PosPurchaseReceipt $receipt, User $actor, string $key, array $payment, ?object $drawer): PosSupplierPayment
    {
        if (!$receipt->payment_tracking) {
            throw ValidationException::withMessages(['amount_minor' => 'Payment history for this old receipt was not tracked. Reconcile it before recording new payments.']);
        }
        $amount = $payment['amount_minor'];
        if ($amount > $receipt->balance_minor) {
            throw ValidationException::withMessages(['amount_minor' => 'Payment cannot exceed the current balance on this receipt.']);
        }
        $movement = null;
        if ($payment['source'] === 'drawer') {
            $session = PosCashDrawer::requireSession($drawer, $payment['cash_session_id']);
            $shift = DB::table('pos_cash_sessions')->find($session);
            if (PosCashDrawer::expected($shift) < $amount) {
                throw ValidationException::withMessages(['amount_minor' => 'The open drawer does not have enough expected cash.']);
            }
            $movement = DB::table('pos_cash_movements')->insertGetId([
                'session_id' => $session, 'user_id' => $actor->id, 'actor' => $actor->name,
                'request_id' => (string) Str::uuid(), 'kind' => 'out', 'amount_minor' => $amount,
                'reason' => 'Supplier payment · PR-'.str_pad((string) $receipt->id, 4, '0', STR_PAD_LEFT),
                'posted_at' => now(),
            ]);
            PosCashDrawer::add($session, 'cash_out_minor', $amount);
        }

        $row = new PosSupplierPayment;
        $row->forceFill($payment + [
            'receipt_id' => $receipt->id, 'user_id' => $actor->id, 'actor' => $actor->name,
            'request_id' => $key, 'cash_movement_id' => $movement, 'posted_at' => now(),
        ])->save();
        $receipt->forceFill(['paid_minor' => $receipt->paid_minor + $amount])->save();

        return $row;
    }
}
