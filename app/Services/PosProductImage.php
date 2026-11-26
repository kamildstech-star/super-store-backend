<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PosProductImage
{
    public static function save(UploadedFile $image): string
    {
        $path = $image->store('pos-product-images', 'local');
        if ($path === false) {
            throw ValidationException::withMessages(['image' => 'The image could not be saved. Check that Laravel storage is writable.']);
        }

        return $path;
    }

    public static function remove(?string $path): void
    {
        if (!$path) {
            return;
        }

        try {
            if (!Storage::disk('local')->delete($path)) {
                report(new \RuntimeException('An unused POS product image could not be deleted.'));
            }
        } catch (Throwable $error) {
            // A cleanup problem must not turn an already committed product edit into a failed response.
            report($error);
        }
    }
}
