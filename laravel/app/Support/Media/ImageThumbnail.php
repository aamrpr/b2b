<?php

namespace App\Support\Media;

use Illuminate\Support\Facades\Storage;

/**
 * مصغّر صور المنتجات والأصناف.
 *
 * بطاقة السوق ارتفاعها نحو ١٥٠ بكسل، والأصل قد يتجاوز ميغابايت.
 * المصغّر نسخة WebP لا يزيد أطول ضلع فيها على ٦٤٠ بكسل،
 * والأصل يبقى للعرض الكبير.
 */
class ImageThumbnail
{
    public const MAX_EDGE = 640;

    public const QUALITY = 75;

    public static function relative(string $path): string
    {
        $directory = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);
        $prefix = ($directory === '' || $directory === '.') ? '' : $directory.'/';

        return $prefix.'thumbs/'.$name.'.webp';
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $thumb = self::relative($path);

        return Storage::disk('public')->exists($thumb)
            ? asset('storage/'.$thumb)
            : null;
    }

    public static function forget(?string $path): void
    {
        if (! $path) {
            return;
        }

        $thumb = self::relative($path);

        if (Storage::disk('public')->exists($thumb)) {
            Storage::disk('public')->delete($thumb);
        }
    }

    /** يكتب المصغّر بجانب الأصل. يعيد false إن تعذّر ذلك دون أن يُسقط الرفع. */
    public static function make(string $path, bool $force = false): bool
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path) || ! function_exists('imagewebp')) {
            return false;
        }

        $thumb = self::relative($path);

        if (! $force && $disk->exists($thumb)) {
            return true;
        }

        $image = self::open($disk->path($path));

        if (! $image) {
            return false;
        }

        $image = self::orient($image, $disk->path($path));
        $resized = self::fit($image);

        if ($resized !== $image) {
            imagedestroy($image);
        }

        $target = $disk->path($thumb);
        $directory = dirname($target);

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            imagedestroy($resized);

            return false;
        }

        $written = imagewebp($resized, $target, self::QUALITY);
        imagedestroy($resized);

        if ($written) {
            @chmod($target, 0644);
        }

        return $written;
    }

    private static function open(string $source): ?\GdImage
    {
        $info = @getimagesize($source);

        if (! $info) {
            return null;
        }

        $image = match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG => @imagecreatefrompng($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            IMAGETYPE_GIF => @imagecreatefromgif($source),
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }

    private static function orient(\GdImage $image, string $source): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($source);
        $orientation = (int) ($exif['Orientation'] ?? 0);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        if ($rotated instanceof \GdImage && $rotated !== $image) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private static function fit(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $edge = max($width, $height);

        if ($edge <= self::MAX_EDGE) {
            return $image;
        }

        $scale = self::MAX_EDGE / $edge;
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $clear = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $clear);
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $canvas;
    }
}
