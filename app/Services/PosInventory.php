<?php

namespace App\Services;

use App\Models\PosProduct;
use App\Models\PosStockMovement;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class PosInventory
{
    public static function checkPrecision(PosProduct $product, int $quantity): void
    {
        if ($quantity % (10 ** (3 - $product->unit->quantity_precision)) !== 0) {
            throw ValidationException::withMessages(['quantity' => "Check the unit precision for {$product->name}."]);
        }
    }

    // The caller must hold the product row lock and an open transaction.
    public static function move(PosProduct $product, User $actor, string $type, int $delta, string $reason, array $links = []): PosStockMovement
    {
        self::checkPrecision($product, $delta);
        $balance = $product->stock_milli + $delta;
        if ($balance < 0) {
            throw ValidationException::withMessages(['quantity' => "Insufficient stock for {$product->name}."]);
        }
        $product->forceFill(['stock_milli' => $balance])->save();

        return PosStockMovement::create([
            'product_id' => $product->id, 'user_id' => $actor->id, 'actor' => $actor->name,
            'type' => $type, 'delta_milli' => $delta, 'balance_milli' => $balance, 'reason' => $reason,
        ] + $links);
    }
}
