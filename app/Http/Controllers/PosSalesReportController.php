<?php

namespace App\Http\Controllers;

use App\Models\PosOrder;
use App\Models\PosRefund;
use App\Services\PosListing;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PosSalesReportController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $filters = PosListing::filters($request);
        $dates = $request->validate([
            'period' => ['sometimes', 'in:today,yesterday,custom'],
            'from' => ['required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d'],
            'to' => ['required_if:period,custom', 'prohibited_unless:period,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'cashier_id' => ['sometimes', 'integer', 'min:1'],
            'kind' => ['sometimes', 'in:sales,refunds'],
        ]);

        $zone = 'Asia/Karachi';
        $today = CarbonImmutable::today($zone);
        $period = $dates['period'] ?? 'today';
        $start = match ($period) {
            'custom' => CarbonImmutable::createFromFormat('!Y-m-d', $dates['from'], $zone),
            'yesterday' => $today->subDay(),
            default => $today,
        };
        $end = $period === 'custom' ? CarbonImmutable::createFromFormat('!Y-m-d', $dates['to'], $zone) : $start;
        if ($end->greaterThan($start->addDays(365))) {
            throw ValidationException::withMessages(['to' => 'Choose a range of 366 days or less.']);
        }

        $page = (int) ($filters['page'] ?? 1);
        $size = (int) ($filters['per_page'] ?? 15);
        $offset = ($page - 1) * $size;
        $cashierId = isset($dates['cashier_id']) ? (int) $dates['cashier_id'] : null;
        $query = PosOrder::query()
            ->leftJoin('users as finalizer', 'finalizer.id', '=', 'pos_orders.finalized_by')
            ->where('pos_orders.finalized_at', '>=', $start->utc())
            ->where('pos_orders.finalized_at', '<', $end->addDay()->utc())
            ->orderByDesc('pos_orders.finalized_at')->orderByDesc('pos_orders.id')
            ->select([
                'pos_orders.id', 'pos_orders.finalized_at', 'pos_orders.finalized_by', 'pos_orders.finalized_cashier',
                'pos_orders.customer', 'pos_orders.line_count', 'pos_orders.total_minor', 'pos_orders.payment_method',
                'pos_orders.lines', 'finalizer.name as cashier_name',
            ]);

        $kind = $dates['kind'] ?? 'sales';
        $gross = $paid = $count = $refunded = $refundCount = 0;
        $payments = $cashiers = $rows = [];
        // Read the same finalized rows for summary, payment breakdown, and paginated detail.
        // Use immutable line snapshots; current prices/discounts must not change old reports.
        foreach ($query->cursor() as $order) {
            if ($order->finalized_by !== null) {
                $cashiers[$order->finalized_by] = [
                    'id' => (int) $order->finalized_by,
                    'name' => $order->cashier_name ?: ($order->finalized_cashier ?: 'Cashier #'.$order->finalized_by),
                ];
            }
            if ($cashierId !== null && (int) $order->finalized_by !== $cashierId) {
                continue;
            }

            $gross += array_reduce($order->lines, static function (int $total, array $line): int {
                $qty = (int) round($line['qty'] * 1000);

                return $total + intdiv($qty * (int) $line['price_minor'] + 500, 1000);
            }, 0);
            $paid += $order->total_minor;
            $method = $order->payment_method ?? 'unrecorded';
            $payments[$method] ??= ['method' => $method, 'total_minor' => 0, 'order_count' => 0];
            $payments[$method]['total_minor'] += $order->total_minor;
            $payments[$method]['order_count']++;
            if ($kind === 'sales' && $count >= $offset && count($rows) < $size) {
                $rows[] = [
                    'id' => $order->id,
                    'finalized_at' => $order->finalized_at,
                    'cashier' => $order->finalized_cashier ?: $order->cashier_name,
                    'customer' => $order->customer,
                    'line_count' => $order->line_count,
                    'total_minor' => (string) $order->total_minor,
                    'payment_method' => $method,
                ];
            }
            $count++;
        }

        // Refunds belong to the posting date and posting cashier, independently of the original sale.
        $refunds = PosRefund::query()
            ->where('posted_at', '>=', $start->utc())->where('posted_at', '<', $end->addDay()->utc())
            ->orderByDesc('posted_at')->orderByDesc('id')
            ->select(['id', 'posted_at', 'order_id', 'user_id', 'actor', 'total_minor']);
        foreach ($refunds->cursor() as $refund) {
            $cashiers[$refund->user_id] ??= ['id' => (int) $refund->user_id, 'name' => $refund->actor];
            if ($cashierId !== null && (int) $refund->user_id !== $cashierId) {
                continue;
            }
            $refunded += (int) $refund->total_minor;
            if ($kind === 'refunds' && $refundCount >= $offset && count($rows) < $size) {
                $rows[] = [
                    'id' => $refund->id, 'order_id' => $refund->order_id, 'posted_at' => $refund->posted_at,
                    'cashier' => $refund->actor, 'total_minor' => (string) $refund->total_minor,
                ];
            }
            $refundCount++;
        }
        $rowCount = $kind === 'refunds' ? $refundCount : $count;

        uasort($cashiers, static fn (array $a, array $b) => strnatcasecmp($a['name'], $b['name']) ?: ($a['id'] <=> $b['id']));
        ksort($payments);
        return response()->json([
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'timezone' => $zone],
            'summary' => [
                'gross_minor' => (string) $gross,
                'discount_minor' => (string) ($gross - $paid),
                'sales_after_discount_minor' => (string) $paid,
                'refunds_minor' => (string) $refunded,
                'net_sales_minor' => (string) ($paid - $refunded),
                'refund_count' => $refundCount,
                'order_count' => $count,
            ],
            'payments' => array_values(array_map(static function (array $payment): array {
                $payment['total_minor'] = (string) $payment['total_minor'];

                return $payment;
            }, $payments)),
            'cashiers' => array_values($cashiers),
            'data' => $rows,
            'meta' => [
                'page' => $page,
                'per_page' => $size,
                'last_page' => max(1, (int) ceil($rowCount / $size)),
                'total' => $rowCount,
                'from' => $rows ? $offset + 1 : null,
                'to' => $rows ? $offset + count($rows) : null,
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
