<?php

namespace Database\Seeders;

use App\Models\PosCategory;
use App\Models\PosProduct;
use App\Models\PosUnit;
use App\Services\PosProductCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PosCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $categories = [];
            foreach ([
                ['drinks', 'Beverages', 'drink', '#568eab'],
                ['snacks', 'Snacks', 'snack', '#bd8548'],
                ['grocery', 'Grocery', 'pantry', '#aa6d92'],
                ['produce', 'Fresh Produce', 'produce', '#699177'],
            ] as $order => [$slug, $name, $icon, $color]) {
                $categories[$slug] = PosCategory::updateOrCreate(['slug' => $slug], [
                    'name' => $name, 'icon' => $icon, 'color' => $color, 'sort_order' => $order,
                ])->id;
            }

            $units = [];
            foreach ([
                ['piece', 'Piece', 0],
                ['pack', 'Pack', 0],
                ['kg', 'Kilogram', 3],
                ['bottle', 'Bottle', 0],
                ['box', 'Box', 0],
                ['dozen', 'Dozen', 0],
            ] as [$code, $name, $precision]) {
                $units[$code] = PosUnit::updateOrCreate(['code' => $code], [
                    'name' => $name, 'quantity_precision' => $precision,
                ])->id;
            }

            foreach ([
                ['MIN-01','Mineral Water','drinks','piece',60,'bottle','#5bb4e6','#e3f1f7','PURE'],
                ['COL-02','Cola Can','drinks','piece',110,'can','#b8293b','#f5e8eb','COLA'],
                ['MAN-03','Mango Juice','drinks','pack',125,'carton','#d78626','#f9efdb','MANGO'],
                ['MKT-04','Milk 1 L','drinks','pack',280,'carton','#5694bf','#e9f0f7','MILK'],
                ['CHP-05','Salted Chips','snacks','pack',95,'bag','#d8942d','#fbf2df','CHIPS'],
                ['BIS-06','Chocolate Biscuits','snacks','pack',150,'bag','#8c4f3b','#f6ebe5','CHOCO'],
                ['NUT-07','Mixed Nuts','snacks','pack',260,'bag','#a16645','#f5ede3','NUTS'],
                ['POP-08','Caramel Popcorn','snacks','pack',180,'bag','#b6627a','#f8eaf0','POP'],
                ['RIC-09','Basmati Rice','grocery','kg',340,'bag','#739059','#edf1e6','RICE'],
                ['FLR-10','Wheat Flour','grocery','kg',165,'bag','#bf8b58','#f6eee1','FLOUR'],
                ['OIL-11','Cooking Oil 1 L','grocery','bottle',540,'bottle','#d09a39','#f8f0dd','OIL'],
                ['TEA-12','Black Tea','grocery','box',420,'box','#367c79','#e8f2ed','TEA'],
                ['APL-13','Red Apples','produce','kg',320,'apple','#bf374a','#faeaeb',''],
                ['BAN-14','Bananas','produce','dozen',230,'banana','#d7a833','#faf2dd',''],
                ['TOM-15','Tomatoes','produce','kg',190,'tomato','#d64a3d','#f8eae4',''],
                ['ORA-16','Oranges','produce','kg',245,'orange','#e29335','#faecdd',''],
            ] as $order => [$sku, $name, $category, $unit, $rupees, $shape, $ink, $tone, $label]) {
                if (PosProduct::where('sku', $sku)->exists()) {
                    continue;
                }
                PosProduct::create([
                    'sku' => $sku, 'internal_code' => app(PosProductCode::class)->next(),
                    'barcode' => sprintf('DEMO-%04d', $order + 1),
                    'name' => $name, 'category_id' => $categories[$category], 'unit_id' => $units[$unit],
                    'price_minor' => $rupees * 100, 'retail_price_minor' => $rupees * 100, 'is_active' => true, 'sort_order' => $order,
                    'art_shape' => $shape, 'art_ink' => $ink, 'art_tone' => $tone, 'art_label' => $label,
                ]);
            }
        });
    }
}
