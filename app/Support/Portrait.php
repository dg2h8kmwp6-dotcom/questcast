<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a phone selfie into a small square avatar.
 *
 * Phone cameras hand us 3-12 megapixel images, often rotated, and these are
 * only ever drawn at 40-80 pixels. Everything is cropped square, downscaled
 * and re-encoded as JPEG on the way in, so what we store is a few kilobytes
 * and we never ship a multi-megabyte original to a projector.
 */
class Portrait
{
    public const SIZE = 256;

    /** @return string path on the disk */
    public static function store(UploadedFile $file, ?string $disk = null): string
    {
        $disk ??= config('questcast.portraits.disk');

        $source = self::read($file);
        $square = self::squareCrop($source, self::SIZE);
        imagedestroy($source);

        ob_start();
        imagejpeg($square, null, 82);
        $jpeg = ob_get_clean();
        imagedestroy($square);

        $path = 'portraits/'.Str::random(40).'.jpg';

        Storage::disk($disk)->put($path, $jpeg, [
            'ContentType' => 'image/jpeg',
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return $path;
    }

    public static function delete(?string $path, ?string $disk = null): void
    {
        if (! $path) {
            return;
        }

        Storage::disk($disk ?? config('questcast.portraits.disk'))->delete($path);
    }

    /** @return \GdImage */
    private static function read(UploadedFile $file)
    {
        $path = $file->getRealPath();
        $info = @getimagesize($path);

        $image = match ($info['mime'] ?? null) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image) {
            throw new RuntimeException('That photo is in a format we cannot read. Try again, or skip it.');
        }

        return self::applyOrientation($image, $path, $info['mime']);
    }

    /**
     * Phones record rotation in EXIF rather than rotating the pixels, so a
     * portrait selfie arrives on its side unless we straighten it here.
     */
    private static function applyOrientation($image, string $path, string $mime)
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = (int) ($exif['Orientation'] ?? 1);

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        if (! $rotated) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    /** Centre-crop to a square, then scale to the target size. */
    private static function squareCrop($source, int $size)
    {
        $w = imagesx($source);
        $h = imagesy($source);
        $edge = min($w, $h);

        // Faces sit above centre in a selfie, so bias the crop upward a little.
        $x = (int) (($w - $edge) / 2);
        $y = (int) max(0, ($h - $edge) / 2 - $edge * 0.06);

        $out = imagecreatetruecolor($size, $size);
        imagecopyresampled($out, $source, 0, 0, $x, $y, $size, $size, $edge, $edge);

        return $out;
    }
}
