<?php

namespace App\Http\Controllers;

use App\Models\PosCustomer;
use App\Models\PosOrder;
use App\Models\PosProduct;
use App\Models\User;
use App\Services\PosListing;
use App\Services\PosCashDrawer;
use App\Services\PosInventory;
use App\Services\PosOrderNumber;
use App\Services\PosStoreProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = PosListing::filters($request);
        $term = $filters['q'] ?? '';
        $orders = PosOrder::query()
            ->select(['id', 'created_at', 'cashier', 'customer', 'line_count', 'total_minor', 'finalized_at', 'payment_method', 'business_date', 'daily_number'])
            ->orderByDesc('id');
        if ($term !== '') {
            $orders->where(function ($match) use ($term): void {
                PosListing::whereContains($match, ['cashier', 'customer'], $term);
                if (preg_match('/^([0-9]{4}-[0-9]{2}-[0-9]{2})\s+#?([0-9]{1,18})$/D', $term, $reference)) {
                    [$year, $month, $day] = array_map('intval', explode('-', $reference[1]));
                    if (checkdate($month, $day, $year)) {
                        $match->orWhere(fn ($byDay) => $byDay->where('business_date', $reference[1])->where('daily_number', (int) $reference[2]));
                    }
                } elseif (preg_match('/^(?:order\s*)?#?([0-9]{1,18})$/iD', $term, $reference)) {
                    $match->orWhere('daily_number', (int) $reference[1])
                        ->orWhere(fn ($legacy) => $legacy->whereNull('daily_number')->where('id', (int) $reference[1]));
                }
                if (in_array(strtolower($term), ['walk-in', 'walk in'], true)) {
                    $match->orWhereNull('customer');
                }
            });
        }

        return PosListing::response($orders, $filters);
    }

    public function show(PosOrder $order): JsonResponse
    {
        return response()->json($this->detail($order));
    }

    public function store(Request $request): JsonResponse
    {
        $body = $request->validate(['request_id' => ['required', 'uuid']] + $this->orderRules());
        return DB::transaction(function () use ($request, $body): JsonResponse {
            $identity = ['user_id' => $request->user()->id, 'request_id' => $body['request_id']];
            // Serialize same-cashier Park/checkout retries, including before the order row exists.
            User::query()->lockForUpdate()->findOrFail($identity['user_id']);
            $fingerprint = $this->fingerprint('park', $body);
            if ($existing = PosOrder::where($identity)->lockForUpdate()->first()) {
                $original = $existing->request_fingerprint ?? $this->fingerprint('park', $existing->toArray());

                return ! $existing->finalized_at && hash_equals($original, $fingerprint)
                    ? response()->json($this->detail($existing))
                    : $this->submissionConflict($existing);
            }

            $order = new PosOrder($identity + ['cashier' => $request->user()->name] + $this->orderAttributes($body));
            PosOrderNumber::assign($order);
            $order->forceFill(['request_fingerprint' => $fingerprint])->save();

            return response()->json($this->detail($order), 201);
        }, 3);
    }

    public function update(Request $request, int $order): JsonResponse
    {
        $body = $request->validate(['revision' => ['required', 'string', 'size:64'], 'update_id' => ['sometimes', 'required', 'uuid']] + $this->orderRules());

        return DB::transaction(function () use ($body, $order): JsonResponse {
            $saved = PosOrder::query()->lockForUpdate()->findOrFail($order);
            if ($saved->finalized_at) {
                return response()->json(['message' => 'Finalized sales cannot be edited.'], 409);
            }
            $fingerprint = $this->fingerprint('update', $body);
            if (isset($body['update_id']) && $saved->update_request_id === $body['update_id']) {
                return hash_equals($saved->update_fingerprint ?? '', $fingerprint)
                    ? response()->json($this->detail($saved))
                    : $this->submissionConflict($saved);
            }
            if (! hash_equals($this->revision($saved), $body['revision'])) {
                return response()->json(['message' => 'This order was changed elsewhere. Open it again to edit the latest version.'], 409);
            }

            $saved->fill($this->orderAttributes($body, $saved))->forceFill([
                'update_request_id' => $body['update_id'] ?? null,
                'update_fingerprint' => isset($body['update_id']) ? $fingerprint : null,
            ])->save();

            return response()->json($this->detail($saved));
        });
    }

    public function checkout(Request $request, ?int $order = null): JsonResponse
    {
        $body = $request->validate([
            'payment_method' => ['required', 'in:cash,card,bank_transfer'],
            'cash_session_id' => ['nullable', 'integer', 'min:1'],
            'cash_received_minor' => ['required_if:payment_method,cash', 'prohibited_unless:payment_method,cash', 'integer', 'between:0,9000000000000000'],
        ] + ($order === null ? ['request_id' => ['required', 'uuid']] : ['revision' => ['required', 'string', 'size:64'], 'checkout_id' => ['required', 'uuid']]) + $this->orderRules());

        return DB::transaction(function () use ($request, $order, $body): JsonResponse {
            $drawer = PosCashDrawer::lock();
            $user = $request->user();
            $checkoutId = $body['checkout_id'] ?? $body['request_id'];
            $fingerprint = $this->fingerprint('checkout', $body);
            if ($order !== null) {
                $saved = PosOrder::query()->lockForUpdate()->findOrFail($order);
            } else {
                User::query()->lockForUpdate()->findOrFail($user->id);
                $identity = ['user_id' => $user->id, 'request_id' => $body['request_id']];
                $saved = PosOrder::where($identity)->lockForUpdate()->first();
            }

            if ($saved?->finalized_at) {
                // Older receipts have no fingerprint or tender; do not invent either.
                $original = $saved->checkout_fingerprint ?? $this->fingerprint('checkout', $saved->toArray() + [
                    'revision' => $body['revision'] ?? null,
                ]);
                return $saved->checkout_id === $checkoutId && hash_equals($original, $fingerprint)
                    ? response()->json($this->detail($saved))
                    : $this->submissionConflict($saved);
            }
            // Accept only exact replays of old non-cash receipts, never a new non-cash sale.
            if ($body['payment_method'] !== 'cash') {
                throw ValidationException::withMessages(['payment_method' => 'Only cash is accepted for new sales.']);
            }
            if ($order !== null && ! hash_equals($this->revision($saved), $body['revision'])) {
                return response()->json(['message' => 'This order was changed elsewhere. Reopen it before finalizing.'], 409);
            }
            if ($order === null && $saved !== null) {
                return $this->submissionConflict($saved);
            }

            $cashSession = PosCashDrawer::requireSession($drawer, isset($body['cash_session_id']) ? (int) $body['cash_session_id'] : null);
            if ($saved === null) {
                $saved = new PosOrder($identity + ['cashier' => $user->name] + $this->orderAttributes($body));
                PosOrderNumber::assign($saved);
                $saved->save();
            }
            $attributes = $order === null ? ['lines' => $saved->lines, 'total_minor' => $saved->total_minor] : $this->orderAttributes($body, $saved);
            $received = (int) $body['cash_received_minor'];
            if ($received < $attributes['total_minor']) {
                throw ValidationException::withMessages(['cash_received_minor' => 'Cash received must cover the sale total.']);
            }
            $products = PosProduct::with('unit')->whereIn('sku', array_column($attributes['lines'], 'sku'))
                ->where('is_active', true)->orderBy('id')->lockForUpdate()->get()->keyBy('sku');
            foreach ($attributes['lines'] as $index => $line) {
                $product = $products->get($line['sku']);
                if (! $product || $product->unit->code !== $line['unit']) {
                    throw ValidationException::withMessages(['lines' => "{$line['name']} is unavailable or its unit changed. Remove it and add the current item before finalizing."]);
                }
                $attributes['lines'][$index]['quantity_precision'] = $product->unit->quantity_precision;
                PosInventory::move($product, $user, 'sale', -(int) round($line['qty'] * 1000), 'Finalized sale', ['order_id' => $saved->id]);
            }

            $saved->fill($attributes)->forceFill([
                'finalized_at' => now(), 'checkout_id' => $checkoutId, 'payment_method' => $body['payment_method'], 'finalized_by' => $user->id,
                'receipt_store' => PosStoreProfile::branding(),
                'cash_session_id' => $cashSession, 'finalized_cashier' => $user->name, 'cash_received_minor' => $received, 'checkout_fingerprint' => $fingerprint,
            ])->save();

            PosCashDrawer::add($cashSession, 'sales_minor', $saved->total_minor);

            return response()->json($this->detail($saved), $order === null ? 201 : 200);
        }, 3);
    }

    private function submissionConflict(PosOrder $order): JsonResponse
    {
        return response()->json(['message' => "Order {$order->reference} already has a different submission. Check it in Orders before retrying."], 409);
    }

    private function fingerprint(string $operation, array $body): string
    {
        $submission = [
            $operation, $body['revision'] ?? null, $body['customer'] ?? null, $body['note'] ?? null,
            $body['payment_method'] ?? null, isset($body['cash_received_minor']) ? (int) $body['cash_received_minor'] : null,
            array_map(fn (array $line) => [
                $line['sku'], (int) round($line['qty'] * 1000), (int) $line['price_minor'], (int) round($line['discount'] * 100),
            ], $body['lines']),
        ];
        // Null must not alter fingerprints on orders finalized before drawer tracking.
        if (isset($body['cash_session_id'])) {
            $submission[] = (int) $body['cash_session_id'];
        }

        if (isset($body['customer_id'])) {
            $submission['customer_id'] = (int) $body['customer_id'];
        }

        return hash('sha256', json_encode($submission, JSON_THROW_ON_ERROR));
    }

    private function detail(PosOrder $order): array
    {
        return $order->toArray() + [
            'revision' => $this->revision($order),
            'finalized_at' => null,
            'payment_method' => null,
            'cash_received_minor' => null,
            'change_minor' => $order->cash_received_minor === null ? null : $order->cash_received_minor - $order->total_minor,
        ];
    }

    private function revision(PosOrder $order): string
    {
        $revision = [
            $order->customer, $order->note, array_map(function (array $line): array {
                ksort($line);

                return $line;
            }, $order->lines),
        ];
        if ($order->customer_id !== null) {
            $revision['customer_id'] = $order->customer_id;
        }

        return hash('sha256', json_encode($revision, JSON_THROW_ON_ERROR));
    }

    private function orderRules(): array
    {
        return [
            'customer' => ['nullable', 'string', 'max:120'],
            'customer_id' => ['nullable', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:160'],
            'lines' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'lines.*' => ['required', 'array:sku,qty,price_minor,discount'],
            'lines.*.sku' => ['required', 'string', 'max:40', 'distinct'],
            'lines.*.qty' => ['required', 'numeric', 'decimal:0,3', 'min:0.001', 'max:9999'],
            'lines.*.price_minor' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'lines.*.discount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
        ];
    }

    private function orderAttributes(array $body, ?PosOrder $previous = null): array
    {
        $previousBySku = array_column($previous?->lines ?? [], null, 'sku');
        $products = PosProduct::query()->with('unit')->where('is_active', true)
            ->whereIn('sku', array_column($body['lines'], 'sku'))->get()->keyBy('sku');
        $lines = [];
        foreach ($body['lines'] as $index => $line) {
            $old = $previousBySku[$line['sku']] ?? null;
            if ($old && (float) $old['qty'] === (float) $line['qty']
                && (int) $old['price_minor'] === (int) $line['price_minor']
                && (float) $old['discount'] === (float) $line['discount']) {
                $lines[] = $old;

                continue;
            }
            $product = $products->get($line['sku']);
            if (! $product) {
                throw ValidationException::withMessages(["lines.$index.sku" => 'A product is no longer available. Remove it and try again.']);
            }
            $qty = (int) round($line['qty'] * 1000);
            if ($qty % (10 ** (3 - $product->unit->quantity_precision)) !== 0) {
                throw ValidationException::withMessages(["lines.$index.qty" => "Check the quantity for {$product->name}."]);
            }
            $discount = (int) round($line['discount'] * 100);
            // Price overrides are allowed in this POS; the server calculates the actual total.
            $scaledPrice = $qty * (int) $line['price_minor'];
            $factor = 10000 - $discount;
            // Split the division so large orders do not overflow PHP integers.
            $amount = intdiv($scaledPrice, 10000000) * $factor
                + intdiv(($scaledPrice % 10000000) * $factor + 5000000, 10000000);
            $lines[] = [
                'sku' => $product->sku,
                'name' => $product->name,
                'unit' => $product->unit->code,
                'qty' => $qty / 1000,
                'price_minor' => (int) $line['price_minor'],
                'discount' => $discount / 100,
                'total_minor' => $amount,
            ];
        }

        return $this->customerAttributes($body, $previous) + Arr::only($body, ['note']) + [
            'line_count' => count($lines),
            'total_minor' => array_sum(array_column($lines, 'total_minor')),
            'lines' => $lines,
        ];
    }

    private function customerAttributes(array $body, ?PosOrder $previous): array
    {
        if (isset($body['customer_id'])) {
            $id = (int) $body['customer_id'];
            if ($id === $previous?->customer_id) {
                // A draft keeps its saved snapshot, even if the directory entry was archived.
                return ['customer_id' => $id, 'customer' => $previous->customer];
            }

            $record = PosCustomer::query()->sharedLock()->find($id);
            if (!$record) {
                throw ValidationException::withMessages(['customer_id' => 'This customer is no longer available. Choose another customer or clear the selection.']);
            }

            return ['customer_id' => $record->id, 'customer' => $record->name];
        }

        // Older clients and old parked orders can still carry a name with no directory link.
        if (!array_key_exists('customer_id', $body)
            && (!array_key_exists('customer', $body) || $body['customer'] === $previous?->customer)) {
            return [];
        }

        return ['customer_id' => null, 'customer' => $body['customer'] ?? null];
    }
}
