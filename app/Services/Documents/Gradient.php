<?php

namespace App\Services\Documents;

/**
 * The background art a document is printed on, as data URIs.
 *
 * dompdf DOES NOT SUPPORT `linear-gradient()`, and cannot draw a shape at all.
 * Worse than not supporting it: a block whose only background was a gradient
 * rendered with no background whatsoever — white text on white paper, which on
 * the title band of a bill is the whole heading gone. A background IMAGE it
 * handles perfectly, including clipping to a border-radius, so anything this
 * layout cannot get from a solid colour is drawn here with GD and stretched
 * with `background-size: 100% 100%`.
 *
 * Two pieces: the ramp across the title band, and the wave under the footer.
 *
 * NEVER THROWS, and callers pair every one of these with a solid
 * `background-color`. GD is present everywhere this runs, but a bill that
 * fails to print because its footer could not be decorated is not a trade
 * anybody would make.
 */
class Gradient
{
    /** 64 steps: the band is 80mm to 190mm wide and the eye finds no seam at
        that width, while a larger strip is bytes on every page for nothing. */
    private const STEPS = 64;

    /** @var array<string, string|null> built images, by their arguments */
    private array $cache = [];

    /**
     * @param  string  $from  `#rrggbb`
     * @param  string  $to  `#rrggbb`
     */
    public function dataUri(string $from, string $to): ?string
    {
        return $this->cache["{$from}-{$to}"] ??= $this->build($from, $to);
    }

    private function build(string $from, string $to): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        try {
            [$r1, $g1, $b1] = sscanf($from, '#%02x%02x%02x');
            [$r2, $g2, $b2] = sscanf($to, '#%02x%02x%02x');

            $image = imagecreatetruecolor(self::STEPS, 1);

            for ($x = 0; $x < self::STEPS; $x++) {
                $t = $x / (self::STEPS - 1);

                imagesetpixel($image, $x, 0, imagecolorallocate(
                    $image,
                    (int) round($r1 + ($r2 - $r1) * $t),
                    (int) round($g1 + ($g2 - $g1) * $t),
                    (int) round($b1 + ($b2 - $b1) * $t),
                ));
            }

            return $this->encode($image);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * The soft swell under the footer, the way a printed bill's does.
     *
     * TWO CURVES, not one, and both cropped by the bottom edge. A single arc
     * across the whole width reads as a graph; two of different periods
     * overlapping reads as the watermark it is meant to be, and the eye stops
     * trying to find meaning in it.
     *
     * Drawn wide and stretched to whatever the band turns out to be, so the
     * curve's proportions follow the paper rather than repeating across it —
     * a tiled wave shows its seam at every repeat.
     *
     * @param  string  $base  the band's own background, `#rrggbb`
     * @param  string  $swell  the curve, a shade nearer the accent
     */
    public function wave(string $base, string $swell): ?string
    {
        return $this->cache["wave-{$base}-{$swell}"] ??= $this->buildWave($base, $swell);
    }

    private function buildWave(string $base, string $swell): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        try {
            $width = 480;
            $height = 64;

            $image = imagecreatetruecolor($width, $height);

            [$r, $g, $b] = sscanf($base, '#%02x%02x%02x');
            imagefill($image, 0, 0, imagecolorallocate($image, (int) $r, (int) $g, (int) $b));

            [$r, $g, $b] = sscanf($swell, '#%02x%02x%02x');

            /* The nearer curve solid, the farther one half-mixed into the
               base, so they read as depth rather than as two stripes. */
            foreach ([
                ['period' => 1.0, 'phase' => 0.0, 'top' => 0.78, 'depth' => 0.13, 'alpha' => 0],
                ['period' => 1.7, 'phase' => 2.2, 'top' => 0.64, 'depth' => 0.10, 'alpha' => 60],
            ] as $curve) {
                $colour = imagecolorallocatealpha(
                    $image, (int) $r, (int) $g, (int) $b, $curve['alpha'],
                );

                $points = [];

                for ($x = 0; $x <= $width; $x += 6) {
                    $t = ($x / $width) * M_PI * 2 * $curve['period'] + $curve['phase'];
                    $points[] = $x;
                    $points[] = (int) round($height * ($curve['top'] + sin($t) * $curve['depth']));
                }

                // Close the shape against the bottom edge.
                array_push($points, $width, $height, 0, $height);

                imagefilledpolygon($image, $points, $colour);
            }

            return $this->encode($image);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function encode(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
