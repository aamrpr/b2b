<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * وحدات القياس ملك المنصة: قطعة، طقم، كيلو…
 * الرمز ثابت يُخزَّن مع المنتج، والاسم مترجم فيظهر بلغة القارئ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->json('name');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $names = [
            'piece' => ['قطعة', 'Piece'],
            'set' => ['طقم', 'Set'],
            'kg' => ['كيلوغرام', 'Kilogram'],
            'gram' => ['غرام', 'Gram'],
            'liter' => ['لتر', 'Liter'],
            'meter' => ['متر', 'Meter'],
            'carton' => ['كرتونة', 'Carton'],
            'box' => ['صندوق', 'Box'],
            'pair' => ['زوج', 'Pair'],
            'ton' => ['طن', 'Ton'],
            'dozen' => ['درزنة', 'Dozen'],
        ];

        $codes = array_keys($names);
        $aliases = [];

        foreach ($names as $code => [$ar, $en]) {
            $aliases[Str::lower($code)] = $code;
            $aliases[Str::lower($ar)] = $code;
            $aliases[Str::lower($en)] = $code;
        }

        foreach (['products' => 'unit_of_measure', 'rfq_items' => 'unit', 'quotation_items' => 'unit', 'order_items' => 'unit', 'purchase_order_items' => 'unit'] as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->distinct()->pluck($column) as $raw) {
                $raw = trim((string) $raw);
                $canonical = $aliases[Str::lower($raw)] ?? Str::lower($raw);

                if ($canonical === '' || mb_strlen($canonical) > 20) {
                    continue;
                }

                if (! in_array($canonical, $codes, true)) {
                    $codes[] = $canonical;
                }

                // «قطعة» المخزَّنة سابقاً تصبح الرمز piece، فيبقى الاسم قابلاً للترجمة
                if ($canonical !== $raw) {
                    DB::table($table)->where($column, $raw)->update([$column => $canonical]);
                }
            }
        }

        $now = now();

        foreach ($codes as $index => $code) {
            [$ar, $en] = $names[$code] ?? [$code, $code];

            DB::table('units')->insert([
                'code' => $code,
                'name' => json_encode(['ar' => $ar, 'en' => $en], JSON_UNESCAPED_UNICODE),
                'sort_order' => $index,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
