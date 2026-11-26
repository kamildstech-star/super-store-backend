<?php

namespace App\Http\Controllers;

use App\Models\PosProduct;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PosProductImageController extends Controller
{
    public function __invoke(PosProduct $product, string $filename): BinaryFileResponse
    {
        $path = $product->image_path;
        abort_unless($path === 'pos-product-images/'.$filename && Storage::disk('local')->exists($path), 404);

        $mime = match (pathinfo($filename, PATHINFO_EXTENSION)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
        };

        return new BinaryFileResponse(Storage::disk('local')->path($path), 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ], public: false);
    }
}
