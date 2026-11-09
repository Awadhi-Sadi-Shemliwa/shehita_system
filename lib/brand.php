<?php
/**
 * SHEHITA EMS — Brand colours derived from a company's logo
 *
 * When a company signs up they upload a logo. Rather than asking them to pick
 * hex codes, we read the dominant brand colour straight out of that image and
 * regenerate the whole UI palette from it.
 *
 * This works because the existing stylesheets already route ~296 of their
 * colour references through var(--brown-900 ... --brown-100). Replace those
 * nine values and the entire system re-themes.
 *
 * Requires the GD extension (added to the Dockerfile alongside mysqli).
 */

/** The nine ramp steps, and how light each one should be (HSL lightness). */
const BRAND_RAMP_LIGHTNESS = [
    900 => 0.14,
    800 => 0.22,
    700 => 0.32,
    600 => 0.44,
    500 => 0.58,
    400 => 0.72,
    300 => 0.84,
    200 => 0.92,
    100 => 0.965,
];

/**
 * Pale steps get their saturation pulled back, otherwise a vivid brand colour
 * produces neon-looking page backgrounds.
 */
const BRAND_RAMP_SATURATION_SCALE = [
    900 => 1.00, 800 => 1.00, 700 => 0.95, 600 => 0.88, 500 => 0.80,
    400 => 0.68, 300 => 0.52, 200 => 0.40, 100 => 0.30,
];

/** SHEHITA's own brown, used when a logo yields nothing usable. */
const BRAND_DEFAULT_PRIMARY = '#7b583f';

/**
 * Read the dominant brand colour out of a logo file.
 *
 * The interesting part is what gets thrown away. Most logos sit on a white or
 * transparent background and contain black text, so the literal "most common
 * colour" is almost always white or black -- useless as a brand colour. We
 * discard near-white, near-black and near-grey pixels first, then pick the
 * heaviest remaining hue.
 *
 * @return string|null Hex like "#7d5419", or null when the image has no
 *                     usable colour (a pure black-and-white logo).
 */
function extract_brand_primary(string $imagePath): ?string
{
    $image = load_image_any($imagePath);
    if ($image === null) {
        return null;
    }

    $width  = imagesx($image);
    $height = imagesy($image);
    if ($width < 1 || $height < 1) {
        imagedestroy($image);
        return null;
    }

    // Downscale so a 3000px logo costs the same as a 300px one.
    $maxDim = 100;
    if ($width > $maxDim || $height > $maxDim) {
        $scale     = $maxDim / max($width, $height);
        $newWidth  = max(1, (int)round($width * $scale));
        $newHeight = max(1, (int)round($height * $scale));

        $small = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($small, false);
        imagesavealpha($small, true);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        imagedestroy($image);
        $image  = $small;
        $width  = $newWidth;
        $height = $newHeight;
    }

    // 24 buckets of 15 degrees each.
    $buckets = [];

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $rgba  = imagecolorat($image, $x, $y);
            $alpha = ($rgba >> 24) & 0x7F;   // 0 = opaque, 127 = fully transparent
            if ($alpha > 64) {
                continue;
            }

            $r = ($rgba >> 16) & 0xFF;
            $g = ($rgba >> 8) & 0xFF;
            $b = $rgba & 0xFF;

            [$h, $s, $l] = rgb_to_hsl($r, $g, $b);

            // Discard the background and the text, keep the brand.
            if ($l > 0.93 || $l < 0.07 || $s < 0.12) {
                continue;
            }

            $bucket = (int)floor($h / 15) % 24;
            if (!isset($buckets[$bucket])) {
                $buckets[$bucket] = ['weight' => 0.0, 'r' => 0.0, 'g' => 0.0, 'b' => 0.0];
            }

            // Weight by saturation so a small vivid mark beats a large washed-out
            // area -- that vivid mark is what a person would call "the colour".
            $weight = $s * (1 - abs($l - 0.5));
            $buckets[$bucket]['weight'] += $weight;
            $buckets[$bucket]['r']      += $r * $weight;
            $buckets[$bucket]['g']      += $g * $weight;
            $buckets[$bucket]['b']      += $b * $weight;
        }
    }

    imagedestroy($image);

    if ($buckets === []) {
        return null;
    }

    uasort($buckets, static fn($a, $b) => $b['weight'] <=> $a['weight']);
    $winner = reset($buckets);

    if ($winner['weight'] <= 0) {
        return null;
    }

    return rgb_to_hex(
        (int)round($winner['r'] / $winner['weight']),
        (int)round($winner['g'] / $winner['weight']),
        (int)round($winner['b'] / $winner['weight'])
    );
}

/**
 * Build the full nine-step palette from one brand colour.
 *
 * Contrast guard: the navbar and primary buttons put WHITE text on the 900/800/700
 * steps. A pale logo (yellow, cyan, lime) would otherwise produce an unreadable
 * header, so those steps are darkened until they clear WCAG AA (4.5:1) against
 * white. The company's colour identity survives; only its lightness is corrected.
 *
 * @return array{primary:string, ramp:array<int,string>, navbar_bg:string}
 */
function brand_ramp(string $primaryHex): array
{
    [$r, $g, $b] = hex_to_rgb($primaryHex) ?? hex_to_rgb(BRAND_DEFAULT_PRIMARY);
    [$h, $s, $l] = rgb_to_hsl($r, $g, $b);

    // Very washed-out logos give a grey ramp; nudge them to something with identity.
    $baseSaturation = max(0.16, min(0.78, $s));

    $ramp = [];
    foreach (BRAND_RAMP_LIGHTNESS as $step => $lightness) {
        $stepSaturation = $baseSaturation * (BRAND_RAMP_SATURATION_SCALE[$step] ?? 1.0);
        $ramp[$step]    = hsl_to_hex($h, $stepSaturation, $lightness);
    }

    // Enforce readable white text on the dark end.
    foreach ([900, 800, 700] as $step) {
        $ramp[$step] = darken_until_readable($ramp[$step], 4.5);
    }

    $navbarStart = darken_until_readable(hsl_to_hex($h, $baseSaturation, 0.11), 7.0);
    $navbarEnd   = darken_until_readable(hsl_to_hex($h, $baseSaturation, 0.075), 7.0);

    return [
        'primary'   => strtolower($primaryHex),
        'ramp'      => $ramp,
        'navbar_bg' => "linear-gradient(135deg, {$navbarStart} 0%, {$navbarEnd} 100%)",
    ];
}

/**
 * The palette used when a tenant has no logo, or the logo yielded no colour.
 * Identical to the values currently hard-coded across the app, so an
 * un-themed page looks exactly as it does today.
 */
function brand_default(): array
{
    return [
        'primary' => BRAND_DEFAULT_PRIMARY,
        'ramp'    => [
            900 => '#3e2b1f', 800 => '#5c3e2d', 700 => '#7b583f',
            600 => '#9b7a5a', 500 => '#b89b7e', 400 => '#d4bfa8',
            300 => '#e8d9cc', 200 => '#f0e8df', 100 => '#f7f2ec',
        ],
        'navbar_bg' => 'linear-gradient(135deg, #2a1f18 0%, #1e1610 100%)',
    ];
}

/**
 * Convenience: logo file in, ready-to-store palette out.
 * Falls back to SHEHITA's palette rather than failing the signup.
 */
function brand_from_logo(?string $imagePath): array
{
    if ($imagePath === null || !is_file($imagePath) || !function_exists('imagecreatetruecolor')) {
        return brand_default();
    }

    $primary = extract_brand_primary($imagePath);

    return $primary === null ? brand_default() : brand_ramp($primary);
}

// ---------------------------------------------------------------------------
// Colour maths
// ---------------------------------------------------------------------------

/**
 * @return array{0:float,1:float,2:float} Hue 0-360, saturation 0-1, lightness 0-1.
 */
function rgb_to_hsl(int $r, int $g, int $b): array
{
    $r /= 255;
    $g /= 255;
    $b /= 255;

    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $l   = ($max + $min) / 2;

    if ($max === $min) {
        return [0.0, 0.0, $l];
    }

    $d = $max - $min;
    $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

    if ($max === $r) {
        $h = (($g - $b) / $d) + ($g < $b ? 6 : 0);
    } elseif ($max === $g) {
        $h = (($b - $r) / $d) + 2;
    } else {
        $h = (($r - $g) / $d) + 4;
    }

    return [$h * 60, $s, $l];
}

function hsl_to_rgb(float $h, float $s, float $l): array
{
    $h = fmod($h, 360);
    if ($h < 0) {
        $h += 360;
    }
    $h /= 360;

    if ($s == 0.0) {
        $v = (int)round($l * 255);
        return [$v, $v, $v];
    }

    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - ($l * $s);
    $p = 2 * $l - $q;

    return [
        (int)round(hue_to_rgb_channel($p, $q, $h + 1 / 3) * 255),
        (int)round(hue_to_rgb_channel($p, $q, $h) * 255),
        (int)round(hue_to_rgb_channel($p, $q, $h - 1 / 3) * 255),
    ];
}

function hue_to_rgb_channel(float $p, float $q, float $t): float
{
    if ($t < 0) {
        $t += 1;
    }
    if ($t > 1) {
        $t -= 1;
    }
    if ($t < 1 / 6) {
        return $p + ($q - $p) * 6 * $t;
    }
    if ($t < 1 / 2) {
        return $q;
    }
    if ($t < 2 / 3) {
        return $p + ($q - $p) * (2 / 3 - $t) * 6;
    }

    return $p;
}

function hsl_to_hex(float $h, float $s, float $l): string
{
    [$r, $g, $b] = hsl_to_rgb($h, $s, $l);
    return rgb_to_hex($r, $g, $b);
}

function rgb_to_hex(int $r, int $g, int $b): string
{
    return sprintf(
        '#%02x%02x%02x',
        max(0, min(255, $r)),
        max(0, min(255, $g)),
        max(0, min(255, $b))
    );
}

/**
 * @return array{0:int,1:int,2:int}|null
 */
function hex_to_rgb(string $hex): ?array
{
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
        return null;
    }

    return [
        hexdec(substr($hex, 0, 2)),
        hexdec(substr($hex, 2, 2)),
        hexdec(substr($hex, 4, 2)),
    ];
}

/** WCAG relative luminance. */
function relative_luminance(int $r, int $g, int $b): float
{
    $channel = static function (int $v): float {
        $v /= 255;
        return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
}

/** WCAG contrast ratio of a colour against white. */
function contrast_with_white(string $hex): float
{
    $rgb = hex_to_rgb($hex);
    if ($rgb === null) {
        return 1.0;
    }

    $lum = relative_luminance($rgb[0], $rgb[1], $rgb[2]);

    return 1.05 / ($lum + 0.05);
}

/**
 * Step a colour darker until white text on it meets the given contrast ratio.
 * Hue and saturation are preserved, so the brand still reads as itself.
 */
function darken_until_readable(string $hex, float $minRatio): string
{
    $rgb = hex_to_rgb($hex);
    if ($rgb === null) {
        return $hex;
    }

    [$h, $s, $l] = rgb_to_hsl($rgb[0], $rgb[1], $rgb[2]);

    for ($i = 0; $i < 40 && contrast_with_white($hex) < $minRatio; $i++) {
        $l  = max(0.0, $l - 0.02);
        $hex = hsl_to_hex($h, $s, $l);
        if ($l <= 0.0) {
            break;
        }
    }

    return $hex;
}

// ---------------------------------------------------------------------------
// Image loading
// ---------------------------------------------------------------------------

/**
 * Load JPEG / PNG / GIF / WebP by sniffing the actual content, never the
 * file extension (uploads are saved with a .jpg name regardless of type).
 */
function load_image_any(string $path)
{
    if (!is_file($path) || !function_exists('getimagesize')) {
        return null;
    }

    $info = @getimagesize($path);
    if ($info === false) {
        return null;
    }

    $image = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
        IMAGETYPE_PNG  => @imagecreatefrompng($path),
        IMAGETYPE_GIF  => @imagecreatefromgif($path),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
        default        => false,
    };

    return $image === false ? null : $image;
}
