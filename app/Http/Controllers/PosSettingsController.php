<?php

namespace App\Http\Controllers;

use App\Services\PosStoreProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class PosSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json(PosStoreProfile::settings())->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'not_regex:/[\r\n]/'],
            'address' => ['nullable', 'present', 'string', 'max:240'],
            'phone' => ['nullable', 'present', 'string', 'max:40', 'not_regex:/[\r\n]/'],
            'receipt_footer' => ['nullable', 'present', 'string', 'max:180'],
            'receipt_width_mm' => ['sometimes', 'required', 'integer', 'in:58,80'],
            'revision' => ['required', 'integer', 'min:1'],
            'currency' => ['prohibited'],
            'timezone' => ['prohibited'],
        ]);
        $revision = (int) $data['revision'];
        $width = isset($data['receipt_width_mm']) ? (int) $data['receipt_width_mm'] : null;
        $data = Arr::only($data, ['name', 'address', 'phone', 'receipt_footer']);

        return DB::transaction(function () use ($data, $revision, $width): JsonResponse {
            $row = PosStoreProfile::record(true);
            // An identical retry after a lost response must not increment the revision.
            $savedWidth = (int) $row->receipt_width_mm;
            if (PosStoreProfile::branding($row) === $data && ($width === null || $width === $savedWidth)) {
                return response()->json(PosStoreProfile::settings($row))->header('Cache-Control', 'no-store');
            }
            if ((int) $row->revision !== $revision) {
                return response()->json(['message' => 'Settings were changed in another session. Load the latest settings before saving again.'], 409)
                    ->header('Cache-Control', 'no-store');
            }

            DB::table('pos_store_settings')->where('id', 1)->update($data + ['revision' => $revision + 1, 'receipt_width_mm' => $width ?? $savedWidth]);

            return response()->json(PosStoreProfile::settings())->header('Cache-Control', 'no-store');
        }, 3);
    }
}
