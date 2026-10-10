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

    /**
     * The curved ribbon in the sheet's top-left corner: two overlapping
     * sweeps on a transparent ground.
     *
     * @param  string  $front  the nearer sweep, `#rrggbb`
     * @param  string  $back  the farther, paler sweep, `#rrggbb`
     */
    public function swoosh(string $front, string $back): ?string
    {
        return $this->cache["swoosh-{$front}-{$back}"] ??= $this->draw(600, 240, function (\GdImage $image, int $w, int $h) use ($front, $back): void {
            foreach ([[$back, 0.70, 0.98], [$front, 0.52, 0.74]] as [$colour, $reach, $drop]) {
                $points = [0, 0];

                // A quadratic curve from the top edge round to the left edge.
                for ($i = 0; $i <= 48; $i++) {
                    $t = $i / 48;
                    [$x0, $y0] = [$w * $reach, 0];
                    [$cx, $cy] = [$w * $reach * 0.42, $h * $drop * 0.30];
                    [$x2, $y2] = [0, $h * $drop];

                    $points[] = (int) round((1 - $t) ** 2 * $x0 + 2 * (1 - $t) * $t * $cx + $t ** 2 * $x2);
                    $points[] = (int) round((1 - $t) ** 2 * $y0 + 2 * (1 - $t) * $t * $cy + $t ** 2 * $y2);
                }

                imagefilledpolygon($image, $points, $this->allocate($image, $colour));
            }
        });
    }

    /**
     * The footer band: a pale ground with a wave rising out of each bottom
     * corner and a soft swell under the badge row.
     */
    public function footerWaves(string $base, string $swell, string $deep, string $fresh, string $light): ?string
    {
        return $this->cache["footer-{$base}-{$swell}-{$deep}-{$fresh}-{$light}"] ??= $this->draw(1200, 300, function (\GdImage $image, int $w, int $h) use ($base, $swell, $deep, $fresh, $light): void {
            imagefill($image, 0, 0, $this->allocate($image, $base));

            $baseline = $h * 0.46;

            // The swell the badges sit on.
            $points = [];
            for ($x = 0; $x <= $w; $x += 10) {
                $points[] = $x;
                $points[] = (int) round($baseline + sin($x / $w * M_PI * 2.2 + 0.6) * $h * 0.035);
            }
            array_push($points, $w, $h, 0, $h);
            imagefilledpolygon($image, $points, $this->allocate($image, $swell));

            // [colour, height above the baseline, how far in, mirrored]
            foreach ([
                [$fresh, 0.50, 0.30, false],
                [$deep, 0.42, 0.26, false],
                [$light, 0.48, 0.29, true],
                [$fresh, 0.36, 0.22, true],
            ] as [$colour, $rise, $reach, $mirror]) {
                $points = [];
                $span = $w * $reach;

                for ($i = 0; $i <= 48; $i++) {
                    $u = $i / 48;
                    $x = $span * $u;
                    // Highest just inside the edge, easing down to the baseline.
                    $y = $baseline - $h * $rise * (0.5 + 0.5 * cos(M_PI * $u)) * (0.72 + 0.28 * sin(M_PI * min(1, $u * 1.6)));

                    $points[] = (int) round($mirror ? $w - $x : $x);
                    $points[] = (int) round($y);
                }

                // Closed well below the baseline so no seam shows above the swell.
                $floor = (int) round($baseline + $h * 0.04);
                array_push($points, $mirror ? $w - (int) $span : (int) $span, $floor, $mirror ? $w : 0, $floor);
                imagefilledpolygon($image, $points, $this->allocate($image, $colour));
            }
        }, false);
    }

    /**
     * A stand-in mark for a clinic that has not uploaded a logo: a rounded
     * cross with a leaf across its lower arm.
     */
    public function mark(string $cross, string $leaf): ?string
    {
        return $this->cache["mark-{$cross}-{$leaf}"] ??= $this->draw(300, 300, function (\GdImage $image, int $w, int $h) use ($cross, $leaf): void {
            $ink = $this->allocate($image, $cross);
            $arm = (int) round($w * 0.30);
            $mid = (int) round($w / 2);
            $r = (int) round($arm * 0.22);

            foreach ([
                [$mid - $arm / 2, $w * 0.08, $mid + $arm / 2, $w * 0.92],
                [$w * 0.08, $mid - $arm / 2, $w * 0.92, $mid + $arm / 2],
            ] as [$x1, $y1, $x2, $y2]) {
                [$x1, $y1, $x2, $y2] = array_map(fn ($v) => (int) round($v), [$x1, $y1, $x2, $y2]);
                imagefilledrectangle($image, $x1 + $r, $y1, $x2 - $r, $y2, $ink);
                imagefilledrectangle($image, $x1, $y1 + $r, $x2, $y2 - $r, $ink);
                foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
                    imagefilledellipse($image, $cx, $cy, $r * 2, $r * 2, $ink);
                }
            }

            // The leaf: two arcs meeting at a point, leaning across the
            // lower-right of the cross.
            $green = $this->allocate($image, $leaf);
            $points = [];
            [$ax, $ay, $bx, $by] = [$w * 0.40, $h * 0.92, $w * 0.86, $h * 0.40];
            $len = hypot($bx - $ax, $by - $ay);
            [$nx, $ny] = [($ay - $by) / $len, ($bx - $ax) / $len];

            foreach ([1, -1] as $side) {
                for ($i = 0; $i <= 32; $i++) {
                    $t = $side === 1 ? $i / 32 : 1 - $i / 32;
                    $bulge = sin(M_PI * $t) * $len * 0.24 * $side;
                    $points[] = (int) round($ax + ($bx - $ax) * $t + $nx * $bulge);
                    $points[] = (int) round($ay + ($by - $ay) * $t + $ny * $bulge);
                }
            }
            imagefilledpolygon($image, $points, $green);
            imagesetthickness($image, 5);
            imageline($image, (int) $ax, (int) $ay, (int) ($ax + ($bx - $ax) * 0.82), (int) ($ay + ($by - $ay) * 0.82), $this->allocate($image, '#ffffff', 40));
        });
    }

    /**
     * Draws at three times the size and scales down — GD's polygons are not
     * anti-aliased, and the curves would otherwise print as stair-steps.
     *
     * @param  \Closure(\GdImage, int, int): void  $paint
     */
    private function draw(int $width, int $height, \Closure $paint, bool $transparent = true): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }

        try {
            $scale = 3;
            $big = imagecreatetruecolor($width * $scale, $height * $scale);

            if ($transparent) {
                imagealphablending($big, false);
                imagefill($big, 0, 0, imagecolorallocatealpha($big, 255, 255, 255, 127));
                imagealphablending($big, true);
            }

            $paint($big, $width * $scale, $height * $scale);

            $small = imagecreatetruecolor($width, $height);

            if ($transparent) {
                imagealphablending($small, false);
                imagesavealpha($small, true);
                imagefill($small, 0, 0, imagecolorallocatealpha($small, 255, 255, 255, 127));
            }

            imagecopyresampled($small, $big, 0, 0, 0, 0, $width, $height, $width * $scale, $height * $scale);

            return $this->encode($small);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    private function allocate(\GdImage $image, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');

        return imagecolorallocatealpha($image, (int) $r, (int) $g, (int) $b, $alpha);
    }

    private function encode(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }
}
