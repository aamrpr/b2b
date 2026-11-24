<?php

namespace App\Console\Commands;

use App\Models\Catalog\Category;
use App\Models\Catalog\ProductMedia;
use App\Support\Media\ImageThumbnail;
use Illuminate\Console\Command;

/** يبني مصغّرات صور المنتجات والأصناف الموجودة حتى تَخفّ بطاقات السوق دون انتظار رفعٍ جديد. */
class MakeProductThumbnails extends Command
{
    protected $signature = 'media:thumbs {--force : أعد بناء المصغّرات حتى الموجودة}';

    protected $description = 'بناء مصغّرات WebP لصور المنتجات والأصناف';

    public function handle(): int
    {
        $paths = ProductMedia::query()
            ->where('type', 'image')
            ->orderBy('id')
            ->pluck('path')
            ->merge(
                Category::query()->whereNotNull('image_path')->orderBy('id')->pluck('image_path')
            );

        $built = 0;
        $failed = 0;

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '') {
                continue;
            }

            if (ImageThumbnail::make($path, (bool) $this->option('force'))) {
                $built++;

                continue;
            }

            $failed++;
            $this->warn($path);
        }

        $this->info("مصغّرات جاهزة: {$built}".($failed ? " — تعذّر: {$failed}" : ''));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
