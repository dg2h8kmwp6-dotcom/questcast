<?php

namespace App\Support;

use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\EyeFill;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/** Renders the join URL as an inline SVG, so phones can scan off the screen. */
class Qr
{
    public static function svg(string $url, int $size = 320): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($size, 1, null, null, Fill::withForegroundColor(
                new Rgb(255, 255, 255),      // background: drawn light on a dark screen
                new Rgb(10, 10, 12),
                new EyeFill(null, null),
                new EyeFill(null, null),
                new EyeFill(null, null),
            )),
            new SvgImageBackEnd,
        );

        // The SVG carries its own XML prolog, which cannot sit inside a page.
        return preg_replace('/<\?xml.*?\?>/', '', (new Writer($renderer))->writeString($url));
    }
}
