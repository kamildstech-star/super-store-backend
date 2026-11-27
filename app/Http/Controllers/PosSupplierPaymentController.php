<?php

namespace App\Http\Controllers;

use App\Models\PosPurchaseReceipt;
use App\Models\PosSupplier;
use App\Models\PosSupplierPayment;
use App\Services\PosCashDrawer;
use App\Services\PosListing;
use App\Services\PosSupplierPayments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PosSupplierPaymentController extends Controller
{
    public function index(Request $request, int $receipt): JsonResponse
    {
        PosPurchaseReceipt::findOrFail($receipt);

        return PosListing::response(PosSupplierPayment::where('receipt_id', $receipt)->orderByDesc('id'), PosListing::filters($request))
            ->header('Cache-Control', 'no-store');
    }

    public function supplier(Request $request, int $supplier): JsonResponse
    {
        $record = PosSupplier::findOrFail($supplier);
        $receipts = PosPurchaseReceipt::where('supplier_id', $supplier);
        $tracked = (clone $receipts)->where('payment_tracking', true);
        $summary = $tracked->selectRaw('COUNT(*) AS receipt_count, SUM(total_minor) AS total_minor, SUM(paid_minor) AS paid_minor, SUM(total_minor - paid_minor) AS balance_minor')->first();
        $response = PosListing::response(PosSupplierPayment::whereIn('receipt_id', $receipts->select('id'))->orderByDesc('id'), PosListing::filters($request));

        return response()->json([
            'supplier' => $record->only(['id', 'name']),
            'summary' => [
                'tracked_receipts' => (int) $summary->receipt_count,
                'untracked_receipts' => PosPurchaseReceipt::where('supplier_id', $supplier)->where('payment_tracking', false)->count(),
                'total_minor' => (string) ($summary->getRawOriginal('total_minor') ?? '0'),
                'paid_minor' => (string) ($summary->getRawOriginal('paid_minor') ?? '0'),
                'balance_minor' => (string) ($summary->getRawOriginal('balance_minor') ?? '0'),
            ],
        ] + $response->getData(true))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, int $receipt): JsonResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'between:1,'.PosCashDrawer::MAX_CASH],
            'source' => ['required', 'in:external,drawer'],
        ] + PosSupplierPayments::rules());
        $payment = PosSupplierPayments::normalize($data);
        $key = strtolower($data['request_id']);
        $actor = $request->user();

        return DB::transaction(function () use ($receipt, $payment, $key, $actor): JsonResponse {
            $drawer = $payment['source'] === 'drawer' ? PosCashDrawer::lock() : null;
            $saved = PosPurchaseReceipt::lockForUpdate()->findOrFail($receipt);
            $past = PosSupplierPayment::where('receipt_id', $receipt)->where('request_id', $key)->first();
            if ($past) {
                abort_unless((int) $past->user_id === $actor->id && $past->only(array_keys($payment)) === $payment, 409,
                    'This supplier payment reference belongs to a different submission.');

                return response()->json($past);
            }

            return response()->json(PosSupplierPayments::record($saved, $actor, $key, $payment, $drawer), 201);
        }, 3);
    }
}
