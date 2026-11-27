<?php

namespace App\Http\Controllers;

use App\Models\PosOrder;
use App\Models\PosProduct;
use App\Models\PosRefund;
use App\Models\PosStockMovement;
use App\Services\PosCashDrawer;
use App\Services\PosInventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PosRefundController extends Controller
{
    public function index(PosOrder $order): JsonResponse
    {
        $this->requireCashSale($order);
        $page = PosRefund::query()->where('order_id', $order->id)->orderByDesc('id')->paginate(10);

        return response()->json($page->through(fn (PosRefund $refund) => $refund->receipt($order)))->header('Cache-Control', 'no-store');
    }

    public function show(PosRefund $refund): JsonResponse
    {
        return response()->json($refund->receipt())->header('Cache-Control', 'no-store');
    }

    public function options(PosOrder $order): JsonResponse
    {
        $this->requireCashSale($order);

        return response()->json(['lines' => $this->available($order), 'order_reference' => $order->reference, 'order_business_date' => $order->business_date])->header('Cache-Control', 'no-store');
    }

    public function preview(Request $request, PosOrder $order): JsonResponse
    {
        $this->requireCashSale($order);
        $body = $this->input($request);
        $lines = $this->plan($body, $this->available($order));

        return response()->json($this->quote($lines))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, int $order): JsonResponse
    {
        $body = $this->input($request, true);

        return DB::transaction(function () use ($request, $order, $body): JsonResponse {
            $drawer = PosCashDrawer::lock();
            $actor = $request->user();
            $sale = PosOrder::query()->lockForUpdate()->findOrFail($order);
            $session = isset($body['cash_session_id']) ? (int) $body['cash_session_id'] : null;
            $fingerprint = $this->hash([$order, $session, $body['reason'], $body['quote'], $body['lines']]);
            if ($past = PosRefund::query()->where('request_id', $body['request_id'])->first()) {
                if ((int) $past->user_id !== $actor->id || ! hash_equals($past->fingerprint, $fingerprint)) {
                    throw new ConflictHttpException('This refund reference was used for a different submission.');
                }

                return response()->json($past->receipt($sale));
            }
            $this->requireCashSale($sale);
            PosCashDrawer::requireSession($drawer, $session);
            $lines = $this->plan($body, $this->available($sale, true));
            $quote = $this->quote($lines);
            if (! hash_equals($quote['quote'], $body['quote'])) {
                throw new ConflictHttpException('Return availability or the refund amount changed. Review the refund again.');
            }
            $refund = PosRefund::create([
                'order_id' => $sale->id, 'cash_session_id' => $session, 'user_id' => $actor->id, 'actor' => $actor->name,
                'request_id' => $body['request_id'], 'fingerprint' => $fingerprint, 'reason' => $body['reason'],
                'total_minor' => $quote['total_minor'], 'lines' => array_map(function ($line) {
                    unset($line['returned_milli'], $line['product_id']);

                    return $line;
                }, $lines), 'posted_at' => now(),
            ]);
            foreach ($lines as $line) {
                if ($line['restock']) {
                    $product = PosProduct::with('unit')->findOrFail($line['product_id']);
                    PosInventory::move($product, $actor, 'return', $line['qty_milli'], 'Return #'.$refund->id.' for sale '.$sale->reference.($sale->business_date ? ' ('.$sale->business_date.')' : ''), ['refund_id' => $refund->id]);
                }
            }
            PosCashDrawer::add($session, 'refunds_minor', (int) $quote['total_minor']);

            return response()->json($refund->receipt($sale), 201);
        }, 3);
    }

    private function requireCashSale(PosOrder $order): void
    {
        abort_unless($order->finalized_at && $order->payment_method === 'cash', 422, 'Only finalized cash sales are eligible for cash refunds.');
    }

    private function input(Request $request, bool $post = false): array
    {
        $body = $request->validate([
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:line,qty_milli,restock'],
            'lines.*.line' => ['required', 'integer', 'between:0,99', 'distinct'],
            'lines.*.qty_milli' => ['required', 'integer', 'between:1,9999000'],
            'lines.*.restock' => ['required', 'boolean'],
        ] + ($post ? [
            'request_id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:160'],
            'quote' => ['required', 'string', 'size:64'], 'cash_session_id' => ['nullable', 'integer', 'min:1'],
        ] : []));
        $body['lines'] = array_map(fn ($line) => [
            'line' => (int) $line['line'], 'qty_milli' => (int) $line['qty_milli'], 'restock' => (bool) $line['restock'],
        ], $body['lines']);
        usort($body['lines'], fn ($a, $b) => $a['line'] <=> $b['line']);

        return $body;
    }

    private function available(PosOrder $sale, bool $lock = false): array
    {
        $returned = [];
        foreach (PosRefund::query()->where('order_id', $sale->id)->pluck('lines') as $lines) {
            // A pluck from the Eloquent query applies the JSON cast.
            foreach ($lines as $line) {
                $returned[$line['line']] = ($returned[$line['line']] ?? 0) + $line['qty_milli'];
            }
        }
        $ids = PosStockMovement::query()->where('order_id', $sale->id)->where('type', 'sale')->pluck('product_id');
        $products = PosProduct::query()->with('unit')->whereIn('id', $ids)->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())->get()->keyBy('sku');

        return array_map(function ($old, $index) use ($returned, $products) {
            $sold = (int) round($old['qty'] * 1000);
            $product = $products->get($old['sku']);
            $sameUnit = $product && $product->unit->code === $old['unit'];
            // For legacy receipts without a precision snapshot, never split finer than we can verify.
            $inferredPrecision = $sold % 1000 === 0 ? 0 : ($sold % 100 === 0 ? 1 : ($sold % 10 === 0 ? 2 : 3));
            $step = 10 ** (3 - ($old['quantity_precision'] ?? ($sameUnit ? max($inferredPrecision, $product->unit->quantity_precision) : $inferredPrecision)));
            $already = $returned[$index] ?? 0;

            return [
                'line' => $index, 'sku' => $old['sku'], 'name' => $old['name'], 'unit' => $old['unit'],
                'sold_milli' => $sold, 'returned_milli' => $already, 'remaining_milli' => $sold - $already,
                'step_milli' => $step, 'paid_minor' => (string) $old['total_minor'],
                'product_id' => $product?->id, 'can_restock' => $sameUnit && $product->is_active,
                'restock_step_milli' => $sameUnit ? 10 ** (3 - $product->unit->quantity_precision) : null,
            ];
        }, $sale->lines, array_keys($sale->lines));
    }

    private function plan(array $body, array $available): array
    {
        $result = [];
        foreach ($body['lines'] as $line) {
            $row = $available[$line['line']] ?? null;
            $qty = $line['qty_milli'];
            if (! $row || $qty > $row['remaining_milli'] || $qty % $row['step_milli'] !== 0) {
                throw ValidationException::withMessages(['lines' => 'A return quantity exceeds the remaining quantity or does not match the original unit.']);
            }
            if ($line['restock'] && (! $row['can_restock'] || $qty % $row['restock_step_milli'] !== 0)) {
                throw ValidationException::withMessages(['lines' => "{$row['name']} cannot be added to sellable stock. Choose No restock to continue."]);
            }
            // Allocate against the original paid line, with cumulative half-up rounding.
            // Quotient/remainder splitting avoids overflowing when multiplying a price by fractional quantity.
            $sold = $row['sold_milli'];
            $paid = (int) $row['paid_minor'];
            $share = fn (int $q) => intdiv($paid, $sold) * $q + intdiv($paid % $sold * $q + intdiv($sold, 2), $sold);
            $amount = $share($row['returned_milli'] + $qty) - $share($row['returned_milli']);
            $result[] = $line + [
                'sku' => $row['sku'], 'name' => $row['name'], 'unit' => $row['unit'], 'product_id' => $row['product_id'],
                'returned_milli' => $row['returned_milli'], 'amount_minor' => (string) $amount,
            ];
        }

        return $result;
    }

    private function quote(array $lines): array
    {
        return ['quote' => $this->hash($lines), 'total_minor' => (string) array_sum(array_column($lines, 'amount_minor')), 'lines' => $lines];
    }

    private function hash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
