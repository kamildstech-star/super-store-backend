<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosProduct extends Model
{
    protected $fillable = [
        'sku', 'internal_code', 'barcode', 'supplier_id', 'category_id', 'unit_id', 'name', 'price_minor', 'retail_price_minor', 'image_path', 'is_active',
        'sort_order', 'art_shape', 'art_ink', 'art_tone', 'art_label',
    ];

    protected $hidden = ['image_path'];

    protected $appends = ['image_url'];

    public function getImageUrlAttribute(): ?string
    {
        $path = $this->attributes['image_path'] ?? null;

        return $path && $this->exists ? route('pos.product-image', [
            'product' => $this->getKey(), 'filename' => basename($path),
        ], false) : null;
    }

    protected function casts(): array
    {
        return ['price_minor' => 'integer', 'retail_price_minor' => 'integer', 'is_active' => 'boolean', 'stock_milli' => 'integer', 'low_stock_milli' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PosCategory::class, 'category_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(PosSupplier::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(PosUnit::class, 'unit_id');
    }
}
