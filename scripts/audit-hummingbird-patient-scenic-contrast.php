#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Audit every Android Hummingbird Patient scenic JPEG under the exact default
 * image and vertical-scrim composition used by PatientScenicBackground.
 *
 * The patient screen renders clinical content over the theme surface, never
 * directly over photography. This script conservatively scans every source
 * pixel: a centered runtime crop can remove pixels but cannot introduce a
 * lower contrast than the full-frame minimum reported here. High-contrast and
 * large-text policies are intentionally excluded because they use a more
 * opaque veil (or no image) and therefore cannot be less legible. The audit
 * fails closed if the Android source no longer contains the constants modeled
 * below, preventing a stale calculation from being reported as renderer proof.
 */
if (! function_exists('imagecreatefromjpeg')) {
    fwrite(STDERR, "The GD PHP extension is required for this contrast audit.\n");
    exit(2);
}

const IMAGE_ALPHA = 0.46;
const WCAG_AA_NORMAL_TEXT_MINIMUM = 4.5;

/** @var array<string, string> $assetPaths */
$assetPaths = [
    'airy_flight' => 'hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_airy_flight.jpg',
    'calm_green' => 'hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_calm_green.jpg',
    'care_connection' => 'hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_care_connection.jpg',
    'warm_motion' => 'hummingbird/androidPatientApp/app/src/main/res/drawable-nodpi/patient_hummingbird_warm_motion.jpg',
];

/** @var array<string, array{surface: list<int>, foregrounds: array<string, list<int>>}> $themes */
$themes = [
    'light' => [
        'surface' => [247, 250, 252],
        'foregrounds' => [
            'onSurface' => [23, 33, 38],
            'onSurfaceVariant' => [63, 72, 75],
        ],
    ],
    'dark' => [
        'surface' => [15, 20, 23],
        'foregrounds' => [
            'onSurface' => [222, 227, 230],
            'onSurfaceVariant' => [191, 200, 203],
        ],
    ],
];

$repositoryRoot = realpath(__DIR__.'/..');
if ($repositoryRoot === false) {
    fwrite(STDERR, "Unable to resolve the repository root.\n");
    exit(2);
}

/**
 * @param  list<int>  $under
 * @param  list<int>  $over
 * @return list<int>
 */
function composite(array $under, array $over, float $overAlpha): array
{
    return [
        (int) round($under[0] * (1 - $overAlpha) + $over[0] * $overAlpha),
        (int) round($under[1] * (1 - $overAlpha) + $over[1] * $overAlpha),
        (int) round($under[2] * (1 - $overAlpha) + $over[2] * $overAlpha),
    ];
}

function contrastRatioForLuminances(float $firstLuminance, float $secondLuminance): float
{
    return (max($firstLuminance, $secondLuminance) + 0.05)
        / (min($firstLuminance, $secondLuminance) + 0.05);
}

function scrimAlphaAt(float $verticalPosition): float
{
    if ($verticalPosition <= 0.5) {
        return 0.68 + ((0.84 - 0.68) * ($verticalPosition / 0.5));
    }

    return 0.84 + ((0.96 - 0.84) * (($verticalPosition - 0.5) / 0.5));
}

function verifyAuditedAndroidRenderingConstants(string $repositoryRoot): void
{
    $path = $repositoryRoot
        .'/hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/PatientScenicBackground.kt';
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException("Unable to read Android scenic renderer: {$path}");
    }

    foreach ([
        'imageAlpha = 0.46f',
        'scrimAlphas = listOf(0.68f, 0.84f, 0.96f)',
    ] as $literal) {
        if (! str_contains($source, $literal)) {
            throw new RuntimeException(
                "Android scenic renderer no longer contains audited default constant: {$literal}",
            );
        }
    }

    $themePath = $repositoryRoot
        .'/hummingbird/androidPatientApp/app/src/main/java/net/acumenus/hummingbird/patient/ui/HummingbirdPatientTheme.kt';
    $themeSource = file_get_contents($themePath);
    if ($themeSource === false) {
        throw new RuntimeException("Unable to read Android patient theme: {$themePath}");
    }

    foreach ([
        'surface = Color(0xFFF7FAFC)',
        'onSurface = Color(0xFF172126)',
        'onSurfaceVariant = Color(0xFF3F484B)',
        'surface = Color(0xFF0F1417)',
        'onSurface = Color(0xFFDEE3E6)',
        'onSurfaceVariant = Color(0xFFBFC8CB)',
    ] as $literal) {
        if (! str_contains($themeSource, $literal)) {
            throw new RuntimeException(
                "Android patient theme no longer contains audited constant: {$literal}",
            );
        }
    }
}

/**
 * @param  array<string, array{surface: list<int>, foregrounds: array<string, list<int>>}>  $themes
 * @param  array<string, string>  $assetPaths
 * @return array<string, array<string, array{ratio: float, asset: string, x: int, y: int}>>
 */
function auditScenicContrast(string $repositoryRoot, array $themes, array $assetPaths): array
{
    $linearChannels = [];
    for ($channel = 0; $channel <= 255; $channel++) {
        $normalized = $channel / 255;
        $linearChannels[$channel] = $normalized <= 0.04045
            ? $normalized / 12.92
            : (($normalized + 0.055) / 1.055) ** 2.4;
    }

    $luminanceFor = static function (array $color) use ($linearChannels): float {
        return (0.2126 * $linearChannels[$color[0]])
            + (0.7152 * $linearChannels[$color[1]])
            + (0.0722 * $linearChannels[$color[2]]);
    };

    /** @var array<string, array{minimum: array{luminance: float, asset: string, x: int, y: int}, maximum: array{luminance: float, asset: string, x: int, y: int}}> $extrema */
    $extrema = [];
    foreach ($themes as $themeName => $_theme) {
        $extrema[$themeName] = [
            'minimum' => [
                'luminance' => INF,
                'asset' => '',
                'x' => 0,
                'y' => 0,
            ],
            'maximum' => [
                'luminance' => -INF,
                'asset' => '',
                'x' => 0,
                'y' => 0,
            ],
        ];
    }

    foreach ($assetPaths as $assetName => $relativePath) {
        $path = $repositoryRoot.'/'.$relativePath;
        if (! is_file($path)) {
            throw new RuntimeException("Missing patient scenic asset: {$relativePath}");
        }

        $image = imagecreatefromjpeg($path);
        if ($image === false) {
            throw new RuntimeException("Unable to decode patient scenic asset: {$relativePath}");
        }

        $width = imagesx($image);
        $height = imagesy($image);
        for ($y = 0; $y < $height; $y++) {
            $verticalPosition = $height > 1 ? $y / ($height - 1) : 0.0;
            $scrimAlpha = scrimAlphaAt($verticalPosition);
            for ($x = 0; $x < $width; $x++) {
                $pixel = imagecolorat($image, $x, $y);
                $photo = [
                    ($pixel >> 16) & 0xFF,
                    ($pixel >> 8) & 0xFF,
                    $pixel & 0xFF,
                ];

                foreach ($themes as $themeName => $theme) {
                    $photoOverSurface = composite($theme['surface'], $photo, IMAGE_ALPHA);
                    $compositedSurface = composite($photoOverSurface, $theme['surface'], $scrimAlpha);
                    $luminance = $luminanceFor($compositedSurface);
                    if ($luminance < $extrema[$themeName]['minimum']['luminance']) {
                        $extrema[$themeName]['minimum'] = [
                            'luminance' => $luminance,
                            'asset' => $assetName,
                            'x' => $x,
                            'y' => $y,
                        ];
                    }
                    if ($luminance > $extrema[$themeName]['maximum']['luminance']) {
                        $extrema[$themeName]['maximum'] = [
                            'luminance' => $luminance,
                            'asset' => $assetName,
                            'x' => $x,
                            'y' => $y,
                        ];
                    }
                }
            }
        }
    }

    $minimums = [];
    foreach ($themes as $themeName => $theme) {
        foreach ($theme['foregrounds'] as $foregroundName => $foreground) {
            $foregroundLuminance = $luminanceFor($foreground);
            $minimum = $extrema[$themeName]['minimum'];
            $maximum = $extrema[$themeName]['maximum'];

            if (
                $foregroundLuminance >= $minimum['luminance']
                && $foregroundLuminance <= $maximum['luminance']
            ) {
                throw new RuntimeException(
                    "The {$themeName} {$foregroundName} luminance intersects the scenic range; "
                    .'the audit must be expanded to scan per-pixel ratios.',
                );
            }

            $minimumRatio = contrastRatioForLuminances($foregroundLuminance, $minimum['luminance']);
            $maximumRatio = contrastRatioForLuminances($foregroundLuminance, $maximum['luminance']);
            $minimums[$themeName][$foregroundName] = $minimumRatio <= $maximumRatio
                ? [
                    'ratio' => $minimumRatio,
                    'asset' => $minimum['asset'],
                    'x' => $minimum['x'],
                    'y' => $minimum['y'],
                ]
                : [
                    'ratio' => $maximumRatio,
                    'asset' => $maximum['asset'],
                    'x' => $maximum['x'],
                    'y' => $maximum['y'],
                ];
        }
    }

    return $minimums;
}

try {
    verifyAuditedAndroidRenderingConstants($repositoryRoot);
    $minimums = auditScenicContrast($repositoryRoot, $themes, $assetPaths);
} catch (RuntimeException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(2);
}

$hasFailure = false;
foreach ($minimums as $themeName => $themeMinimums) {
    foreach ($themeMinimums as $foregroundName => $minimum) {
        $passes = $minimum['ratio'] >= WCAG_AA_NORMAL_TEXT_MINIMUM;
        $hasFailure = $hasFailure || ! $passes;
        printf(
            "%s %s: %.3f:1 %s (minimum at %s %d,%d)\n",
            $themeName,
            $foregroundName,
            $minimum['ratio'],
            $passes ? 'PASS' : 'FAIL',
            $minimum['asset'],
            $minimum['x'],
            $minimum['y'],
        );
    }
}

if ($hasFailure) {
    fwrite(STDERR, "Hummingbird patient scenic contrast audit failed the WCAG AA normal-text minimum.\n");
    exit(1);
}

echo "Hummingbird patient scenic contrast audit passed.\n";
